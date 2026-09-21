<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\SystemType;
use App\Cyberspace\Terrain;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Looking around: the act that writes both halves of discovery at once.
 *
 * What most of this file is really about is the DIFFERENCE between the two
 * halves. Terrain accumulates and is never un-set; contact is the last thing
 * you saw and is rewritten by looking again. A test that could not tell those
 * apart would pass just as happily against a single record that lies.
 */
final class SweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A small world with a small window. The rules do not change with the
        // size, and a 7 x 7 view in an 8 x 8 grid sees nearly all of it.
        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
    }

    private function character(): Character
    {
        return Character::factory()->create(['archetype' => 3]);
    }

    private function system(int $x, int $y, string $address, string $name = 'Cold Vault'): System
    {
        return System::factory()->at($x, $y)->create([
            'address' => $address,
            'name' => $name,
            'type' => SystemType::Silo,
        ]);
    }

    public function test_a_sweep_marks_every_position_in_the_window(): void
    {
        $character = $this->character();

        $result = Sweep::fromConfig()->from($character, new Position(8, 8));

        // A range of 2 is a 5 x 5 square, all of it inside a 16 x 16 grid.
        $this->assertSame(25, $result->positions);
        $this->assertSame(25, $result->newlySwept);
        $this->assertSame(25, (new Terrain($character))->sweptCount());
    }

    /**
     * THE EDGES ARE EDGES. A sweep from a corner sees fewer positions, and it
     * must not mark ground that does not exist — a bit set outside the grid
     * could never be un-set and would count towards a total no walk reaches.
     */
    public function test_a_sweep_at_the_corner_is_clipped_to_the_grid(): void
    {
        $character = $this->character();

        $result = Sweep::fromConfig()->from($character, new Position(0, 0));

        $this->assertSame(9, $result->positions, 'a corner sees a 3 x 3 quarter of its window');
        $this->assertSame(9, (new Terrain($character))->sweptCount());
        $this->assertFalse((new Terrain($character))->hasSwept(new Position(3, 0)));
    }

    public function test_what_is_standing_in_view_becomes_a_contact(): void
    {
        $character = $this->character();
        $this->system(8, 9, 'AAAAAAAA', 'Cold Vault 041');
        $this->system(20, 20, 'BBBBBBBB');

        $result = Sweep::fromConfig()->from($character, new Position(8, 8));

        $this->assertSame(1, $result->systemsSeen);
        $this->assertSame(1, $result->contactsMade);
        $this->assertSame(0, $result->contactsLost);

        $contact = SystemContact::query()->sole();

        $this->assertSame('AAAAAAAA', $contact->address);
        $this->assertSame('Cold Vault 041', $contact->name);
        $this->assertSame(SystemType::Silo, $contact->type);
        $this->assertTrue($contact->position()->equals(new Position(8, 9)));
        $this->assertNotNull($contact->seen_at);
    }

    /** Looking again at the same unchanged machine is not a new contact. */
    public function test_looking_twice_at_the_same_machine_makes_one_contact(): void
    {
        $character = $this->character();
        $this->system(8, 9, 'AAAAAAAA');

        Sweep::fromConfig()->from($character, new Position(8, 8));
        $again = Sweep::fromConfig()->from($character, new Position(8, 8));

        $this->assertSame(0, $again->contactsMade);
        $this->assertSame(0, $again->contactsLost);
        $this->assertSame(0, $again->newlySwept);
        $this->assertSame(1, SystemContact::query()->count());

        // Ground walked twice is explored once — the achievement counts what
        // the bitmap grew by, not how often somebody stood there.
        $this->assertSame(25, (new Achievements)->progressFor($character, Metric::GridExplored));
    }

    /**
     * The moment the world moves on. The ground stays swept — it cannot
     * un-happen — and the memory of the machine does not survive it.
     */
    public function test_a_machine_that_left_is_forgotten_and_the_ground_is_not(): void
    {
        $character = $this->character();
        $system = $this->system(8, 9, 'AAAAAAAA');

        Sweep::fromConfig()->from($character, new Position(8, 8));

        // Relocation: a new position and, as always, a new address.
        $system->update(['x' => 2, 'y' => 2, 'address' => 'CCCCCCCC']);

        $result = Sweep::fromConfig()->from($character, new Position(8, 8));

        $this->assertSame(1, $result->contactsLost);
        $this->assertTrue($result->foundChange());
        $this->assertSame(0, $result->systemsSeen);
        $this->assertSame(0, SystemContact::query()->count());

        $this->assertTrue((new Terrain($character))->hasSwept(new Position(8, 9)));
        $this->assertSame(25, (new Terrain($character))->sweptCount());
    }

    /**
     * A stranger at a remembered address is a loss and a find at once: the
     * machine you knew is gone, and there is a different one standing there.
     */
    public function test_a_different_machine_at_a_known_position_replaces_the_contact(): void
    {
        $character = $this->character();
        $old = $this->system(8, 9, 'AAAAAAAA', 'Cold Vault');

        Sweep::fromConfig()->from($character, new Position(8, 8));

        $old->update(['x' => 2, 'y' => 2, 'address' => 'CCCCCCCC']);
        $this->system(8, 9, 'DDDDDDDD', 'Hot Forge');

        $result = Sweep::fromConfig()->from($character, new Position(8, 8));

        $this->assertSame(1, $result->contactsLost);
        $this->assertSame(1, $result->contactsMade);

        $contact = SystemContact::query()->sole();

        $this->assertSame('DDDDDDDD', $contact->address);
        $this->assertSame('Hot Forge', $contact->name);
    }

    /** A contact outside the window is untouched by a sweep elsewhere. */
    public function test_a_sweep_only_rewrites_what_it_can_see(): void
    {
        $character = $this->character();
        $this->system(1, 1, 'AAAAAAAA');

        Sweep::fromConfig()->from($character, new Position(1, 1));
        $this->assertSame(1, SystemContact::query()->count());

        // Far enough that the two windows do not overlap at all.
        Sweep::fromConfig()->from($character, new Position(12, 12));

        $this->assertSame(1, SystemContact::query()->count());
        $this->assertSame('AAAAAAAA', SystemContact::query()->sole()->address);
    }

    /** A contact is a sighting and never a pointer: it outlives the machine. */
    public function test_a_contact_survives_the_machine_being_burned(): void
    {
        $character = $this->character();
        $system = $this->system(8, 9, 'AAAAAAAA');

        Sweep::fromConfig()->from($character, new Position(8, 8));

        $system->delete();

        $contact = SystemContact::query()->sole();

        $this->assertSame('AAAAAAAA', $contact->address);
        $this->assertFalse($contact->standingIs(null), 'nothing there now is not the machine remembered');
    }

    /** One character's memory is not another's. */
    public function test_a_sweep_records_against_the_character_who_looked(): void
    {
        $mine = $this->character();
        $theirs = $this->character();
        $this->system(8, 9, 'AAAAAAAA');

        Sweep::fromConfig()->from($mine, new Position(8, 8));

        $this->assertSame(1, SystemContact::query()->where('character_id', $mine->id)->count());
        $this->assertSame(0, SystemContact::query()->where('character_id', $theirs->id)->count());
        $this->assertFalse((new Terrain($theirs))->hasSwept(new Position(8, 8)));
    }

    /**
     * Walking one step is mostly ground you have already swept, and the count
     * a player is told about is the new part.
     */
    public function test_stepping_reveals_only_the_new_edge(): void
    {
        $character = $this->character();
        $sweeper = Sweep::fromConfig();

        $sweeper->from($character, new Position(8, 8));
        $step = $sweeper->from($character, new Position(9, 8));

        // A 5 x 5 window moved one column: five new positions, twenty already
        // swept.
        $this->assertSame(25, $step->positions);
        $this->assertSame(5, $step->newlySwept);
        $this->assertTrue($step->foundGround());
        $this->assertSame(30, (new Terrain($character))->sweptCount());
    }
}
