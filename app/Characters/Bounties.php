<?php

declare(strict_types=1);

namespace App\Characters;

use App\Models\Character;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * Somebody decides you are worth money.
 *
 * THE FIRST THING HEAT DOES. It has been a number that only went up since the
 * trace was built — one point a completed trace, two a burn — and a number
 * that only goes up and changes nothing is not a mechanic. This is the
 * threshold it crosses: every night, once, each point of heat makes it likelier
 * that word goes out on the street about you.
 *
 * ## The curve, and why it is allowed to reach certainty
 *
 * Under `heat.bounty.floor_below` it is the uncertainty floor and nothing more
 * — you have done something, so it is never quite impossible. From there it
 * climbs in a straight line to certain at `certain_at`. A straight line because
 * nothing has been balanced against this yet and a curve would be a shape
 * somebody has to defend; when heat is finished, this is one method to change.
 *
 * IT IS NOT A CHECK, which is why it does not go through `CheckRoller` and why
 * reaching 100% does not break the rule that no check is ever certain. A check
 * is somebody attempting something against a difficulty, and the floor exists
 * so skill never becomes a guarantee. This is the world deciding about you:
 * there is no attempt, nothing to be good at, and at a hundred points of heat
 * the answer is supposed to be yes. It is the percentile shape the market's
 * rarity roll and the backdoor ageing roll already use — injected die, strictly
 * inside the bound, so a chance of nothing never fires and a chance of
 * everything always does.
 *
 * ## Zero heat is not rolled at all
 *
 * A floor is what an attempt keeps, not something the world owes a person who
 * has done nothing. A character who has never been traced attracts nobody, and
 * the first point of heat is what puts them on the list.
 *
 * ## What a hit is
 *
 * Creds added to `characters.bounty`: heat × `amount.per_heat`, scaled up by
 * the highest grade that cost the character heat since the last hit
 * (`bounty_grade`, reset to 0 here). Nothing collects the sum yet — that is
 * for the player-versus-player mechanic to come.
 *
 * ## Word that is already out is not news
 *
 * `characters.bounty_word_at` remembers the last time, and nobody is rolled
 * again until `cooldown_days` have passed. A memory rather than a gentler
 * curve: at a hundred heat the answer is still certain, it just stops being a
 * new sentence every morning. The skipped nights are not rolled at all, so
 * they are not made up either.
 */
final class Bounties
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private readonly Notifier $notifier,
        private readonly float $floor,
        private readonly int $floorBelow,
        private readonly int $certainAt,
        private readonly Closure $roll,
        private readonly int $cooldownDays = 0,
        private readonly float $perHeat = 50.0,
        private readonly float $perGrade = 0.25,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            new Notifier,
            // THE SAME FLOOR THE ROLLS USE. Not a check, but the design asked
            // for "the uncertainty floor" in as many words, and two numbers
            // meaning the same thing is one of them being wrong later.
            (float) Config::get('checks.certainty_floor', 0.0085),
            (int) Config::get('cyberspace.heat.bounty.floor_below', 5),
            (int) Config::get('cyberspace.heat.bounty.certain_at', 100),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
            (int) Config::get('cyberspace.heat.bounty.cooldown_days', 7),
            (float) Config::get('cyberspace.heat.bounty.amount.per_heat', 50),
            (float) Config::get('cyberspace.heat.bounty.amount.per_grade', 0.25),
        );
    }

    /** Is word still out from last time? Then there is nothing to roll. */
    public function resting(Character $character): bool
    {
        return $character->bounty_word_at !== null
            && $character->bounty_word_at->addDays($this->cooldownDays)->isFuture();
    }

    /**
     * The odds that word goes out tonight, for a character carrying this much.
     *
     * Public and separate from the roll, like every other chance in the game:
     * a screen that warns somebody and the roll that judges them must not be
     * able to disagree. Nothing quotes it yet.
     */
    public function chanceFor(float $heat): float
    {
        if ($heat <= 0.0) {
            return 0.0;
        }

        if ($heat < $this->floorBelow) {
            return $this->floor;
        }

        if ($heat >= $this->certainAt) {
            return 1.0;
        }

        $span = max(1, $this->certainAt - $this->floorBelow);

        return $this->floor + (($heat - $this->floorBelow) / $span) * (1 - $this->floor);
    }

    /** Creds a hit adds: heat, scaled up for the highest grade that earned it. */
    public function amountFor(float $heat, int $grade): int
    {
        return (int) round($heat * $this->perHeat * (1 + $this->perGrade * (max(1, $grade) - 1)));
    }

    /**
     * Roll every character who has anything to answer for.
     *
     * @return list<Character> the ones word went out about
     */
    public function tonight(): array
    {
        $marked = [];

        foreach (Character::query()->where('heat', '>', 0)->orderBy('id')->cursor() as $character) {
            if ($this->resting($character) || ! $this->decides((float) $character->heat)) {
                continue;
            }

            $character->forceFill([
                'bounty_word_at' => now(),
                'bounty' => (int) $character->bounty + $this->amountFor((float) $character->heat, (int) $character->bounty_grade),
                'bounty_grade' => 0,
            ])->save();
            $this->notifier->send($character, Category::City, 'bounty_word', ['bounty' => number_format($character->bounty)]);

            $marked[] = $character;
        }

        return $marked;
    }

    /**
     * Ten thousandths, and inside the bound rather than at it — so a chance of
     * nothing never fires and a chance of everything always does.
     */
    private function decides(float $heat): bool
    {
        return ($this->roll)(1, 10_000) <= (int) round($this->chanceFor($heat) * 10_000);
    }
}
