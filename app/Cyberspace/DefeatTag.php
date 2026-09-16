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
 * an amount for Flatline and Neural Shock, a share of what you were carrying
 * for Skim,
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
     * Damage to CURRENT HIT POINTS. The mind's defeat, landing on the body.
     *
     * It was called Drain and aimed at Synaptic Integrity, which could never
     * work: a tag fires because SI reached zero, and subtracting from zero is
     * nothing. Aiming it at the body is what the mechanic was always reaching
     * for — the feedback comes back down the interface and stops a heart rather
     * than a thought. **It touches neither maximum**; that is Flatline's job,
     * and permanent.
     */
    case NeuralShock = 'neural_shock';

    /** Destroys fitted hardware, at random — ICE does not care which part is closest to its limit. */
    case Surge = 'surge';

    /** Deletes software or data held on the deck. */
    case Erase = 'erase';

    /** Takes carried creds. Never what is in a node: a defeat reaches what you carried. */
    case Skim = 'skim';

    /**
     * Somebody puts a price on the character.
     *
     * THE ONLY TAG NOBODY ASSIGNS. Its chance is keyed by the MACHINE'S GRADE
     * rather than by a level, because a bounty is not a property of the guard
     * that beat you — it is a consequence of whose address you were standing
     * on. So every piece of ICE carries it, none of them has to be told to, and
     * `chanceAt()` is the lookup rather than `levelValue()`.
     *
     * The mechanic a bounty turns into does not exist. The roll can be made the
     * day it does, and the number is already there.
     */
    case Bounty = 'bounty';

    /** Does this tag come in strengths, or is it one thing that happens? */
    public function isLevelled(): bool
    {
        return match ($this) {
            self::Flatline, self::NeuralShock, self::Skim, self::Erase, self::Surge => true,
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

    /**
     * How likely this is on a machine of that grade, per cent.
     *
     * Bounty only. A tenth at grade 1, two-thirds at grade 9 — so the grade a
     * player chooses to attack is the dial on how much the world notices them
     * losing. Zero for every other tag, which are assigned rather than rolled
     * for by address.
     */
    public function chanceAt(int $grade): int
    {
        if ($this !== self::Bounty) {
            return 0;
        }

        /** @var array<int, int> $table */
        $table = Config::get('cyberspace.defeat_tags.bounty', []);

        return (int) ($table[$grade] ?? 0);
    }

    /**
     * Is this one somebody puts on a piece of ICE, or one the world applies?
     *
     * Everything but Bounty is authored onto a row. Bounty happens to anybody
     * who loses, scaled by where they lost.
     */
    public function isAuthored(): bool
    {
        return $this !== self::Bounty;
    }
}
