<?php

namespace ParsaAftabi\StrongMigrations;

/**
 * Runtime state and configuration for the package. The service provider
 * fills this from config/strong-migrations.php; tests and application code
 * can also drive it directly.
 */
class StrongMigrations
{
    private static bool $enabled = true;

    private static bool $safetyAssured = false;

    private static array $modes = [];

    private static int $disabledDepth = 0;

    private static bool $migrating = false;

    /** @var null|callable(Finding): void */
    private static $warningHandler = null;

    public static function configure(array $config): void
    {
        self::$enabled = $config['enabled'] ?? true;
        self::$safetyAssured = $config['safety_assured'] ?? false;
        self::$modes = array_merge(self::defaultModes(), $config['checks'] ?? []);
    }

    public static function defaultModes(): array
    {
        return array_merge(Inspector::defaultModes(), ['backfill' => 'warn']);
    }

    public static function modes(): array
    {
        return self::$modes !== [] ? self::$modes : self::defaultModes();
    }

    public static function mode(string $check): string
    {
        return self::modes()[$check] ?? 'off';
    }

    public static function setSafetyAssured(bool $value = true): void
    {
        self::$safetyAssured = $value;
    }

    public static function shouldInspect(): bool
    {
        return self::$enabled && ! self::$safetyAssured && self::$disabledDepth === 0;
    }

    /**
     * Run the callback with all checks disabled (the programmatic escape
     * hatch). Inspection is restored afterwards, even if the callback throws.
     */
    public static function withoutChecks(callable $callback): mixed
    {
        self::$disabledDepth++;

        try {
            return $callback();
        } finally {
            self::$disabledDepth--;
        }
    }

    public static function beginMigration(): void
    {
        self::$migrating = true;
    }

    public static function endMigration(): void
    {
        self::$migrating = false;
    }

    public static function migrating(): bool
    {
        return self::$migrating;
    }

    /**
     * Register the handler that receives warning findings. Pass null to
     * restore the default handler (STDERR on the CLI, error_log otherwise).
     */
    public static function onWarning(?callable $handler): void
    {
        self::$warningHandler = $handler;
    }

    public static function warn(Finding $finding): void
    {
        if (self::$warningHandler !== null) {
            (self::$warningHandler)($finding);

            return;
        }

        $line = "[strong-migrations] WARNING [{$finding->check}] {$finding->message} Safe alternative: {$finding->suggestion}";

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, $line."\n");
        } else {
            error_log($line);
        }
    }

    public static function reset(): void
    {
        self::$enabled = true;
        self::$safetyAssured = false;
        self::$modes = [];
        self::$disabledDepth = 0;
        self::$migrating = false;
        self::$warningHandler = null;
    }
}
