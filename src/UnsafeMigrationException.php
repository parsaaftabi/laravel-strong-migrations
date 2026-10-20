<?php

namespace ParsaAftabi\StrongMigrations;

use RuntimeException;

/**
 * Thrown when a migration contains an operation that is configured to block.
 * The message lists every blocking problem and the safe way to write it.
 */
class UnsafeMigrationException extends RuntimeException
{
    /**
     * @param  Finding[]  $findings
     */
    public function __construct(private readonly array $findings)
    {
        $lines = ['Unsafe migration blocked by strong-migrations:'];

        foreach ($findings as $finding) {
            $lines[] = '';
            $lines[] = "[{$finding->check}] {$finding->message}";
            $lines[] = "Safe alternative: {$finding->suggestion}";
        }

        $lines[] = '';
        $lines[] = 'If you have reviewed this migration and are sure it is safe, run it with SAFETY_ASSURED=1 to skip these checks.';

        parent::__construct(implode("\n", $lines));
    }

    /**
     * @return Finding[]
     */
    public function findings(): array
    {
        return $this->findings;
    }
}
