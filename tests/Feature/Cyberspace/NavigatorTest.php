<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Address;
use App\Cyberspace\BookmarkKeeper;
use App\Cyberspace\Grid;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use App\Cyberspace\Terrain;
use App\Cyberspace\TravelRefusal;
use App\Decks\DeckEquipper;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Going in, moving about, and coming back out.
 *
 * The two things worth stating up front, because most of this file is one or
 * the other: READINESS IS THE GATE on the way in, and ARRIVING ANYWHERE MEANS
 * LOOKING AROUND. A move that recorded a position without the sweep that
 * explains it would leave a hole in the map exactly where the player is
 * standing.
 */
final class NavigatorTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        // A small world. The portal lands at (8, 8) and the rules do not
        // change with the size.
        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function navigator(): Navigator
    {
        return Navigator::fromConfig(app(Resolver::class));
    }

    private function portal(): Position
    {
        return Grid::fromConfig()->portal();
    }

    /** An equipped deck with nothing in it. It will not run. */
    private function equipEmptyDeck(): InventoryItem
    {
        $deck = $this->ownedBy($this->character, Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1])->create());

        app(DeckEquipper::class)->equip($this->character, $deck);
        $this->character->refresh();

        return $deck;
    }

    // ---- getting in --------------------------------------------------------

    public function test_a_ready_deck_gets_you_in_at_the_portal(): void
    {
        $this->equipReadyDeck($this->character);

        $result = $this->navigator()->jackIn($this->character);

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->position->equals($this->portal()));
        $this->assertTrue($this->character->fresh()->isJackedIn());
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($this->portal()));
    }

    public function test_you_cannot_go_in_without_a_deck(): void
    {
        $result = $this->navigator()->jackIn($this->character);

        $this->assertSame(TravelRefusal::NoDeck, $result->refusal);
        $this->assertFalse($this->character->fresh()->isJackedIn());
    }

    /**
     * READINESS IS THE GATE. DeckTotals::isReady() is the whole check and
     * there is no second list — a deck short of compute, memory, link or power
     * does not enter cyberspace.
     */
    public function test_an_unready_deck_does_not_enter_cyberspace(): void
    {
        $this->equipEmptyDeck();

        $result = $this->navigator()->jackIn($this->character);

        $this->assertSame(TravelRefusal::DeckNotReady, $result->refusal);
        $this->assertFalse($this->character->fresh()->isJackedIn());
    }

    public function test_jacking_in_twice_is_refused(): void
    {
        $this->equipReadyDeck($this->character);
        $this->navigator()->jackIn($this->character);

        $again = $this->navigator()->jackIn($this->character->fresh());

        $this->assertSame(TravelRefusal::AlreadyJackedIn, $again->refusal);
    }

    // ---- coming out --------------------------------------------------------

    public function test_jacking_out_takes_the_position_with_it(): void
    {
        $this->equipReadyDeck($this->character);
        $this->navigator()->jackIn($this->character);

        $result = $this->navigator()->jackOut($this->character->fresh());

        $this->assertTrue($result->succeeded());
        $this->assertNull($result->position);
        $this->assertFalse($this->character->fresh()->isJackedIn());
        $this->assertNull($this->character->fresh()->gridPosition());
    }

    public function test_jacking_out_when_you_are_not_in_is_refused(): void
    {
        $this->assertSame(
            TravelRefusal::NotJackedIn,
            $this->navigator()->jackOut($this->character)->refusal,
        );
    }

    /**
     * A POSITION IS NOT RESUMED. Every jack-in arrives at the portal, because
     * the way back to the frontier is a bookmark rather than a saved
     * coordinate — which is what makes a bookmark worth having.
     */
    public function test_going_back_in_starts_at_the_portal_again(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();

        $nav->jackIn($this->character);
        $nav->step($this->character->fresh(), new Position($this->portal()->x + 1, $this->portal()->y));
        $nav->jackOut($this->character->fresh());

        $back = $nav->jackIn($this->character->fresh());

        $this->assertTrue($back->position->equals($this->portal()));
    }

    // ---- walking -----------------------------------------------------------

    public function test_a_step_moves_one_position_and_sweeps(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $before = (new Terrain($this->character))->sweptCount();

        // Diagonal, because a diagonal step is one step.
        $to = new Position($this->portal()->x + 1, $this->portal()->y + 1);
        $result = $nav->step($this->character->fresh(), $to);

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->position->equals($to));
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($to));
        $this->assertGreaterThan($before, (new Terrain($this->character))->sweptCount());
        $this->assertGreaterThan(0, $result->sweep->newlySwept);
    }

    public function test_a_step_tires_and_a_refused_one_does_not(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $nav->step($this->character->fresh(), new Position($this->portal()->x + 5, $this->portal()->y));
        $this->assertSame(0, $this->character->fresh()->current_fatigue);

        $nav->step($this->character->fresh(), new Position($this->portal()->x + 1, $this->portal()->y));
        $this->assertSame(0.1, $this->character->fresh()->current_fatigue);
    }

    public function test_two_positions_away_is_not_a_step(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $result = $nav->step($this->character->fresh(), new Position($this->portal()->x + 2, $this->portal()->y));

        $this->assertSame(TravelRefusal::NotAStep, $result->refusal);
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($this->portal()));
    }

    /**
     * THE GRID DOES NOT WRAP. Walking into the edge is an ordinary thing to
     * do, and it gets its own sentence rather than the one about malformed
     * requests.
     */
    public function test_walking_off_the_edge_is_refused_as_the_edge(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        // Walk to the west edge, then try to keep going.
        for ($x = $this->portal()->x - 1; $x >= 0; $x--) {
            $nav->step($this->character->fresh(), new Position($x, $this->portal()->y));
        }

        $this->assertTrue($this->character->fresh()->gridPosition()->equals(new Position(0, $this->portal()->y)));

        $result = $nav->step($this->character->fresh(), new Position(-1, $this->portal()->y));

        $this->assertSame(TravelRefusal::OffTheGrid, $result->refusal);
    }

    public function test_you_cannot_walk_without_being_in(): void
    {
        $this->assertSame(
            TravelRefusal::NotJackedIn,
            $this->navigator()->step($this->character, new Position(1, 1))->refusal,
        );
    }

    // ---- jumping -----------------------------------------------------------

    public function test_a_jump_arrives_at_whatever_answers_to_the_address(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $system = System::factory()->at(2, 13)->type(SystemType::Relay)->create(['address' => 'DEADBEEF']);

        $result = $nav->jump($this->character->fresh(), $system->address);

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->position->equals(new Position(2, 13)));
        $this->assertSame(1, $result->sweep->systemsSeen);
    }

    /** A typed address arrives with a stray space and in whatever case. */
    public function test_a_typed_address_is_normalised_before_it_is_looked_up(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        System::factory()->at(2, 13)->create(['address' => 'DEADBEEF']);

        $this->assertTrue($nav->jump($this->character->fresh(), '  deadbeef ')->succeeded());
    }

    public function test_something_that_is_not_an_address_is_told_apart_from_one_nothing_answers(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $this->assertSame(
            TravelRefusal::NotAnAddress,
            $nav->jump($this->character->fresh(), 'not an address')->refusal,
        );

        // Well-formed, and nothing wears it. The world moved on.
        $this->assertSame(
            TravelRefusal::NoSuchAddress,
            $nav->jump($this->character->fresh(), 'ABCDEF01')->refusal,
        );
    }

    /**
     * An address is a fact about a system RIGHT NOW. One traded last week is
     * allowed to arrive nowhere, and that is the mechanic rather than a fault.
     */
    public function test_an_address_stops_resolving_when_its_system_relocates(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $system = System::factory()->at(2, 13)->create(['address' => 'DEADBEEF']);

        $this->assertTrue($nav->jump($this->character->fresh(), 'DEADBEEF')->succeeded());

        $system->update(['x' => 4, 'y' => 4, 'address' => Address::generate()]);

        $this->assertSame(
            TravelRefusal::NoSuchAddress,
            $nav->jump($this->character->fresh(), 'DEADBEEF')->refusal,
        );
    }

    public function test_you_cannot_jump_without_being_in(): void
    {
        $this->assertSame(
            TravelRefusal::NotJackedIn,
            $this->navigator()->jump($this->character, 'DEADBEEF')->refusal,
        );
    }

    // ---- coming back -------------------------------------------------------

    /**
     * The other half of *you walk, you bookmark the frontier, you come back*.
     * Returning is free: walking is what buys new ground, and a waypoint only
     * saves re-walking ground already bought.
     */
    public function test_a_waypoint_takes_you_straight_back_to_it(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        // Walk two steps, mark the frontier, then go home.
        $frontier = new Position($this->portal()->x + 2, $this->portal()->y);
        $nav->step($this->character->fresh(), new Position($this->portal()->x + 1, $this->portal()->y));
        $nav->step($this->character->fresh(), $frontier);

        $mark = BookmarkKeeper::fromConfig()
            ->markPosition($this->character->fresh(), $frontier)->bookmark;

        $nav->jackOut($this->character->fresh());
        $nav->jackIn($this->character->fresh());
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($this->portal()));

        $back = $nav->recall($this->character->fresh(), $mark);

        $this->assertTrue($back->succeeded());
        $this->assertTrue($back->position->equals($frontier));
        $this->assertSame(0, $back->sweep->newlySwept, 'ground already bought');
    }

    /** A system bookmark goes through the same lookup a typed address does. */
    public function test_a_system_bookmark_arrives_where_the_address_answers(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $system = System::factory()->at(2, 13)->create(['address' => 'DEADBEEF']);
        $mark = BookmarkKeeper::fromConfig()
            ->markAddress($this->character->fresh(), 'DEADBEEF')->bookmark;

        $this->assertTrue($nav->recall($this->character->fresh(), $mark)->succeeded());
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($system->position()));
    }

    /**
     * Arriving nowhere is the ordinary outcome for a perishable bookmark, and
     * the mechanic rather than a fault.
     */
    public function test_a_system_bookmark_whose_system_moved_arrives_nowhere(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $system = System::factory()->at(2, 13)->create(['address' => 'DEADBEEF']);
        $mark = BookmarkKeeper::fromConfig()
            ->markAddress($this->character->fresh(), 'DEADBEEF')->bookmark;

        $system->update(['x' => 4, 'y' => 4, 'address' => 'AAAAAAAA']);

        $this->assertSame(
            TravelRefusal::NoSuchAddress,
            $nav->recall($this->character->fresh(), $mark)->refusal,
        );
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($this->portal()));
    }

    public function test_you_cannot_recall_without_being_in(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $mark = BookmarkKeeper::fromConfig()
            ->markPosition($this->character->fresh(), $this->portal())->bookmark;

        $nav->jackOut($this->character->fresh());

        $this->assertSame(
            TravelRefusal::NotJackedIn,
            $nav->recall($this->character->fresh(), $mark)->refusal,
        );
    }

    public function test_you_cannot_recall_somebody_elses_bookmark(): void
    {
        $this->equipReadyDeck($this->character);
        $nav = $this->navigator();
        $nav->jackIn($this->character);

        $mark = BookmarkKeeper::fromConfig()
            ->markPosition($this->character->fresh(), $this->portal())->bookmark;

        $stranger = Character::factory()->create(['archetype' => 3]);

        $this->assertSame(TravelRefusal::NotYours, $nav->recall($stranger, $mark)->refusal);
    }

    // ---- every refusal says something --------------------------------------

    public function test_every_refusal_has_a_string(): void
    {
        foreach (TravelRefusal::cases() as $refusal) {
            $this->assertNotSame(
                "cyberspace.travel.{$refusal->value}",
                $refusal->message(),
                "TravelRefusal::{$refusal->name} has no string",
            );
        }
    }
}
