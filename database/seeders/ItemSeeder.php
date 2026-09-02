<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Market\Rarity;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * The item catalogue.
     *
     * Idempotent by slug, so re-running never duplicates and never clobbers a
     * price that has been tuned in place — updateOrCreate would, which is why
     * this only fills in what is missing.
     *
     * A skillbook per skill is generated rather than listed: there is exactly
     * one book per skill by definition, so listing them would be a second
     * place to forget when a skill is added. They carry no name of their own — it
     * composes from the skill they teach.
     */
    public function run(): void
    {
        foreach ($this->catalogue() as $slug => $attributes) {
            $item = Item::firstOrCreate(['slug' => $slug], $attributes);

            $this->fillInMarketValues($item, $attributes);
        }

        Skill::query()->each(function (Skill $skill): void {
            $attributes = [
                'container' => Item::CONTAINER_DATA,
                // Rank is a training-time multiplier, so a book for a
                // slower skill is a rarer find. Ranks are 1,2,4,8,16.
                'tier' => min(Item::MAX_TIER, (int) log($skill->rank, 2) + 1),
                'rarity' => $this->rarityForRank($skill->rank),
                'stackable' => true,
                'use_op' => Item::USE_INJECT_SKILL,
                'use_skill_id' => $skill->id,
                'size' => 8,
                'base_cost' => $skill->base_cost,
                'on_market' => $skill->on_market,
                // Halving per rank: 32 of the commonest book on the shelf,
                // 2 of the rank 16 ones.
                'seed_quantity' => max(2, (int) round(32 / $skill->rank)),
            ];

            $book = Item::firstOrCreate(['slug' => "skillbook_{$skill->slug}"], $attributes);

            $this->fillInMarketValues($book, $attributes);
        });
    }

    /**
     * Give a row that predates the market its rarity and shelf size.
     *
     * The rest of this seeder deliberately never touches an existing row, so
     * a price tuned in place survives a re-run. These two are the exception
     * while they are still absent: a seed quantity of 0 is not a tuned value,
     * it is a column that did not exist when the row was written, and leaving
     * it means an item nobody can ever buy.
     *
     * Once it is non-zero this stops touching it, so a shelf size tuned by
     * hand is as safe as a price.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fillInMarketValues(Item $item, array $attributes): void
    {
        if ($item->wasRecentlyCreated || $item->seed_quantity > 0) {
            return;
        }

        $item->forceFill([
            'rarity' => $attributes['rarity'],
            'seed_quantity' => $attributes['seed_quantity'],
        ])->save();
    }

    /**
     * A book for a slower skill is a rarer find.
     *
     * Five ranks, four rarities: the two slowest share the top one, because
     * the thing that separates rank 8 from rank 16 is the months of training,
     * not how hard the book is to come by.
     */
    private function rarityForRank(int $rank): Rarity
    {
        return match ($rank) {
            1 => Rarity::Common,
            2 => Rarity::Uncommon,
            4 => Rarity::Rare,
            default => Rarity::UltraRare,
        };
    }

    /**
     * The demo items the landing page shows.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(): array
    {
        return [
            'sandevistan_mk5' => [
                'name' => 'Sandevistan MK.V',
                'flavor' => 'Pulled from a Militech cadaver. Still warm when they found it.',
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 5, 'weight' => 1, 'base_cost' => 48_000,
                'rarity' => Rarity::UltraRare, 'seed_quantity' => 2,
            ],
            'kiroshi_optics' => [
                'name' => 'Kiroshi Optics',
                'flavor' => 'Sees more than you want it to.',
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 4, 'weight' => 1, 'base_cost' => 21_500,
                'rarity' => Rarity::Rare, 'seed_quantity' => 5,
            ],
            'ballistic_coprocessor' => [
                'name' => 'Ballistic Coprocessor',
                'flavor' => 'Does the maths so your hands do not have to.',
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 3, 'weight' => 2, 'base_cost' => 9_800,
                'rarity' => Rarity::Uncommon, 'seed_quantity' => 12,
            ],
            /*
             * The first container in the game, and the reason inventory rows
             * can nest at all: a PHYSICAL object that carries DATA. It is
             * carried, so it lists in the stash beside the gear; what is on it
             * belongs to it rather than to the deck.
             */
            'memory_shard' => [
                'name' => 'Memory Shard',
                'flavor' => 'Sixty-four gig of nothing until you put something on it.',
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 2, 'weight' => 0.1,
                // Room for eight of the 8-unit skillbooks below.
                'capacity' => 64, 'accepts' => Item::CONTAINER_DATA,
                'base_cost' => 450,
                'rarity' => Rarity::Common, 'seed_quantity' => 40,
            ],
            'scrap_alloy' => [
                'name' => 'Scrap Alloy',
                'flavor' => 'Worth almost nothing. Almost.',
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 1, 'weight' => 0.5, 'base_cost' => 12,
                'rarity' => Rarity::Common, 'seed_quantity' => 200,
            ],
        ];
    }
}
