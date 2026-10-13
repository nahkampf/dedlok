<?php

declare(strict_types=1);

namespace App\Items;

use App\Models\Character;
use App\Support\Points;
use App\Support\Text;

/**
 * What using a consumable can do. A fixed vocabulary, like `Drugs\Family`: a new kind is a case, a label, and an arm
 * of each `match` below — never a migration and never a free-text field.
 *
 * Each kind answers two questions so a screen can ask before it spends: `would()` is how much it would do to this
 * character right now, and `apply()` does it. A use that would do nothing is refused by the caller (`Consumer`), so
 * nobody wastes a medkit at full health.
 */
enum UseEffect: string
{
    /** Restores current hit points, never past the maximum as it stands (an injury lowers that maximum). */
    case HealHitpoints = 'heal_hitpoints';

    public function label(): string
    {
        return Text::get("consumables.effects.{$this->value}.label");
    }

    /** "Restores 20 hit points", for a card. */
    public function describe(int|float $amount): string
    {
        return Text::get("consumables.effects.{$this->value}.card", ['amount' => $amount]);
    }

    /** "You recover 12 hit points.", for after it was used. */
    public function done(int|float $amount): string
    {
        return Text::get("consumables.effects.{$this->value}.done", ['amount' => $amount]);
    }

    /** How much `apply()` would change right now. Reads, never writes. */
    public function would(Character $character, int|float $amount): int|float
    {
        return match ($this) {
            self::HealHitpoints => Points::of(min($amount, max(0, $character->maximumFor('current_hitpoints') - $character->current_hitpoints))),
        };
    }

    /** Does it, and returns how much it actually changed. */
    public function apply(Character $character, int|float $amount): int|float
    {
        return match ($this) {
            self::HealHitpoints => $character->recover('current_hitpoints', $amount),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
