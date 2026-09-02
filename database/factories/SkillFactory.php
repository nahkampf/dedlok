<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    protected $model = Skill::class;

    public function definition(): array
    {
        $attributes = fake()->randomElements(Character::ATTRIBUTES, 2);

        return [
            'slug' => fake()->unique()->slug(2),
            'title' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['intrusion', 'combat', 'fabrication', 'social']),
            'rank' => fake()->randomElement(config('skills.ranks')),
            'primary_attribute' => $attributes[0],
            'secondary_attribute' => $attributes[1],
            'base_cost' => fake()->numberBetween(100, 50_000),
            'on_market' => true,
        ];
    }

    public function rank(int $rank): static
    {
        return $this->state(['rank' => $rank]);
    }

    public function offMarket(): static
    {
        return $this->state(['on_market' => false]);
    }

    /** Fixes the governing attributes, so training-rate tests are deterministic. */
    public function governedBy(string $primary, string $secondary): static
    {
        return $this->state(['primary_attribute' => $primary, 'secondary_attribute' => $secondary]);
    }
}
