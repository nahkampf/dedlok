<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Cyberspace\Address;
use App\Cyberspace\Grade;
use App\Cyberspace\SystemType;
use App\Models\Entity;
use App\Models\Ice;
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
            // NO NAME, like anything the world generates: the name is composed
            // from the type and the owner, and a factory that invented one
            // would be making systems no seeder could produce. ->named() is how
            // a test asks for an authored one.
            'name' => null,
            'designation' => fake()->numberBetween(1, 999),
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
            'designation' => null,
            'owner_id' => null,
        ]);
    }

    /** Carrying a name of its own, the way an event or mission system will. */
    public function named(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    public function grade(int $grade): static
    {
        return $this->state(['grade' => $grade]);
    }

    /**
     * Watching for intruders.
     *
     * NOT IN `definition()`, deliberately: the columns default to 0 and a
     * factory-built machine therefore defends itself with nothing, so a test
     * about defences has to say so. That is the same call the schema makes for
     * the same reason — nothing becomes dangerous by being forgotten.
     */
    public function withIds(int $detection): static
    {
        return $this->state(['ids_detection' => $detection]);
    }

    /** Tracing. Both columns together, because a tracer is one thing. */
    public function withTracer(int $detection, int $rate): static
    {
        return $this->state([
            'tracer_detection' => $detection,
            'tracer_rate' => $rate,
        ]);
    }

    /** Guarded. Takes the ICE row rather than a level, like the column does. */
    public function withIce(Ice $ice): static
    {
        return $this->state(['ice_id' => $ice->id]);
    }

    /** Run by a particular entity. */
    public function ownedBy(Entity $entity): static
    {
        return $this->state(['owner_id' => $entity->id]);
    }
}
