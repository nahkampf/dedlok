<?php

declare(strict_types=1);

namespace App\Cyberspace;

use Illuminate\Support\Facades\Config;

/**
 * What a piece of ICE does WHILE it is fighting you.
 *
 * THE MIRROR OF `DefeatTag`, AND THE DISTINCTION IS THE WHOLE POINT. A defeat
 * tag fires once, at the end, on somebody who has already lost. An attack tag
 * is in force for as long as the thing is awake and alive — so it changes the
 * decisions inside a fight rather than the price of losing one, and the way
 * out from under it is to break the guard.
 *
 * ## Passive, not applied
 *
 * An attack tag is read off the run's ACTIVE ICE every time something needs it.
 * Nothing is written onto the run, nothing has to expire, and nothing has to be
 * cleaned up when the ICE dies — `runs.ice_id` going null is the whole of
 * being free of it. That is deliberately not the alternative shape (a hit
 * applies a lasting condition): a condition that outlives its cause is a
 * column, a duration, a sweep to expire it, and a screen that has to explain
 * why leaving is still hard when nothing is fighting you.
 *
 * ## Levels
 *
 * Levelled like most of the defeat tags, and the numbers live in
 * `config/cyberspace.attack_tags` so they can be tuned against each other
 * rather than against whatever the simulator happens to do with them.
 */
enum AttackTag: string
{
    /**
     * Leaving is harder while this is awake.
     *
     * MULTIPLIES THE EGRESS CHANCE — a quarter off at level I, two-fifths at
     * II, rather more than half at III. A multiplier rather than a subtraction
     * because every other percentage in the game is one: a flat 55 points off
     * would annihilate a chance that was poor to begin with and barely dent a
     * good one, which is backwards. A quarter off a bad chance is still a bad
     * chance, and a quarter off a good one is a decision.
     *
     * IT NEVER CLOSES THE DOOR. The reduced chance is floored again, because
     * "no check is ever certain" cuts both ways and a run with no exit is a
     * character deleted by a door they could not see. Ensnare makes leaving
     * expensive in turns, and turns are trace and heat — it does not make
     * leaving impossible.
     *
     * It applies to EVERYBODY in the run, which is what it means for the tag
     * to belong to the situation rather than to whoever was hit last. Coop
     * runs are phase 7; when they arrive this needs no change.
     */
    case Ensnare = 'ensnare';

    /** Does this tag come in strengths, or is it one thing that happens? */
    public function isLevelled(): bool
    {
        return match ($this) {
            self::Ensnare => true,
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
        $table = Config::get("cyberspace.attack_tags.{$this->value}", []);

        return $table[$level] ?? null;
    }

    /** How many levels this tag has, or 0 where it has none. */
    public function levels(): int
    {
        return $this->isLevelled()
            ? count((array) Config::get("cyberspace.attack_tags.{$this->value}", []))
            : 0;
    }

    /**
     * Ensnare's multiplier at this level, as a factor on a chance.
     *
     * ONE PLACE TURNS A LEVEL INTO A NUMBER, so the screen quoting the odds and
     * the roll that follows cannot disagree about what being snared costs. A
     * level nobody has priced multiplies by 1 — it is a tag that does nothing
     * rather than a tag that throws, the same call `levelValue()` makes.
     */
    public function multiplier(int $level): float
    {
        $reduction = (float) ($this->levelValue($level)['reduction'] ?? 0);

        return max(0.0, 1.0 - ($reduction / 100));
    }
}
