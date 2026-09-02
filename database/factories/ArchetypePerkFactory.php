<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Archetype;
use App\Models\ArchetypePerk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArchetypePerk>
 */
class ArchetypePerkFactory extends Factory
{
    protected $model = ArchetypePerk::class;

    /** Live by default: a display-only perk is the legacy shape. */
    public function definition(): array
    {
        return [
            'archetype_id' => Archetype::factory(),
            'key' => fake()->unique()->slug(1),
            'label' => fake()->words(2, true),
            'modifiers' => [
                ['op' => 'add', 'amount' => 1, 'target' => ['id' => 'reflex']],
            ],
            'display_modifier' => null,
            'display_unit' => null,
            'position' => 1,
        ];
    }

    /** @param  list<array<string, mixed>>  $modifiers */
    public function live(array $modifiers): static
    {
        return $this->state([
            'modifiers' => $modifiers,
            'display_modifier' => null,
            'display_unit' => null,
        ]);
    }

    /** The older shape: renders on the card, changes nothing. */
    public function displayOnly(int $amount, string $unit = 'percent'): static
    {
        return $this->state([
            'modifiers' => null,
            'display_modifier' => $amount,
            'display_unit' => $unit,
        ]);
    }
}
