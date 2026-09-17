<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Run;

/**
 * What came of trying to start or end a run.
 *
 * The same shape ProbeResult takes: a refusal is a reason, and anything else
 * carries the row it produced.
 */
final readonly class RunResult
{
    private function __construct(
        public ?Run $run,
        public ?RunRefusal $refusal,
    ) {}

    public static function started(Run $run): self
    {
        return new self($run, null);
    }

    public static function ended(Run $run): self
    {
        return new self($run, null);
    }

    public static function refused(RunRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
