<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Run;
use App\Programs\ProcessRefusal;

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
        public ?ProcessRefusal $because = null,
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

    /** A program on the loadout would not start, and this is why. */
    public static function programRefused(ProcessRefusal $because): self
    {
        return new self(null, RunRefusal::ProgramRefused, $because);
    }

    /** The sentence a player is shown, naming the program's own reason where there is one. */
    public function message(): string
    {
        return $this->because?->message() ?? $this->refusal?->message() ?? '';
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }
}
