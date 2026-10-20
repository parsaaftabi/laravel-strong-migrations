# laravel-strong-migrations

Catch dangerous migrations **before** they lock your production tables.

This is [strong_migrations](https://github.com/ankane/strong_migrations) for Laravel: it inspects every migration as it runs and stops the ones that take dangerous locks — then tells you exactly how to write them safely.

```bash
composer require parsaaftabi/laravel-strong-migrations
```

That's it. No configuration needed — it hooks into Laravel's schema builder automatically.

## What it catches

| Operation | Default | Why it matters |
|---|---|---|
| Index built without `CONCURRENTLY` (PostgreSQL) | block | Blocks writes for the whole build |
| `dropColumn()` | block | Breaks deployed code still using the column |
| `renameColumn()` | block | Breaks deployed code using the old name |
| `NOT NULL` column without a default | block | Fails on existing rows; can rewrite the table |
| Volatile `DB::raw()` default on a new column | block | Forces a full table rewrite on every row |
| Changing a column's type | block | Rewrites the whole table under lock |
| Adding a foreign key | warn | Validates every existing row under lock |
| Unbatched `UPDATE` / `DELETE` inside a migration | warn | Holds row locks for the entire statement |

When a blocked operation is found, the migration throws **before a single statement runs**, and the exception shows the safe rewrite:

```
Unsafe migration blocked by strong-migrations:

[add_index] Adding the index "users_email_index" on (email) takes an ACCESS
EXCLUSIVE lock on PostgreSQL and blocks writes until the build finishes.
Safe alternative: Build it CONCURRENTLY so writes keep flowing:
$table->index('email')->online()
```

## The safe pattern: expand/contract

Almost every fix is the same shape: **add → backfill in batches → switch the code → drop the old thing in a later deploy.** The exception message tells you which one applies.

## Escape hatch

If you have reviewed a migration and know it is safe:

```bash
SAFETY_ASSURED=1 php artisan migrate
```

Or programmatically, for one block of code:

```php
use ParsaAftabi\StrongMigrations\StrongMigrations;

StrongMigrations::withoutChecks(fn () => Schema::table('users', ...));
```

## Configuration

Publish the config file and tune each check to `block`, `warn`, or `off`:

```bash
php artisan vendor:publish --tag=strong-migrations-config
```

```php
// config/strong-migrations.php
'checks' => [
    'add_index' => 'block',
    'drop_column' => 'block',
    'rename_column' => 'block',
    'add_column_not_null' => 'block',
    'add_column_volatile_default' => 'block',
    'change_column' => 'block',
    'add_foreign_key' => 'warn',
    'backfill' => 'warn',
],
```

Creating a brand new table is never blocked — an empty table has nothing to lock.

## Notes

- Designed for PostgreSQL, where these locks actually hurt. Most checks are driver-aware and stay silent on MySQL/SQLite.
- Concurrent index builds: `->online()` only exists in Laravel 12+. On Laravel 10/11 the schema builder cannot create indexes concurrently at all — the package detects this and points you at a raw `DB::statement('CREATE INDEX CONCURRENTLY ...')` migration (with `$withinTransaction = false`) instead of letting an `->online()` call that the grammar would silently ignore slip through.
- Migrations on a non-default connection via `Schema::connection('tenant')` need the resolver installed by hand:
  `Schema::connection('tenant')->blueprintResolver(StrongMigrationsServiceProvider::blueprintResolver());`

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © 2026 Parsa Aftabi
