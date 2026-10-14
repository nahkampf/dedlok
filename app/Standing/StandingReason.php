<?php

declare(strict_types=1);

namespace App\Standing;

use App\Support\Text;
use Illuminate\Support\Facades\Config;

/**
 * Why somebody's standing or loyalty moved.
 *
 * The amounts live in `config/standing.php` and never here: a case is what
 * happened, and what it is worth is a number somebody will retune. A reason
 * with no entry in config is worth nothing, which is the honest answer for a
 * cause nobody has priced yet.
 *
 * THE SENTENCE IS COMPOSED WHEN IT IS READ, unlike a notification's, which is
 * rendered once and stored. A notification outlives its subject — a system
 * relocates, a skill is renamed — and an entity does neither.
 */
enum StandingReason: string
{
    /** Something on their system noticed you. */
    case Noticed = 'noticed';

    /** You took something off one of their systems. */
    case Raided = 'raided';

    /** You burned one of their systems out of existence. */
    case Burned = 'burned';

    /** One of their systems finished tracing you. Identified is worse than seen, short of a burn. */
    case Traced = 'traced';

    /** A door of yours on one of their systems was found. */
    case BackdoorFound = 'backdoor_found';

    /** Work done, and the only thing that pays loyalty. */
    case MissionCompleted = 'mission_completed';

    /** Money spent at their store, which is its own small courtesy. */
    case StoreSpend = 'store_spend';

    /** Something that happened to them in the City, by an author's hand. The amount is the outcome's own. */
    case Encounter = 'encounter';

    /** You put one of their people down in a fight. Worth its amount per grade of the opponent. */
    case Defeated = 'defeated';

    /** An achievement unlocked: every entity thinks a little better of somebody who is getting around. */
    case Achievement = 'achievement';

    /** Time passing. Written by the nightly drift so the sum still checks. */
    case Decay = 'decay';

    /** What this is worth, before clamping. Zero for anything unpriced. */
    public function amount(): float
    {
        return (float) Config::get("standing.reasons.{$this->value}", 0.0);
    }

    public function label(): string
    {
        return Text::get("entities.reasons.{$this->value}");
    }
}
