<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Market\Rarity;
use App\Models\Item;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'flavor' => null,
            'container' => fake()->randomElement(Item::CONTAINERS),
            'tier' => fake()->numberBetween(1, Item::MAX_TIER),
            // Not randomised, unlike tier. Rarity decides whether a restock
            // pass delivers anything, so a random one would make any test
            // that touches the market roll dice it never asked to roll.
            'rarity' => Rarity::Common,
            'stackable' => true,
            'use_op' => null,
            'use_skill_id' => null,
            'weight' => fake()->randomFloat(2, 0, 20),
            'size' => fake()->numberBetween(0, 512),
            'capacity' => 0,
            'accepts' => null,
            'base_cost' => fake()->numberBetween(10, 50_000),
            'on_market' => true,
            // Nothing the market stocks unless a test says so, matching the
            // column default: an item is listed before it is stocked.
            'seed_quantity' => 0,
        ];
    }

    public function physical(): static
    {
        return $this->state(['container' => Item::CONTAINER_PHYSICAL]);
    }

    public function data(): static
    {
        return $this->state(['container' => Item::CONTAINER_DATA]);
    }

    public function tier(int $tier): static
    {
        return $this->state(['tier' => $tier]);
    }

    public function rarity(Rarity $rarity): static
    {
        return $this->state(['rarity' => $rarity]);
    }

    /** Something the market carries, and how many a full shelf is. */
    public function restocks(int $seedQuantity): static
    {
        return $this->state(['seed_quantity' => $seedQuantity, 'on_market' => true]);
    }

    public function offMarket(): static
    {
        return $this->state(['on_market' => false]);
    }

    /**
     * A container: something other items go inside.
     *
     * Unstackable by default, because two of them are two objects the moment
     * either has anything on it — which is the whole reason nesting forced
     * per-instance rows in the first place.
     */
    public function holds(string $accepts, int $capacity = 64): static
    {
        return $this->state([
            'capacity' => $capacity,
            'accepts' => $accepts,
            'stackable' => false,
        ]);
    }

    public function size(int $size): static
    {
        return $this->state(['size' => $size]);
    }

    public function unstackable(): static
    {
        return $this->state(['stackable' => false]);
    }

    /** A book that teaches one skill. Always data: it is injected, not carried. */
    public function skillbookFor(Skill $skill): static
    {
        return $this->state([
            'slug' => "skillbook_{$skill->slug}",
            'container' => Item::CONTAINER_DATA,
            'use_op' => Item::USE_INJECT_SKILL,
            'use_skill_id' => $skill->id,
        ]);
    }
}
