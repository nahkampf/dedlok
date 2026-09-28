<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Market\Rarity;
use App\Models\Item;
use App\Models\Skill;

/**
 * The one imprint per skill, and where its numbers come from.
 *
 * There is exactly one imprint for every skill — that is what makes an imprint
 * nameless (its name composes from the skill it teaches) and what makes
 * `hasInjected()` a meaningful gate. Keeping that true means the imprint cannot
 * be something someone remembers to create: it has to fall out of the skill
 * existing.
 *
 * This is the derivation, lifted out of ItemSeeder so the seeder and the admin
 * editor cannot drift. Both call it; neither owns it.
 *
 * What follows the skill afterwards, and what does not:
 *
 * - `base_cost` and `on_market` are the skill's own answers to "what does this
 *   cost" and "is it sold", so they are kept in step on every save.
 * - `tier`, `rarity`, `size` and `seed_quantity` are derived from rank ONCE, at
 *   creation, and then belong to the item editor. They are the numbers most
 *   likely to be tuned by hand, and the seeder's rule holds here too: a value
 *   someone has deliberately set is not a value to overwrite.
 */
final class Imprint
{
    public static function slugFor(Skill $skill): string
    {
        return "imprint_{$skill->slug}";
    }

    /** The imprint teaching this skill, if one has been made. */
    public static function forSkill(Skill $skill): ?Item
    {
        return $skill->imprint()->first();
    }

    /**
     * Every column of the imprint a skill implies.
     *
     * @return array<string, mixed>
     */
    public static function attributesFor(Skill $skill): array
    {
        $rank = (int) $skill->rank;

        return [
            'container' => Item::CONTAINER_DATA,
            // Rank is a training-time multiplier, so an imprint for a slower skill
            // is the better article. Ranks are 1,2,4,8,16, and the scale is
            // two steps: the quickest skill's imprint is Tier One, the rest Two.
            'tier' => min(Item::MAX_TIER, (int) log(max(1, $rank), 2) + 1),
            'rarity' => self::rarityForRank($rank),
            'stackable' => true,
            'use_op' => Item::USE_INJECT_SKILL,
            'use_skill_id' => $skill->id,
            // QB equal to the rank: 1 for the quickest skill, 16 for the
            // slowest. A rank 16 imprint will not fit a starter deck beside
            // anything else, so learning the hardest skills takes a better
            // deck or a storage module first.
            'size' => self::sizeForRank($rank),
            'base_cost' => (int) $skill->base_cost,
            'on_market' => (bool) $skill->on_market,
            // Halving per rank: 32 of the commonest imprint on the shelf, 2 of
            // the rank 16 ones.
            'seed_quantity' => max(2, (int) round(32 / max(1, $rank))),
        ];
    }

    public static function sizeForRank(int $rank): int
    {
        return max(1, $rank);
    }

    /**
     * The imprint for this skill, made if it was not there.
     *
     * firstOrCreate by slug rather than by use_skill_id, so an imprint that was
     * seeded before and an imprint made here are the same row.
     */
    public static function ensureFor(Skill $skill): Item
    {
        return Item::firstOrCreate(
            ['slug' => self::slugFor($skill)],
            self::attributesFor($skill),
        );
    }

    /**
     * Put the imprint's cost and availability back in step with the skill.
     *
     * Deliberately narrow — see the class comment for what is left alone and
     * why. Returns the imprint, or null when the skill has none, which is a state
     * the admin editor allows: an imprint can be deleted on purpose.
     */
    public static function syncFor(Skill $skill): ?Item
    {
        $book = self::forSkill($skill);

        if ($book === null) {
            return null;
        }

        $book->fill([
            'base_cost' => (int) $skill->base_cost,
            'on_market' => (bool) $skill->on_market,
        ])->save();

        return $book;
    }

    /**
     * An imprint for a slower skill is a rarer find.
     *
     * Five ranks, four rarities: the two slowest share the top one, because
     * what separates rank 8 from rank 16 is the months of training, not how
     * hard the imprint is to come by.
     */
    private static function rarityForRank(int $rank): Rarity
    {
        return match ($rank) {
            1 => Rarity::Common,
            2 => Rarity::Uncommon,
            4 => Rarity::Rare,
            default => Rarity::UltraRare,
        };
    }
}
