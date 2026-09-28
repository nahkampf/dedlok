<?php

declare(strict_types=1);

namespace App\Standing;

use App\Models\Character;
use App\Support\Text;

/**
 * Something an entity DOES for loyalty, rather than something it hands over.
 *
 * A fixed vocabulary and not rows, the call `App\Drugs\Family` makes: what a
 * service does is code, so a new one is a case, a `match` arm in each method
 * and its strings. How MUCH it does is the offer row's `service_amount`, so
 * the price and the size are authored like any other shelf row.
 *
 * A service lands nowhere: nothing enters the stash, which is the whole
 * difference from an item a player could carry, sell or lose.
 */
enum Service: string
{
    /**
     * They make the word about you go quiet. `characters.heat` comes down by
     * the amount, floored at nothing, rounded to its one place — the nightly
     * cooling's arithmetic, bought early. `bounty_word_at` is NOT touched:
     * word already out stays out, and this only changes what the next roll
     * sees.
     */
    case ShedHeat = 'shed_heat';

    public function label(): string
    {
        return Text::get("exchange.services.{$this->value}.name");
    }

    /** What one row of it does, for the card. */
    public function describe(float $amount): string
    {
        return Text::get("exchange.services.{$this->value}.does", ['amount' => rtrim(rtrim(number_format($amount, 1), '0'), '.')]);
    }

    /** Would it do anything for them? Spending loyalty on nothing is refused. */
    public function wantedBy(Character $character): bool
    {
        return match ($this) {
            self::ShedHeat => (float) $character->heat > 0,
        };
    }

    public function perform(Character $character, float $amount): void
    {
        match ($this) {
            self::ShedHeat => $character->forceFill([
                'heat' => max(0.0, round((float) $character->heat - $amount, 1)),
            ])->save(),
        };
    }
}
