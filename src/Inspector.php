<?php

namespace ParsaAftabi\StrongMigrations;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;

/**
 * Inspects the columns and commands of a schema blueprint and reports the
 * operations that are dangerous to run against a production database.
 *
 * Each check can be set to "block" (throw), "warn" (print and continue) or
 * "off" through the package configuration.
 */
class Inspector
{
    /**
     * Default mode per check.
     */
    public static function defaultModes(): array
    {
        return [
            'add_column_not_null' => 'block',
            'add_column_volatile_default' => 'block',
            'change_column' => 'block',
            'add_index' => 'block',
            'drop_column' => 'block',
            'rename_column' => 'block',
            'add_foreign_key' => 'warn',
        ];
    }

    /**
     * @param  ColumnDefinition[]  $columns
     * @param  Fluent[]  $commands
     * @param  array{online_index_supported?: bool}  $context  Capabilities of the
     *                                             running framework, probed by the blueprint.
     * @return Finding[]
     */
    public function inspect(array $columns, array $commands, string $driver, array $modes, array $context = []): array
    {
        $findings = [];

        foreach ($columns as $column) {
            $findings = array_merge($findings, $this->inspectColumn($column, $driver, $modes));
        }

        foreach ($commands as $command) {
            $findings = array_merge($findings, $this->inspectCommand($command, $driver, $modes, $context));
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function inspectColumn(ColumnDefinition $column, string $driver, array $modes): array
    {
        $findings = [];

        // Fluent indexes declared on the column itself ($table->string('x')->index())
        // become plain index builds; on PostgreSQL they cannot be concurrent.
        if ($driver === 'pgsql' && ($column->index || $column->unique)) {
            $type = $column->unique ? 'unique' : 'index';
            $this->add($findings, $modes, 'add_index',
                "Adding the {$type} index on \"{$column->name}\" takes an ACCESS EXCLUSIVE lock on PostgreSQL and blocks writes until the build finishes.",
                "Declare the index on the blueprint instead of on the column, so it can be built CONCURRENTLY: \$table->{$type}('{$column->name}')");
        }

        if ($column->change) {
            $this->add($findings, $modes, 'change_column',
                "Changing the column \"{$column->name}\" can rewrite the whole table and hold a lock while it does.",
                'Use the expand/contract pattern instead: add a new column, backfill it in batches, switch the code over, then drop the old column in a later deploy.');
        }

        if ($column->change) {
            return $findings;
        }

        $default = $column->default;

        if ($default instanceof Expression) {
            $this->add($findings, $modes, 'add_column_volatile_default',
                "Adding the column \"{$column->name}\" with a volatile default (DB::raw) forces a full table rewrite to fill in existing rows.",
                "Add the column as nullable first, backfill it in batches, then set NOT NULL in a later migration. The rewrite happens row by row instead of in one locked pass.");
        } elseif ($column->nullable === false && $default === null) {
            $this->add($findings, $modes, 'add_column_not_null',
                "Adding the NOT NULL column \"{$column->name}\" without a default fails on tables that already have rows — and on older PostgreSQL it rewrites the table under lock.",
                "Give the column a static ->default(...) if one is safe, or use expand/contract: add it nullable, backfill in batches, then add the NOT NULL constraint.");
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function inspectCommand(Fluent $command, string $driver, array $modes, array $context = []): array
    {
        $findings = [];

        switch ($command->name) {
            case 'index':
            case 'unique':
                if ($driver === 'pgsql') {
                    $columns = implode(', ', (array) $command->columns);
                    $supported = $context['online_index_supported'] ?? true;

                    if (! empty($command->online) && $supported) {
                        break;
                    }

                    if (! empty($command->online)) {
                        $this->add($findings, $modes, 'add_index',
                            "The index \"{$command->index}\" is marked ->online(), but this Laravel version's PostgreSQL grammar ignores online() — the index will still be built under a full write lock.",
                            'Create it with a raw statement instead: DB::statement(\'CREATE INDEX CONCURRENTLY ...\'), in a migration with $withinTransaction = false.');
                    } elseif ($supported) {
                        $this->add($findings, $modes, 'add_index',
                            "Adding the index \"{$command->index}\" on ({$columns}) takes an ACCESS EXCLUSIVE lock on PostgreSQL and blocks writes until the build finishes.",
                            "Build it CONCURRENTLY so writes keep flowing: \$table->{$command->name}('{$columns}')->online()");
                    } else {
                        $this->add($findings, $modes, 'add_index',
                            "Adding the index \"{$command->index}\" on ({$columns}) takes an ACCESS EXCLUSIVE lock on PostgreSQL and blocks writes until the build finishes — and this Laravel version's schema builder cannot create indexes concurrently.",
                            'Create it with a raw statement instead: DB::statement(\'CREATE INDEX CONCURRENTLY ...\'), in a migration with $withinTransaction = false.');
                    }
                }
                break;

            case 'dropColumn':
                $columns = implode(', ', (array) $command->columns);
                $this->add($findings, $modes, 'drop_column',
                    "Dropping the column(s) {$columns} breaks any running code that still reads or writes them, and the drop cannot be rolled back with the data.",
                    'Use the expand/contract pattern: first deploy code that ignores the column (and stop writing to it), then drop it in a later, separate migration.');
                break;

            case 'renameColumn':
                $this->add($findings, $modes, 'rename_column',
                    "Renaming the column \"{$command->from}\" to \"{$command->to}\" breaks the code that is currently deployed, because it still uses the old name.",
                    'Use the expand/contract pattern: add the new column, backfill it in batches, deploy code that uses both names, then drop the old column.');
                break;

            case 'foreign':
                $columns = implode(', ', (array) $command->columns);
                $this->add($findings, $modes, 'add_foreign_key',
                    "Adding a foreign key on ({$columns}) locks both tables while PostgreSQL validates every existing row.",
                    'Add the constraint NOT VALID first (it only checks new writes), then run VALIDATE CONSTRAINT separately so existing rows are checked without an exclusive lock.');
                break;
        }

        return $findings;
    }

    /**
     * @param  Finding[]  $findings
     */
    private function add(array &$findings, array $modes, string $check, string $message, string $suggestion): void
    {
        $mode = $modes[$check] ?? 'off';

        if ($mode === 'off') {
            return;
        }

        $findings[] = new Finding($check, $mode === 'warn' ? 'warn' : 'block', $message, $suggestion);
    }
}
