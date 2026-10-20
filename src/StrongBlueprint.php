<?php

namespace ParsaAftabi\StrongMigrations;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * A schema blueprint that inspects itself before compiling to SQL. When a
 * dangerous operation is configured to block, compiling throws and the
 * migration never reaches the database; warnings are reported and the
 * migration continues.
 *
 * Creating a brand new table is always safe (it has no rows to lock), so
 * inspection only runs for alterations.
 */
class StrongBlueprint extends Blueprint
{
    public function __construct(Connection $connection, $table, ?Closure $callback = null)
    {
        parent::__construct($connection, $table, $callback);
    }

    public function toSql()
    {
        if (! $this->creating() && StrongMigrations::shouldInspect()) {
            $findings = (new Inspector)->inspect(
                $this->getColumns(),
                $this->getCommands(),
                $this->connection->getDriverName() ?? '',
                StrongMigrations::modes()
            );

            $blocking = [];

            foreach ($findings as $finding) {
                if ($finding->severity === 'block') {
                    $blocking[] = $finding;
                } else {
                    StrongMigrations::warn($finding);
                }
            }

            if ($blocking !== []) {
                throw new UnsafeMigrationException($blocking);
            }
        }

        return parent::toSql();
    }
}
