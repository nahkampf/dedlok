<?php

declare(strict_types=1);

namespace App\Cyberspace;

use Illuminate\Support\Facades\Config;

/**
 * What happens to somebody a piece of ICE beats.
 *
 * REACHING 0 SYNAPTIC INTEGRITY IS NOT ONE OUTCOME — it is whichever outcome
 * the program that got you carries. That is the whole design: a new kind of bad
 * night is a new tag, never a new branch in the simulator, and the worst
 * software in the game is worst because it carries SEVERAL of these rather than
 * because any one number is enormous.
 *
 * Underneath all of them, a defeat puts the character on the waiting screen.
 * That floor is what makes losing cost something even when the tag set is
 * empty — and an empty set is ordinary, which is why `Ice::defeatTags()` treats
 * null and `[]` as one answer.
 *
 * ## Levels
 *
 * Five of the six are levelled, and what rises is different for each:
 * an amount for Flatline and Drain, a share of what you were carrying for Skim,
 * and how much of the deck goes for Erase. The numbers live in
 * `config/cyberspace.defeat_tags` so they can be tuned against each other,
 * which is the same call `freeze` makes about its own three.
 *
 * ## What is built
 *
 * NOTHING READS ANY OF THIS. There is no defeat because there is no fight, so
 * a tag on a piece of ICE is a true fact that nothing acts on — the same state
 * the operating-system skills shipped in. What exists here is the vocabulary
 * and the numbers, so that when the simulator arrives it has something to
 * resolve rather than something to invent.
 */
enum DefeatTag: string
{
    /** Permanent damage to MAXIMUM Synaptic Integrity. Deepens one injury; floors at 1. */
    case Flatline = 'flatline';

    /**
     * Damage to CURRENT Synaptic Integrity — and what will not fit spills into
     * the body.
     *
     * A defeat has already taken Synaptic Integrity to zero, so in practice
     * almost all of a drain overspills: the mind has nothing left to give and
     * the damage comes out of current hit points instead. That is the tag's
     * whole character rather than an edge case. It touches neither MAXIMUM —
     * that is Flatline's job and permanent — and it does not lengthen the wait.
     */
    case Drain = 'drain';

    /** Destroys fitted hardware, at random — ICE does not care which part is closest to its limit. */
    case Surge = 'surge';

    /** Deletes software or data held on the deck. */
    case Erase = 'erase';

    /** Takes carried creds. Never what is in a node: a defeat reaches what you carried. */
    case Skim = 'skim';

    /** Puts a bounty on the character. The mechanic comes later; the tag exists now. */
    case Bounty = 'bounty';

    /** Does this tag come in strengths, or is it one thing that happens? */
    public function isLevelled(): bool
    {
        return match ($this) {
            self::Flatline, self::Drain, self::Skim, self::Erase, self::Surge => true,
            self::Bounty => false,
        };
    }

    /**
     * What a level of this tag is worth, or null where nothing is settled.
     *
     * @return array<string, mixed>|null
     */
    public function levelValue(int $level): ?array
    {
        /** @var array<int, array<string, mixed>> $table */
        $table = Config::get("cyberspace.defeat_tags.{$this->value}", []);

        return $table[$level] ?? null;
    }

    /** How many levels this tag has, or 0 where it has none. */
    public function levels(): int
    {
        return $this->isLevelled()
            ? count((array) Config::get("cyberspace.defeat_tags.{$this->value}", []))
            : 0;
    }
}
