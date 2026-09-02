<?php

declare(strict_types=1);

namespace Database\Seeders;

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
     * place to forget when a skill is added. Their names come from the skill,
     * so no lang entry is needed either.
     */
    public function run(): void
    {
        foreach ($this->catalogue() as $slug => $attributes) {
            Item::firstOrCreate(['slug' => $slug], $attributes);
        }

        Skill::query()->each(function (Skill $skill): void {
            Item::firstOrCreate(
                ['slug' => "skillbook_{$skill->slug}"],
                [
                    'container' => Item::CONTAINER_DATA,
                    // Rank is a training-time multiplier, so a book for a
                    // slower skill is a rarer find. Ranks are 1,2,4,8,16.
                    'tier' => min(Item::MAX_TIER, (int) log($skill->rank, 2) + 1),
                    'stackable' => true,
                    'use_op' => Item::USE_INJECT_SKILL,
                    'use_skill_id' => $skill->id,
                    'size' => 8,
                    'base_cost' => $skill->base_cost,
                    'on_market' => $skill->on_market,
                ]
            );
        });
    }

    /**
     * The demo items the landing page already names in lang/en/items.php.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(): array
    {
        return [
            'sandevistan_mk5' => [
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 5, 'weight' => 1, 'base_cost' => 48_000,
            ],
            'kiroshi_optics' => [
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 4, 'weight' => 1, 'base_cost' => 21_500,
            ],
            'ballistic_coprocessor' => [
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 3, 'weight' => 2, 'base_cost' => 9_800,
            ],
            /*
             * The first container in the game, and the reason inventory rows
             * can nest at all: a PHYSICAL object that carries DATA. It is
             * carried, so it lists in the stash beside the gear; what is on it
             * belongs to it rather than to the deck.
             */
            'memory_shard' => [
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 2, 'weight' => 0.1,
                // Room for eight of the 8-unit skillbooks below.
                'capacity' => 64, 'accepts' => Item::CONTAINER_DATA,
                'base_cost' => 450,
            ],
            'scrap_alloy' => [
                'container' => Item::CONTAINER_PHYSICAL,
                'tier' => 1, 'weight' => 0.5, 'base_cost' => 12,
            ],
        ];
    }
}
