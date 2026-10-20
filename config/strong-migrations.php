<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, migrations run exactly as stock Laravel. You can also
    | disable the package per-environment, for example in local development,
    | while keeping it on in CI and production.
    |
    */

    'enabled' => env('STRONG_MIGRATIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Safety assured
    |--------------------------------------------------------------------------
    |
    | The escape hatch, modelled after strong_migrations for Rails. When a
    | migration has been reviewed and is known to be safe, run it with
    | SAFETY_ASSURED=1 php artisan migrate to skip every check for that run.
    | Per-block bypasses are also available through
    | StrongMigrations::withoutChecks(fn () => ...).
    |
    */

    'safety_assured' => env('SAFETY_ASSURED', false),

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    |
    | Each check is one dangerous operation. Modes:
    |   "block" — throw and stop the migration before it touches the database
    |   "warn"  — print a warning with the safe rewrite, then continue
    |   "off"   — do not check this operation at all
    |
    */

    'checks' => [
        // Non-concurrent index builds lock the table on PostgreSQL.
        'add_index' => 'block',

        // Dropping / renaming columns breaks the code that is still deployed.
        'drop_column' => 'block',
        'rename_column' => 'block',

        // Column additions that rewrite the table or fail on existing rows.
        'add_column_not_null' => 'block',
        'add_column_volatile_default' => 'block',

        // Changing a column's type can rewrite the whole table under lock.
        'change_column' => 'block',

        // Foreign keys validate every existing row under lock.
        'add_foreign_key' => 'warn',

        // UPDATE / DELETE statements inside a migration that are not batched.
        'backfill' => 'warn',
    ],

];
