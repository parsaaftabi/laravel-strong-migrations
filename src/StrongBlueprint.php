<?php

namespace ParsaAftabi\StrongMigrations;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use ReflectionMethod;

/**
 * A schema blueprint that inspects itself before compiling to SQL. When a
 * dangerous operation is configured to block, compiling throws and the
 * migration never reaches the database; warnings are reported and the
 * migration continues.
 *
 * Creating a brand new table is always safe (it has no rows to lock), so
 * inspection only runs for alterations.
 *
 * Laravel 10/11 build blueprints as (table, callback, prefix) and compile
 * them with toSql($connection, $grammar); Laravel 12 builds them as
 * (connection, table, callback) and compiles with toSql(). The constructor
 * and toSql() below accept both shapes (detected via reflection) so the
 * package works unchanged on all three majors.
 */
class StrongBlueprint extends Blueprint
{
    private ?Connection $strongConnection = null;

    public function __construct(...$args)
    {
        $connection = null;
        $table = null;
        $callback = null;
        $prefix = '';

        foreach ($args as $arg) {
            if ($arg instanceof Connection) {
                $connection = $arg;
            } elseif ($arg instanceof Closure) {
                $callback = $arg;
            } elseif (is_string($arg) && $table === null) {
                $table = $arg;
            } elseif (is_string($arg)) {
                $prefix = $arg;
            }
        }

        $this->strongConnection = $connection;

        if (self::blueprintTakesConnection()) {
            parent::__construct($connection, $table, $callback);
        } else {
            parent::__construct($table, $callback, $prefix);
        }
    }

    public function toSql($connection = null, $grammar = null)
    {
        $conn = $connection ?? $this->strongConnection ?? ($this->connection ?? null);

        if (! $this->creating() && $conn !== null && StrongMigrations::shouldInspect()) {
            $grammarObject = $grammar ?? ($this->grammar ?? null) ?? $conn->getSchemaGrammar();

            $context = ($conn->getDriverName() === 'pgsql')
                ? ['online_index_supported' => $this->grammarSupportsOnlineIndex($grammarObject)]
                : [];

            $findings = (new Inspector)->inspect(
                $this->getColumns(),
                $this->getCommands(),
                $conn->getDriverName() ?? '',
                StrongMigrations::modes(),
                $context
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

        if (self::toSqlTakesArguments()) {
            return parent::toSql($conn, $grammar ?? $conn->getSchemaGrammar());
        }

        return parent::toSql();
    }

    /**
     * Probe whether the running framework's PostgreSQL grammar honours
     * online() on index commands (Laravel 12+). Compiling a scratch index is
     * the honest way to ask: it works regardless of version string quirks.
     */
    private function grammarSupportsOnlineIndex($grammar): bool
    {
        static $cache = [];

        $class = $grammar::class;

        if (! array_key_exists($class, $cache)) {
            try {
                $probe = new Fluent([
                    'name' => 'index',
                    'index' => 'strong_migrations_probe',
                    'columns' => ['id'],
                    'algorithm' => null,
                    'online' => true,
                ]);

                $cache[$class] = str_contains(strtolower($grammar->compileIndex($this, $probe)), 'concurrently');
            } catch (\Throwable) {
                $cache[$class] = false;
            }
        }

        return $cache[$class];
    }

    /**
     * Whether this Laravel version's Blueprint constructor takes the
     * connection as its first argument (Laravel 12+).
     */
    private static function blueprintTakesConnection(): bool
    {
        static $takes = null;

        if ($takes === null) {
            $params = (new ReflectionMethod(Blueprint::class, '__construct'))->getParameters();
            $type = ($params[0] ?? null)?->getType();
            $takes = $type !== null && method_exists($type, 'getName') && $type->getName() === Connection::class;
        }

        return $takes;
    }

    /**
     * Whether this Laravel version's Blueprint::toSql() expects the
     * connection and grammar arguments (Laravel 10/11).
     */
    private static function toSqlTakesArguments(): bool
    {
        static $takes = null;

        if ($takes === null) {
            $takes = (new ReflectionMethod(Blueprint::class, 'toSql'))->getNumberOfParameters() > 0;
        }

        return $takes;
    }
}
