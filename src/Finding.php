<?php

namespace ParsaAftabi\StrongMigrations;

/**
 * A single dangerous operation found in a migration.
 */
class Finding
{
    public function __construct(
        public readonly string $check,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $suggestion,
    ) {
    }
}
