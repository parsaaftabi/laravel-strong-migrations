<?php

namespace ParsaAftabi\StrongMigrations\Tests;

use ParsaAftabi\StrongMigrations\BackfillGuard;
use PHPUnit\Framework\TestCase;

class BackfillGuardTest extends TestCase
{
    public function test_it_flags_an_unbatched_update(): void
    {
        $finding = BackfillGuard::inspect('update "users" set "active" = true', 'pgsql', 'warn');

        $this->assertNotNull($finding);
        $this->assertSame('backfill', $finding->check);
        $this->assertSame('warn', $finding->severity);
        $this->assertStringContainsString('batch', strtolower($finding->suggestion));
    }

    public function test_it_flags_an_unbatched_delete(): void
    {
        $finding = BackfillGuard::inspect('delete from "sessions" where "expired" = true', 'pgsql', 'warn');

        $this->assertNotNull($finding);
        $this->assertSame('backfill', $finding->check);
    }

    public function test_it_allows_a_batched_update_with_a_limit_subquery(): void
    {
        $sql = 'update "users" set "active" = true where "id" in (select "id" from "users" where "active" = false limit 1000)';

        $this->assertNull(BackfillGuard::inspect($sql, 'pgsql', 'warn'));
    }

    public function test_it_allows_a_mysql_update_with_a_limit(): void
    {
        $this->assertNull(BackfillGuard::inspect('update `users` set `active` = 1 limit 1000', 'mysql', 'warn'));
    }

    public function test_it_ignores_other_statements(): void
    {
        $this->assertNull(BackfillGuard::inspect('select * from "users"', 'pgsql', 'warn'));
        $this->assertNull(BackfillGuard::inspect('insert into "users" ("name") values (?)', 'pgsql', 'warn'));
    }

    public function test_block_mode_upgrades_the_severity(): void
    {
        $finding = BackfillGuard::inspect('update "users" set "active" = true', 'pgsql', 'block');

        $this->assertSame('block', $finding->severity);
    }
}
