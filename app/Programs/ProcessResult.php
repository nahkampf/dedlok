<?php

declare(strict_types=1);

namespace App\Programs;

use App\Models\InventoryItem;

/**
 * What came of asking to start or stop a program.
 *
 * A refusal is a value rather than an exception, like every other writer in
 * this codebase: none of these is an exceptional condition.
 */
final class ProcessResult
{
    private function __construct(
        /** The row that is now running, or was. */
        public readonly ?InventoryItem $process,
        public readonly ?ProcessRefusal $refusal,
        /** How many processes were stopped. More than one for a stop-all. */
        public readonly int $stopped = 0,
    ) {}

    public static function started(InventoryItem $process): self
    {
        return new self($process, null);
    }

    public static function stopped(int $count = 1): self
    {
        return new self(null, null, $count);
    }

    public static function refused(ProcessRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
