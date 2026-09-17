<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Run;

/**
 * What one turn came to.
 *
 * The LOG LINES are the point. A run is something a player watches happen, so
 * every turn has to be able to say what it did in the order it did it — and
 * that account is built here rather than reconstructed by a screen from the
 * state afterwards, because the state only remembers the last thing.
 */
final readonly class TurnResult
{
    /**
     * @param  list<array{text: string, tone: string}>  $log
     */
    private function __construct(
        public ?Run $run,
        public array $log,
        public ?RunEnding $ending,
        public ?RunRefusal $refusal,
    ) {}

    /** @param  list<array{text: string, tone: string}>  $log */
    public static function resolved(Run $run, array $log, ?RunEnding $ending = null): self
    {
        return new self($run, $log, $ending, null);
    }

    public static function refused(RunRefusal $refusal): self
    {
        return new self(null, [], null, $refusal);
    }

    public function succeeded(): bool
    {
        return $this->refusal === null;
    }

    /** Did this turn finish the run, one way or another? */
    public function ended(): bool
    {
        return $this->ending !== null;
    }
}
