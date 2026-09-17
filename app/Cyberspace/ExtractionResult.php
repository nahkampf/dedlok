<?php

declare(strict_types=1);

namespace App\Cyberspace;

/**
 * What one extraction attempt actually brought back.
 *
 * Three counts rather than a running log line, because what the counts mean —
 * creds added, a quantity granted, a file that became a dump — is the
 * simulator's to say in whatever voice the run's log speaks, not this class's.
 */
final readonly class ExtractionResult
{
    public function __construct(
        public int $creds = 0,
        public int $items = 0,
        public int $files = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function tookNothing(): bool
    {
        return $this->creds === 0 && $this->items === 0 && $this->files === 0;
    }
}
