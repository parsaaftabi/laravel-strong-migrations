<?php

namespace ParsaAftabi\StrongMigrations;

/**
 * Guards against unbatched data changes (UPDATE / DELETE) executed while a
 * migration runs. A single statement that touches every row holds row locks
 * for its whole duration; batched changes keep each lock window short.
 */
class BackfillGuard
{
    public static function inspect(string $sql, string $driver, string $mode): ?Finding
    {
        if ($mode === 'off') {
            return null;
        }

        $normalized = strtolower(ltrim($sql));

        if (! preg_match('/^(update|delete)\b/', $normalized)) {
            return null;
        }

        // A LIMIT anywhere in the statement means the change is batched:
        // either directly (MySQL) or through an id subquery (PostgreSQL).
        if (preg_match('/\blimit\s+\d+/', $normalized)) {
            return null;
        }

        return new Finding(
            'backfill',
            $mode === 'block' ? 'block' : 'warn',
            'This UPDATE/DELETE runs against the whole table in one statement while a migration is running, holding locks on every row it touches until it finishes.',
            'Backfill in batches instead: loop over chunks of rows (for example where "id" in (select "id" ... limit 1000)) and update one batch per statement, so each lock is held only briefly.'
        );
    }
}
