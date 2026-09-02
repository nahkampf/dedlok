<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Character;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A skillbook was spent and the skill is now on the sheet at 0 points.
 *
 * The moment a skill becomes trainable at all, so anything that reacts to a
 * character gaining access to something hangs here rather than off the first
 * deposit.
 */
class SkillInjected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Character $character,
        public readonly Skill $skill,
        public readonly Item $book,
    ) {}
}
