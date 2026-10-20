<?php

namespace ParsaAftabi\StrongMigrations\Tests;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use ParsaAftabi\StrongMigrations\StrongBlueprint;
use ParsaAftabi\StrongMigrations\StrongMigrations;
use ParsaAftabi\StrongMigrations\StrongMigrationsServiceProvider;
use ParsaAftabi\StrongMigrations\UnsafeMigrationException;

class ServiceProviderTest extends TestCase
{
    private array $warnings = [];

    protected function getPackageProviders($app)
    {
        return [StrongMigrationsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
        StrongMigrations::onWarning(function ($finding) {
            $this->warnings[] = $finding;
        });
    }

    protected function tearDown(): void
    {
        StrongMigrations::reset();
        StrongMigrations::onWarning(null);

        parent::tearDown();
    }

    public function test_it_installs_the_strong_blueprint_on_resolved_connections(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        $this->assertTrue(Schema::hasTable('users'));

        try {
            Schema::table('users', function ($table) {
                $table->dropColumn('name');
            });
            $this->fail('Expected UnsafeMigrationException');
        } catch (UnsafeMigrationException $e) {
            $this->assertStringContainsString('drop_column', $e->getMessage());
        }
    }

    public function test_creating_a_table_is_never_blocked(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable(false);
            $table->index('name');
        });

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_it_tracks_migration_boundaries_through_events(): void
    {
        $migration = new class extends Migration {};

        $this->assertFalse(StrongMigrations::migrating());

        event(new MigrationStarted($migration, 'up'));
        $this->assertTrue(StrongMigrations::migrating());

        event(new MigrationEnded($migration, 'up'));
        $this->assertFalse(StrongMigrations::migrating());
    }

    public function test_it_warns_on_unbatched_updates_during_a_migration(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        event(new MigrationStarted(new class extends Migration {}, 'up'));
        DB::table('users')->update(['name' => 'parsa']);
        event(new MigrationEnded(new class extends Migration {}, 'up'));

        $this->assertCount(1, $this->warnings);
        $this->assertSame('backfill', $this->warnings[0]->check);
    }

    public function test_it_does_not_warn_on_updates_outside_migrations(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('users')->update(['name' => 'parsa']);

        $this->assertSame([], $this->warnings);
    }

    public function test_a_blocked_backfill_throws(): void
    {
        StrongMigrations::configure(['checks' => ['backfill' => 'block']]);

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        event(new MigrationStarted(new class extends Migration {}, 'up'));

        try {
            DB::table('users')->update(['name' => 'parsa']);
            $this->fail('Expected UnsafeMigrationException');
        } catch (UnsafeMigrationException $e) {
            $this->assertStringContainsString('backfill', $e->getMessage());
        } finally {
            event(new MigrationEnded(new class extends Migration {}, 'up'));
        }
    }

    public function test_it_merges_the_package_configuration(): void
    {
        $this->assertSame('block', config('strong-migrations.checks.add_index'));
        $this->assertSame('warn', config('strong-migrations.checks.add_foreign_key'));
        $this->assertTrue(config('strong-migrations.enabled'));
    }
}
