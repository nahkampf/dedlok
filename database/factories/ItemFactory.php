<?php

declare(strict_types=1);

namespace Database\Factories;

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
            'container' => fake()->randomElement(Item::CONTAINERS),
            'tier' => fake()->numberBetween(1, Item::MAX_TIER),
            'stackable' => true,
            'use_op' => null,
            'use_skill_id' => null,
            'weight' => fake()->randomFloat(2, 0, 20),
            'size' => fake()->numberBetween(0, 512),
            'base_cost' => fake()->numberBetween(10, 50_000),
            'on_market' => true,
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
