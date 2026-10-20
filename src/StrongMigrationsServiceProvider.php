<?php

namespace ParsaAftabi\StrongMigrations;

use Closure;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StrongMigrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/strong-migrations.php', 'strong-migrations');
    }

    public function boot(): void
    {
        StrongMigrations::configure((array) config('strong-migrations', []));

        if (! config('strong-migrations.enabled', true)) {
            return;
        }

        $this->installBlueprintResolver();
        $this->guardBackfills();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/strong-migrations.php' => config_path('strong-migrations.php'),
            ], 'strong-migrations-config');
        }
    }

    /**
     * Swap in StrongBlueprint wherever the Schema facade builds a blueprint.
     *
     * Laravel creates a fresh schema builder for every Schema:: call (the
     * "db.schema" binding is not a singleton), so the resolver has to be
     * installed through an extender that runs on every resolution instead of
     * once on a cached builder instance.
     */
    private function installBlueprintResolver(): void
    {
        $resolver = $this->blueprintResolver();

        $this->app->extend('db.schema', function ($builder) use ($resolver) {
            $builder->blueprintResolver($resolver);

            return $builder;
        });
    }

    /**
     * The resolver that turns every blueprint into a StrongBlueprint. It is
     * public so it can also be installed by hand on schema builders created
     * outside the Schema facade (for example Schema::connection('tenant')).
     */
    public static function blueprintResolver(): Closure
    {
        return fn ($connection, $table, $callback = null) => new StrongBlueprint($connection, $table, $callback);
    }

    /**
     * Watch for unbatched UPDATE / DELETE statements while a migration is
     * running and report them through the backfill check.
     */
    private function guardBackfills(): void
    {
        Event::listen(MigrationStarted::class, fn () => StrongMigrations::beginMigration());
        Event::listen(MigrationEnded::class, fn () => StrongMigrations::endMigration());

        DB::listen(function (QueryExecuted $query) {
            if (! StrongMigrations::migrating() || ! StrongMigrations::shouldInspect()) {
                return;
            }

            $finding = BackfillGuard::inspect(
                $query->sql,
                $query->connection->getDriverName() ?? '',
                StrongMigrations::mode('backfill')
            );

            if ($finding === null) {
                return;
            }

            if ($finding->severity === 'block') {
                throw new UnsafeMigrationException([$finding]);
            }

            StrongMigrations::warn($finding);
        });
    }
}
