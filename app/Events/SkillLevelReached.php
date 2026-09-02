<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A character crossed a skill breakpoint.
 *
 * Emitted once per level, in order, even when several are crossed in a single
 * deposit — a character coming back from a long outage should receive "reached
 * level 2" and "reached level 3", not one event claiming level 3.
 */
class SkillLevelReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Character $character,
        public readonly Skill $skill,
        public readonly int $level,
        public readonly \DateTimeInterface $reachedAt,
    ) {}
}
