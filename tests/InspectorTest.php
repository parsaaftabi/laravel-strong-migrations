<?php

namespace ParsaAftabi\StrongMigrations\Tests;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;
use ParsaAftabi\StrongMigrations\Inspector;
use PHPUnit\Framework\TestCase;

class InspectorTest extends TestCase
{
    private Inspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new Inspector;
    }

    private function modes(array $overrides = []): array
    {
        return array_merge(Inspector::defaultModes(), $overrides);
    }

    public function test_it_flags_a_non_concurrent_index_on_postgres(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index'])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('add_index', $findings[0]->check);
        $this->assertSame('block', $findings[0]->severity);
        $this->assertStringContainsString('CONCURRENTLY', $findings[0]->suggestion);
    }

    public function test_it_allows_an_online_index_on_postgres(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index', 'online' => true])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes());

        $this->assertSame([], $findings);
    }

    public function test_it_flags_an_online_index_when_the_framework_grammar_ignores_it(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index', 'online' => true])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes(), ['online_index_supported' => false]);

        $this->assertCount(1, $findings);
        $this->assertSame('add_index', $findings[0]->check);
        $this->assertStringContainsString('CREATE INDEX CONCURRENTLY', $findings[0]->suggestion);
    }

    public function test_it_suggests_a_raw_statement_when_online_is_unsupported(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index'])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes(), ['online_index_supported' => false]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('CREATE INDEX CONCURRENTLY', $findings[0]->suggestion);
    }

    public function test_it_ignores_indexes_on_mysql(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index'])];

        $findings = $this->inspector->inspect([], $commands, 'mysql', $this->modes());

        $this->assertSame([], $findings);
    }

    public function test_it_flags_a_unique_index_without_online_on_postgres(): void
    {
        $commands = [new Fluent(['name' => 'unique', 'columns' => ['email'], 'index' => 'users_email_unique'])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('add_index', $findings[0]->check);
    }

    public function test_it_flags_a_fluent_column_index_on_postgres(): void
    {
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'email', 'index' => true])];

        $findings = $this->inspector->inspect($columns, [], 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('add_index', $findings[0]->check);
    }

    public function test_it_ignores_fluent_column_indexes_while_creating(): void
    {
        // Creating a table is handled by the blueprint, not the inspector:
        // this test only pins the inspector behaviour for fluent unique keys.
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'email', 'unique' => true])];

        $findings = $this->inspector->inspect($columns, [], 'mysql', $this->modes());

        $this->assertSame([], $findings);
    }

    public function test_it_flags_dropping_a_column(): void
    {
        $commands = [new Fluent(['name' => 'dropColumn', 'columns' => ['legacy_flag']])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('drop_column', $findings[0]->check);
        $this->assertSame('block', $findings[0]->severity);
        $this->assertStringContainsString('expand', strtolower($findings[0]->suggestion));
    }

    public function test_it_flags_renaming_a_column(): void
    {
        $commands = [new Fluent(['name' => 'renameColumn', 'from' => 'name', 'to' => 'full_name'])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('rename_column', $findings[0]->check);
        $this->assertSame('block', $findings[0]->severity);
    }

    public function test_it_flags_a_not_null_column_without_a_default(): void
    {
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'nickname', 'nullable' => false])];

        $findings = $this->inspector->inspect($columns, [], 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('add_column_not_null', $findings[0]->check);
        $this->assertSame('block', $findings[0]->severity);
        $this->assertStringContainsString('default', strtolower($findings[0]->suggestion));
    }

    public function test_it_allows_a_nullable_column(): void
    {
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'nickname'])];

        $findings = $this->inspector->inspect($columns, [], 'pgsql', $this->modes());

        $this->assertSame([], $findings);
    }

    public function test_it_allows_a_not_null_column_with_a_static_default(): void
    {
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'nickname', 'nullable' => false, 'default' => 'n/a'])];

        $findings = $this->inspector->inspect($columns, [], 'pgsql', $this->modes());

        $this->assertSame([], $findings);
    }

    public function test_it_flags_a_volatile_default_expression(): void
    {
        $columns = [new ColumnDefinition(['type' => 'timestamp', 'name' => 'seen_at', 'nullable' => false, 'default' => new Expression('now()')])];

        $findings = $this->inspector->inspect($columns, [], 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('add_column_volatile_default', $findings[0]->check);
        $this->assertSame('block', $findings[0]->severity);
    }

    public function test_it_warns_on_adding_a_foreign_key(): void
    {
        $commands = [new Fluent(['name' => 'foreign', 'columns' => ['user_id']])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('add_foreign_key', $findings[0]->check);
        $this->assertSame('warn', $findings[0]->severity);
        $this->assertStringContainsString('NOT VALID', $findings[0]->suggestion);
    }

    public function test_it_flags_changing_a_column_type(): void
    {
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'code', 'change' => true])];

        $findings = $this->inspector->inspect($columns, [], 'pgsql', $this->modes());

        $this->assertCount(1, $findings);
        $this->assertSame('change_column', $findings[0]->check);
        $this->assertSame('block', $findings[0]->severity);
    }

    public function test_a_disabled_check_is_skipped(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index'])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes(['add_index' => 'off']));

        $this->assertSame([], $findings);
    }

    public function test_a_warn_mode_check_does_not_block(): void
    {
        $commands = [new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index'])];

        $findings = $this->inspector->inspect([], $commands, 'pgsql', $this->modes(['add_index' => 'warn']));

        $this->assertCount(1, $findings);
        $this->assertSame('warn', $findings[0]->severity);
    }

    public function test_it_reports_multiple_findings_at_once(): void
    {
        $columns = [new ColumnDefinition(['type' => 'string', 'name' => 'nickname', 'nullable' => false])];
        $commands = [
            new Fluent(['name' => 'index', 'columns' => ['email'], 'index' => 'users_email_index']),
            new Fluent(['name' => 'dropColumn', 'columns' => ['legacy_flag']]),
        ];

        $findings = $this->inspector->inspect($columns, $commands, 'pgsql', $this->modes());

        $checks = array_map(fn ($f) => $f->check, $findings);
        $this->assertSame(['add_column_not_null', 'add_index', 'drop_column'], $checks);
    }
}
