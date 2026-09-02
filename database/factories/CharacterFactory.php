<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Archetype;
use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    protected $model = Character::class;

    public function definition(): array
    {
        $attributes = [];

        foreach (Character::ATTRIBUTES as $attribute) {
            $attributes[$attribute] = fake()->numberBetween(
                Character::ATTRIBUTE_MIN,
                Character::CREATION_ATTRIBUTE_MAX
            );
        }

        return array_merge($attributes, [
            'user_id' => User::factory(),
            // Handles are unique and capped at 24 characters, so the generator
            // has to respect both rather than using a plain word list.
            'handle' => mb_substr(fake()->unique()->userName(), 0, 24),
            // Was numberBetween(0, 7), which could produce 0 — an id no
            // archetype ever had. The foreign key refuses it now, so this
            // picks from the roster that actually exists.
            'archetype' => Archetype::roster()->keys()->random(),
        ]);
    }

    /** All ten attributes at the floor. */
    public function blank(): static
    {
        return $this->state(array_fill_keys(Character::ATTRIBUTES, Character::ATTRIBUTE_MIN));
    }

    /** All ten attributes at the ceiling. */
    public function maxed(): static
    {
        return $this->state(array_fill_keys(Character::ATTRIBUTES, Character::ATTRIBUTE_MAX));
    }
}
