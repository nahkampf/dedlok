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
 * Four of the seven are levelled I-III, and what rises is different for each:
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

    /** Damage to CURRENT Synaptic Integrity, beyond the defeat itself. */
    case Drain = 'drain';

    /** Destroys fitted hardware, at random — ICE does not care which part is closest to its limit. */
    case Surge = 'surge';

    /** Deletes software or data held on the deck. */
    case Erase = 'erase';

    /** Takes carried creds. Never what is in a node: a defeat reaches what you carried. */
    case Skim = 'skim';

    /** Puts a bounty on the character. The mechanic comes later; the tag exists now. */
    case Bounty = 'bounty';

    /**
     * Strips skill points from a random skill. PAUSED, NOT BUILT.
     *
     * The only tag whose consequences do not stop at the character sheet:
     * losing points can lose a level, losing a level can invalidate a skill
     * that gated a module or a program, and the player has to be TOLD all of it
     * rather than discovering it later as a deck that stopped working. That is
     * a cascade with a notification design attached. **Nothing should carry it
     * until that exists.**
     */
    case Lobotomy = 'lobotomy';

    /** Does this tag come in strengths, or is it one thing that happens? */
    public function isLevelled(): bool
    {
        return match ($this) {
            self::Flatline, self::Drain, self::Skim, self::Erase => true,
            self::Surge => true,
            self::Bounty, self::Lobotomy => false,
        };
    }

    /**
     * May this be put on a piece of ICE today?
     *
     * False is not a gap — it is a consequence the game cannot deliver yet, and
     * a row pointing at one would be a promise nothing keeps. Same decision
     * `ScanFact::isDiscoverable()` takes about the facts nobody can read.
     */
    public function isAssignable(): bool
    {
        return $this !== self::Lobotomy;
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

    /** @return list<self> */
    public static function assignable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $tag): bool => $tag->isAssignable()));
    }
}
