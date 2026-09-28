<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Character;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A skill is now on the sheet.
 *
 * The moment a skill becomes trainable at all, so anything that reacts to a
 * character gaining access to something hangs here rather than off the first
 * deposit.
 *
 * `imprint` is the copy that was spent, and it is null when there was none: an
 * archetype's starting skills are granted at creation rather than bought, and
 * a listener that cares which it was can ask. `points` is where the skill
 * starts — 0 for an injection, and whatever the granted level costs for a
 * loadout, which is the other thing the two origins do not share.
 */
class SkillInjected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Character $character,
        public readonly Skill $skill,
        public readonly ?Item $book = null,
        public readonly int $points = 0,
    ) {}
}
