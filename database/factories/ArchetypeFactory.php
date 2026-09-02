<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Archetype;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Archetype>
 */
class ArchetypeFactory extends Factory
{
    protected $model = Archetype::class;

    public function definition(): array
    {
        return [
            // Ids are assigned by hand everywhere else; a factory has to pick
            // one, so it picks well clear of the seeded roster.
            'id' => fake()->unique()->numberBetween(100, 255),
            'slug' => fake()->unique()->slug(1),
            'name' => fake()->words(2, true),
            'role' => fake()->word(),
            'description' => fake()->sentence(),
        ];
    }
}
