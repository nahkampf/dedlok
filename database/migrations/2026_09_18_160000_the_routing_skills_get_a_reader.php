<?php

declare(strict_types=1);

use App\Models\Skill;
use Illuminate\Database\Migrations\Migration;

/**
 * Routing and Advanced Routing finally grant something.
 *
 * Both skills have existed since the skill catalogue was written, with books
 * on the shelf and prerequisites in place, and **nothing in the game has ever
 * asked either of them a question**. docs/CYBERSPACE.md's table says what they are
 * for — one system in the chain per level each, ten the ceiling — and until
 * `routing_hops` existed there was no id for that sentence to target.
 *
 * FIVE GRANTS EACH, ONE PER LEVEL, which is how `skills.modifiers` says "per
 * level": a grant carries the level it applies FROM, and `SkillEffects`
 * includes every grant at or below the attained level. `add` rather than
 * `percentage_of_base`, because a hop is a whole machine — there is no such
 * thing as 6% of a relay.
 *
 * APPENDED, NEVER REPLACED. Both rows already carry grants against
 * `deck_bandwidth`, `deck_power_draw` and `check.routing`, and those are
 * content somebody tuned. This adds to the list and leaves everything else
 * exactly as it is, which also makes it safe to run against a database where
 * it has already been applied.
 */
return new class extends Migration
{
    /** One hop per level, on both skills, which is where the ceiling of ten comes from. */
    private const GRANTS = ['routing', 'advancedrouting'];

    public function up(): void
    {
        foreach (self::GRANTS as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();

            // A catalogue that has not been authored is not a reason to fail a
            // migration — the same call the Egress Protocols prerequisite made.
            if ($skill === null) {
                continue;
            }

            $modifiers = array_values(array_filter(
                (array) $skill->modifiers,
                static fn (mixed $grant): bool => ! is_array($grant)
                    || ($grant['target']['id'] ?? null) !== 'routing_hops',
            ));

            foreach (range(1, 5) as $level) {
                $modifiers[] = [
                    'op' => 'add',
                    'level' => $level,
                    'amount' => 1,
                    'target' => ['id' => 'routing_hops'],
                ];
            }

            $skill->forceFill(['modifiers' => $modifiers])->save();
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();

            if ($skill === null) {
                continue;
            }

            $skill->forceFill(['modifiers' => array_values(array_filter(
                (array) $skill->modifiers,
                static fn (mixed $grant): bool => ! is_array($grant)
                    || ($grant['target']['id'] ?? null) !== 'routing_hops',
            ))])->save();
        }
    }
};
