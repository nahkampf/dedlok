<?php

declare(strict_types=1);

namespace App\Safehouses;

/** What came of a raid, already in words. `won` only says which way to flash it. */
final readonly class Raid
{
    public function __construct(public bool $won, public string $message) {}
}
