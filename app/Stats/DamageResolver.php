<?php

declare(strict_types=1);

namespace App\Stats;

use App\Models\Character;

/**
 * The one place a hit becomes a number and a number becomes a smaller pool.
 *
 * Everything that hurts anybody goes through here, for the reason every write
 * in this codebase has one door: a second path would be a second answer to
 * "how much did that actually cost", and the first thing to disagree with it
 * would be the character sheet.
 *
 * IT OWNS NO ARITHMETIC. `App\Stats\Resolver` does the layers, the clamp and
 * the rounding, exactly as it does for a ceiling or a check — the only thing
 * unusual about damage is that its BASE arrives with the event instead of
 * being read off the character. That is why this class is three lines of
 * substance and a long explanation: the design is that there is nothing here.
 *
 * WHAT A RESISTANCE IS, THEREFORE: a modifier, in data, on a row that already
 * exists. A skill that softens ICE is `percentage_of_base -15` against
 * `all_of: ['damage', 'ice']` on the skill's own `modifiers` column. A drug
 * that makes everything hurt more is `+25` against `all_of: ['damage']`.
 * Immunity is `multiplier` 0. None of it is code, which is the rule MECHANICS
 * states for every modifier in the game and the reason this was worth building
 * before the second source of damage rather than after it.
 *
 * CONDITIONS APPLY, because the context is the character's own. "While under
 * 30% Synaptic Integrity" is judged the same way it is judged everywhere else,
 * against a context built from unconditional modifiers alone — see Sheet.
 *
 * ZERO IS A REAL OUTCOME AND BUYS NOTHING OFF. A hit mitigated to nothing is
 * still a hit that landed: the readiness dump ejects a player whose
 * resistances ate all of it, and a future flatline must still flatline. What
 * damage does is separate from what it costs, and callers read `taken` only to
 * say it out loud.
 */
final class DamageResolver
{
    public function __construct(private readonly Resolver $resolver) {}

    public static function make(): self
    {
        return new self(app(Resolver::class));
    }

    /**
     * Work the hit out and take it.
     *
     * The write is `Character::takeDamage()`, which is the dull half on
     * purpose: subtract and floor, and know nothing about why.
     */
    public function apply(Character $character, Damage $damage): DamageResult
    {
        $breakdown = $this->preview($character, $damage);
        $resolved = $breakdown->toInt();

        return new DamageResult(
            damage: $damage,
            requested: $damage->amount,
            resolved: $resolved,
            taken: $character->takeDamage($damage->resource, $resolved),
            breakdown: $breakdown,
        );
    }

    /**
     * What the hit WOULD be, without taking it.
     *
     * Here because a screen that warns "this would cost you 12" and a hit that
     * costs 12 must be the same calculation, and because a test about
     * mitigation should not have to spend a character's health to read one
     * number.
     */
    public function preview(Character $character, Damage $damage): ResolvedStat
    {
        return $this->resolver->resolve(
            $damage->statId(),
            (float) $damage->amount,
            $character->modifiers(),
            Sheet::contextFor($character),
        );
    }
}
