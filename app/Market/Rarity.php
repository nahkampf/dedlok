<?php

declare(strict_types=1);

namespace App\Market;

use App\Support\Text;
use Illuminate\Support\Facades\Config;

/**
 * How hard an item is to find on the market.
 *
 * Not the same axis as `items.tier`, which is easy to mistake for it. Tier is
 * what a thing IS — Standard through Anomalous — and it is what the UI kit
 * colours. Rarity is how often the market has one, and it is the only thing
 * that decides whether a restock pass delivers anything at all.
 *
 * The two are free to disagree: milspec ammunition is common, and a tuned
 * bike part nobody makes any more is not.
 *
 * An enum rather than a lookup table because the set is closed by design —
 * the seeding curve has four steps in it, and a fifth would be a balance
 * decision, not a row someone adds.
 */
enum Rarity: string
{
    case Common = 'common';

    case Uncommon = 'uncommon';

    case Rare = 'rare';

    case UltraRare = 'ultra_rare';

    /**
     * The percentage chance that one restock pass stocks this at all.
     *
     * Config, not a match arm, because it is the number most likely to be
     * tuned. A rarity missing from config restocks never rather than always:
     * of the two ways to be wrong, a shelf that stays empty is the one someone
     * notices before it reaches a player's wallet.
     */
    public function seedChance(): int
    {
        return (int) Config::get("market.seed_chance.{$this->value}", 0);
    }

    /** The word a player sees. `name` is taken — enums define it themselves. */
    public function label(): string
    {
        return Text::get("items.rarities.{$this->value}");
    }
}
