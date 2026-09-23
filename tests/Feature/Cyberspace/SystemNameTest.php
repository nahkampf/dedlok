<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\SystemNamer;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Entity;
use App\Models\System;
use App\Models\SystemContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A machine is named for what it is and who runs it.
 *
 * The rule this file holds is that the name is COMPOSED and not stored: both
 * halves of it are columns already, so a stored sentence would be the same
 * facts written twice. What follows from that is the pair of behaviours below
 * — a live machine follows its owner's name, and a memory of one does not,
 * because a contact is a snapshot and is allowed to be out of date.
 */
final class SystemNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
    }

    public function test_a_generated_machine_is_named_for_its_type_its_owner_and_its_serial(): void
    {
        $entity = Entity::query()->firstOrFail();

        $system = System::factory()->at(3, 3)->ownedBy($entity)->create([
            'type' => SystemType::Silo,
            'designation' => 41,
        ]);

        $this->assertSame(SystemType::Silo->label().' - '.$entity->name.' 041', $system->displayName());
    }

    /** Padded, because 041 reads as a serial number and 41 reads as a count. */
    public function test_the_serial_is_three_digits(): void
    {
        $this->assertSame('001', SystemNamer::designation(1));
        $this->assertSame('041', SystemNamer::designation(41));
        $this->assertSame('999', SystemNamer::designation(999));
    }

    /**
     * The override, and the only shape a name-on-the-row now takes: the
     * portal today, an event or mission system later.
     */
    public function test_an_authored_name_wins(): void
    {
        $entity = Entity::query()->firstOrFail();

        $system = System::factory()->at(3, 3)->ownedBy($entity)
            ->named('The Wound')
            ->create(['designation' => 41]);

        $this->assertSame('The Wound', $system->displayName());
    }

    /**
     * A machine nobody runs reads as a name rather than as a sentence with a
     * hole in it. The portal is the case that exists today, and it is authored
     * — this is the fallback under it.
     */
    public function test_a_machine_with_no_owner_falls_back_to_its_type(): void
    {
        $system = System::factory()->at(3, 3)->create([
            'type' => SystemType::Relay,
            'owner_id' => null,
            'designation' => 7,
        ]);

        $this->assertSame(SystemType::Relay->label().' 007', $system->displayName());
    }

    /** Nothing is composed for the portal at all. */
    public function test_the_portal_keeps_the_name_it_was_given(): void
    {
        $portal = System::factory()->at(3, 3)->portal()->create();

        $this->assertSame('Cyberspace Portal', $portal->displayName());
        $this->assertNull($portal->designation);
    }

    /**
     * THE LIVE MACHINE FOLLOWS ITS OWNER. This is what composing rather than
     * storing buys: rename the company and every machine it runs is renamed,
     * with no migration and nothing to keep in step.
     */
    public function test_renaming_an_entity_renames_the_machines_it_runs(): void
    {
        $entity = Entity::query()->firstOrFail();
        $system = System::factory()->at(3, 3)->ownedBy($entity)->create([
            'type' => SystemType::Compute,
            'designation' => 12,
        ]);

        $entity->update(['name' => 'Vestibule Analytics']);

        $this->assertSame(
            SystemType::Compute->label().' - Vestibule Analytics 012',
            $system->fresh()->displayName(),
        );
    }

    /**
     * AND THE MEMORY OF IT DOES NOT. A contact is what a player saw, written
     * down; it outlives the machine and it is allowed to be wrong about it,
     * which is the whole reason it carries a name of its own.
     */
    public function test_a_remembered_name_is_a_snapshot_and_does_not_follow(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $entity = Entity::query()->firstOrFail();

        $system = System::factory()->at(8, 9)->ownedBy($entity)->create([
            'address' => 'DEADBEEF',
            'type' => SystemType::Silo,
            'designation' => 41,
        ]);

        Sweep::fromConfig()->from($character, new Position(8, 8));

        $seen = SystemType::Silo->label().' - '.$entity->name.' 041';
        $this->assertSame($seen, SystemContact::query()->sole()->name);

        $entity->update(['name' => 'Vestibule Analytics']);
        $system->delete();

        $this->assertSame($seen, SystemContact::query()->sole()->name);
    }

    /** Every generated machine carries a serial, or a name identifies eight. */
    public function test_the_serial_is_rolled_inside_its_configured_bounds(): void
    {
        Config::set('cyberspace.designation', ['min' => 5, 'max' => 5]);

        $this->assertSame(5, SystemNamer::fromConfig()->designate());
    }
}
