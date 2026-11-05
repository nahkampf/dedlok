<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * Whether a run got what it came for, for the encounters that ask (`encounters.run_outcome`; no filter is both).
 *
 * `Completed` is the only success: extracted, backdoored, burned, infiltrated or flooded. Every other ending is a failure,
 * including an egress with nothing taken.
 */
enum RunOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';

    public static function of(RunEnding $ending): self
    {
        return $ending === RunEnding::Completed ? self::Success : self::Failure;
    }

    public function label(): string
    {
        return Text::get("cyberspace.run_outcome.{$this->value}");
    }
}
