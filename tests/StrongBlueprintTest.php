<?php

namespace ParsaAftabi\StrongMigrations\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use PDO;
use ParsaAftabi\StrongMigrations\StrongBlueprint;
use ParsaAftabi\StrongMigrations\StrongMigrations;
use ParsaAftabi\StrongMigrations\UnsafeMigrationException;
use PHPUnit\Framework\TestCase;

class StrongBlueprintTest extends TestCase
{
    protected function tearDown(): void
    {
        StrongMigrations::reset();

        parent::tearDown();
    }

    private function blueprint(callable $callback): StrongBlueprint
    {
        $connection = new Connection(new PDO('sqlite::memory:'), '', '', ['driver' => 'pgsql']);
        $connection->setSchemaGrammar(new PostgresGrammar($connection));

        return new StrongBlueprint($connection, 'users', $callback);
    }

    public function test_it_throws_on_a_dangerous_migration(): void
    {
        $blueprint = $this->blueprint(fn ($t) => $t->index('email'));

        $this->expectException(UnsafeMigrationException::class);
        $this->expectExceptionMessageMatches('/index/i');

        $blueprint->toSql();
    }

    public function test_the_exception_message_shows_the_safe_rewrite(): void
    {
        $blueprint = $this->blueprint(fn ($t) => $t->index('email'));

        try {
            $blueprint->toSql();
            $this->fail('Expected UnsafeMigrationException');
        } catch (UnsafeMigrationException $e) {
            $this->assertStringContainsString('->online()', $e->getMessage());
        }
    }

    public function test_it_compiles_a_safe_migration_without_throwing(): void
    {
        $blueprint = $this->blueprint(fn ($t) => $t->index('email')->online());

        $statements = $blueprint->toSql();

        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('concurrently', $statements[0]);
    }

    public function test_it_does_not_inspect_when_a_check_is_off(): void
    {
        StrongMigrations::configure(['checks' => ['add_index' => 'off']]);

        $blueprint = $this->blueprint(fn ($t) => $t->index('email'));

        $this->assertNotEmpty($blueprint->toSql());
    }

    public function test_it_sends_warnings_to_the_registered_handler_instead_of_throwing(): void
    {
        StrongMigrations::configure(['checks' => ['add_index' => 'warn']]);
        $warnings = [];
        StrongMigrations::onWarning(function ($finding) use (&$warnings) {
            $warnings[] = $finding;
        });

        $blueprint = $this->blueprint(fn ($t) => $t->index('email'));
        $blueprint->toSql();

        $this->assertCount(1, $warnings);
        $this->assertSame('add_index', $warnings[0]->check);
    }

    public function test_safety_assured_disables_inspection(): void
    {
        StrongMigrations::setSafetyAssured(true);

        $blueprint = $this->blueprint(fn ($t) => $t->index('email'));

        $this->assertNotEmpty($blueprint->toSql());
    }

    public function test_without_checks_disables_inspection_inside_the_callback_only(): void
    {
        $blueprint = $this->blueprint(fn ($t) => $t->index('email'));

        $statements = StrongMigrations::withoutChecks(fn () => $blueprint->toSql());

        $this->assertNotEmpty($statements);
        $this->assertTrue(StrongMigrations::shouldInspect());
    }

    public function test_without_checks_reenables_inspection_after_an_exception(): void
    {
        try {
            StrongMigrations::withoutChecks(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            $this->assertTrue(StrongMigrations::shouldInspect());
        }
    }
}
