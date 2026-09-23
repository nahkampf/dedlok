<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\GridSeeder;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Entity;
use App\Models\System;
use App\Models\SystemContact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Somebody owns every machine on the grid.
 *
 * The distinction this file exists to hold is that an owner is a THING and a
 * remembered owner is a STRING. `systems.owner_id` points at an entity that can be
 * asked questions — faction standing, one day. `system_contacts.owner` is a
 * name written down at the moment of looking, and it has to survive the entity
 * being renamed or the machine being burned, because a memory is not a pointer.
 */
final class SystemOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
    }

    /** The roster arrives by migration, like the archetypes. */
    public function test_the_entity_roster_arrives_with_the_schema(): void
    {
        $this->assertGreaterThan(100, Entity::query()->count());
        $this->assertSame(
            Entity::query()->count(),
            Entity::query()->distinct()->count('name'),
            'two entities with one name is one entity with a typo',
        );

        $entity = Entity::query()->firstOrFail();
        $this->assertNotSame('', $entity->name);
        $this->assertNotSame('', $entity->business);
    }

    public function test_a_generated_world_gives_every_machine_an_owner(): void
    {
        Config::set('cyberspace.occupancy', 50);

        GridSeeder::fromConfig(fn (int $min, int $max): int => random_int($min, $max))->run();

        $this->assertSame(
            0,
            System::query()->where('type', '!=', SystemType::Portal->value)->whereNull('owner_id')->count(),
            'every machine answers to somebody',
        );
    }

    /**
     * Except the portal, which is the one system that is none of the things a
     * system is. Inventing a company to run it would be a fact nobody asked
     * for.
     */
    public function test_the_portal_is_owned_by_nobody(): void
    {
        Config::set('cyberspace.occupancy', 50);

        GridSeeder::fromConfig(fn (int $min, int $max): int => random_int($min, $max))->run();

        $portal = System::query()->where('type', SystemType::Portal->value)->sole();

        $this->assertNull($portal->owner_id);
        $this->assertNull($portal->owner);
    }

    public function test_owners_are_spread_rather_than_all_one_entity(): void
    {
        Config::set('cyberspace.occupancy', 70);

        GridSeeder::fromConfig(fn (int $min, int $max): int => random_int($min, $max))->run();

        $distinct = System::query()->whereNotNull('owner_id')->distinct()->count('owner_id');

        $this->assertGreaterThan(10, $distinct, 'the roster is drawn from, not picked once');
    }

    public function test_a_system_knows_who_runs_it(): void
    {
        $entity = Entity::query()->firstOrFail();
        $system = System::factory()->at(3, 3)->ownedBy($entity)->create();

        $this->assertSame($entity->id, $system->owner->id);
        $this->assertTrue($entity->systems->contains($system));
    }

    /**
     * An entity that still runs machines cannot be deleted. Restricted rather
     * than cascading: losing a company should not quietly burn every node it
     * owned.
     */
    public function test_an_entity_running_machines_cannot_be_deleted(): void
    {
        $entity = Entity::query()->firstOrFail();
        System::factory()->at(3, 3)->ownedBy($entity)->create();

        $this->expectException(QueryException::class);

        $entity->delete();
    }

    // ---- what a player remembers ------------------------------------------

    public function test_looking_at_a_machine_records_who_ran_it(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $entity = Entity::query()->firstOrFail();

        System::factory()->at(8, 9)->ownedBy($entity)->create(['address' => 'DEADBEEF']);

        Sweep::fromConfig()->from($character, new Position(8, 8));

        $this->assertSame($entity->name, SystemContact::query()->sole()->owner);
    }

    /**
     * THE MEMORY IS A NAME, NOT A POINTER. It has to outlive the machine, and
     * it has to say what the player actually saw rather than what the entity is
     * called today.
     */
    public function test_the_remembered_owner_survives_the_machine_being_burned(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $entity = Entity::query()->firstOrFail();

        $system = System::factory()->at(8, 9)->ownedBy($entity)->create(['address' => 'DEADBEEF']);
        Sweep::fromConfig()->from($character, new Position(8, 8));

        $system->delete();

        $this->assertSame($entity->name, SystemContact::query()->sole()->owner);
    }

    public function test_a_remembered_owner_does_not_change_when_the_entity_is_renamed(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $entity = Entity::query()->firstOrFail();
        $seen = $entity->name;

        System::factory()->at(8, 9)->ownedBy($entity)->create(['address' => 'DEADBEEF']);
        Sweep::fromConfig()->from($character, new Position(8, 8));

        $entity->update(['name' => 'Something Else Entirely']);

        $this->assertSame($seen, SystemContact::query()->sole()->owner);
    }

    /** Nothing owns the portal, so nothing is remembered about who does. */
    public function test_a_remembered_portal_has_no_owner(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);

        System::factory()->at(8, 9)->portal()->create(['address' => 'DEADBEEF']);
        Sweep::fromConfig()->from($character, new Position(8, 8));

        $this->assertNull(SystemContact::query()->sole()->owner);
    }
}
