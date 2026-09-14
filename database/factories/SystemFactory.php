<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Cyberspace\Address;
use App\Cyberspace\Grade;
use App\Cyberspace\SystemType;
use App\Models\Corp;
use App\Models\System;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<System>
 */
class SystemFactory extends Factory
{
    protected $model = System::class;

    public function definition(): array
    {
        return [
            'address' => Address::generate(),
            // Deliberately NOT random: a position is unique, and a factory that
            // rolled one would collide with itself the third time a test asked
            // for a system. Tests say where they mean; ->at() is how.
            'x' => 0,
            'y' => 0,
            'type' => SystemType::Silo,
            // The floor rather than a roll: a test that wanted a hard system
            // says so, and a rolled grade is a test that reads differently
            // every run.
            'grade' => Grade::MIN,
            'name' => fake()->words(2, true),
        ];
    }

    public function at(int $x, int $y): static
    {
        return $this->state(['x' => $x, 'y' => $y]);
    }

    public function type(SystemType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function portal(): static
    {
        // Nobody owns the portal, so the state clears the owner as well as
        // setting the type — the two facts are one fact.
        return $this->state([
            'type' => SystemType::Portal,
            'name' => 'Cyberspace Portal',
            'owner_id' => null,
        ]);
    }

    public function grade(int $grade): static
    {
        return $this->state(['grade' => $grade]);
    }

    /** Run by a particular corp. */
    public function ownedBy(Corp $corp): static
    {
        return $this->state(['owner_id' => $corp->id]);
    }
}
