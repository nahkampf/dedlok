<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BookmarkKeeper;
use App\Cyberspace\BookmarkKind;
use App\Cyberspace\BookmarkRefusal;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\Terrain;
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Saved ways back, and the asymmetry that paces the whole game.
 *
 * A WAYPOINT NEEDS SWEPT GROUND; A SYSTEM BOOKMARK NEEDS NOTHING. That looks
 * inconsistent and is the point: an address is a door, so trading one is
 * harmless, while a position is a map, so a position obtained without walking
 * would let one player hand another the exploration they did.
 */
final class BookmarkTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        $this->character = Character::factory()->create(['archetype' => 3]);
    }

    private function keeper(): BookmarkKeeper
    {
        return BookmarkKeeper::fromConfig();
    }

    /** Look around from here, so the ground counts as swept. */
    private function sweep(Position $at): void
    {
        Sweep::fromConfig()->from($this->character, $at);
    }

    // ---- waypoints ---------------------------------------------------------

    public function test_a_swept_position_can_be_saved(): void
    {
        $this->sweep(new Position(8, 8));

        $result = $this->keeper()->markPosition($this->character, new Position(8, 8), 'The frontier');

        $this->assertTrue($result->succeeded());
        $this->assertSame(BookmarkKind::Waypoint, $result->bookmark->kind);
        $this->assertTrue($result->bookmark->position()->equals(new Position(8, 8)));
        $this->assertSame('The frontier', $result->bookmark->label);
        $this->assertNull($result->bookmark->address);
    }

    /**
     * THE GATE. A position saved sight unseen would make coordinates
     * tradeable, and a traded coordinate is a map somebody else walked.
     */
    public function test_ground_never_swept_cannot_be_saved(): void
    {
        $result = $this->keeper()->markPosition($this->character, new Position(3, 3));

        $this->assertSame(BookmarkRefusal::NeverSwept, $result->refusal);
        $this->assertSame(0, Bookmark::query()->count());
    }

    /** Sweeping the window around you covers more than the cell you stand on. */
    public function test_anywhere_in_a_swept_window_can_be_saved(): void
    {
        $this->sweep(new Position(8, 8));

        // A corner of the 5x5 window, never stood on.
        $this->assertTrue($this->keeper()->markPosition($this->character, new Position(6, 6))->succeeded());
    }

    public function test_a_position_off_the_grid_is_refused_as_the_edge(): void
    {
        $this->assertSame(
            BookmarkRefusal::OffTheGrid,
            $this->keeper()->markPosition($this->character, new Position(99, 99))->refusal,
        );
    }

    public function test_saving_the_same_position_twice_makes_one_bookmark(): void
    {
        $this->sweep(new Position(8, 8));
        $this->keeper()->markPosition($this->character, new Position(8, 8));

        $again = $this->keeper()->markPosition($this->character, new Position(8, 8));

        $this->assertSame(BookmarkRefusal::AlreadySaved, $again->refusal);
        $this->assertSame(1, Bookmark::query()->count());
    }

    /** A waypoint survives everything, because a position cannot move. */
    public function test_a_waypoint_does_not_care_what_stands_on_it(): void
    {
        $system = System::factory()->at(8, 9)->create(['address' => 'DEADBEEF']);
        $this->sweep(new Position(8, 8));

        $saved = $this->keeper()->markPosition($this->character, new Position(8, 9))->bookmark;

        $system->delete();

        $this->assertTrue($saved->fresh()->position()->equals(new Position(8, 9)));
        $this->assertFalse($saved->kind->isPerishable());
    }

    // ---- system bookmarks --------------------------------------------------

    /**
     * No gate at all. Trading addresses is expected, and an address carries no
     * position — it tells you where to arrive and nothing about where that is.
     */
    public function test_an_address_can_be_saved_without_ever_having_seen_it(): void
    {
        System::factory()->at(2, 13)->create(['address' => 'DEADBEEF']);

        $result = $this->keeper()->markAddress($this->character, 'DEADBEEF');

        $this->assertTrue($result->succeeded());
        $this->assertSame(BookmarkKind::System, $result->bookmark->kind);
        $this->assertNull($result->bookmark->x);
        $this->assertSame(0, (new Terrain($this->character))->sweptCount());
    }

    /** An address that already answers to nothing is still worth writing down. */
    public function test_an_address_nothing_answers_to_can_still_be_saved(): void
    {
        $result = $this->keeper()->markAddress($this->character, 'ABCDEF01');

        $this->assertTrue($result->succeeded());
        $this->assertNull($result->bookmark->resolve());
    }

    public function test_a_typed_address_is_normalised(): void
    {
        $saved = $this->keeper()->markAddress($this->character, '  deadbeef ')->bookmark;

        $this->assertSame('DEADBEEF', $saved->address);
    }

    public function test_something_that_is_not_an_address_is_refused(): void
    {
        $this->assertSame(
            BookmarkRefusal::NotAnAddress,
            $this->keeper()->markAddress($this->character, 'nonsense')->refusal,
        );
    }

    /** The world moving on, seen from the bookmark list. */
    public function test_a_system_bookmark_stops_resolving_when_its_system_relocates(): void
    {
        $system = System::factory()->at(2, 13)->create(['address' => 'DEADBEEF', 'name' => 'Cold Vault']);
        $saved = $this->keeper()->markAddress($this->character, 'DEADBEEF')->bookmark;

        $this->assertSame('Cold Vault', $saved->displayLabel());

        $system->update(['x' => 4, 'y' => 4, 'address' => 'AAAAAAAA']);

        $this->assertNull($saved->fresh()->resolve());
        $this->assertNull($saved->fresh()->displayLabel(), 'nothing to fall back to once it is gone');
        $this->assertTrue($saved->kind->isPerishable());
    }

    // ---- both --------------------------------------------------------------

    /**
     * The two unique keys are over nullable columns, so a pile of system
     * bookmarks must not collide with each other on (character, NULL, NULL).
     */
    public function test_many_bookmarks_of_each_kind_coexist(): void
    {
        $this->sweep(new Position(8, 8));

        $this->assertTrue($this->keeper()->markPosition($this->character, new Position(7, 7))->succeeded());
        $this->assertTrue($this->keeper()->markPosition($this->character, new Position(9, 9))->succeeded());
        $this->assertTrue($this->keeper()->markAddress($this->character, 'AAAAAAAA')->succeeded());
        $this->assertTrue($this->keeper()->markAddress($this->character, 'BBBBBBBB')->succeeded());

        $this->assertSame(4, Bookmark::query()->count());
        $this->assertSame(2, Bookmark::query()->ofKind(BookmarkKind::Waypoint)->count());
        $this->assertSame(2, Bookmark::query()->ofKind(BookmarkKind::System)->count());
    }

    public function test_a_waypoint_falls_back_to_its_coordinates(): void
    {
        $this->sweep(new Position(8, 8));

        $saved = $this->keeper()->markPosition($this->character, new Position(8, 8))->bookmark;

        $this->assertSame('8, 8', $saved->displayLabel());
    }

    public function test_a_blank_label_is_no_label_rather_than_an_empty_one(): void
    {
        $this->sweep(new Position(8, 8));

        $saved = $this->keeper()->markPosition($this->character, new Position(8, 8), '   ')->bookmark;

        $this->assertNull($saved->label);
    }

    public function test_a_bookmark_can_be_renamed_and_forgotten(): void
    {
        $this->sweep(new Position(8, 8));
        $saved = $this->keeper()->markPosition($this->character, new Position(8, 8))->bookmark;

        $this->keeper()->rename($this->character, $saved, 'Way home');
        $this->assertSame('Way home', $saved->fresh()->label);

        $this->assertTrue($this->keeper()->forget($this->character, $saved)->succeeded());
        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_another_characters_bookmark_is_not_yours_to_touch(): void
    {
        $this->sweep(new Position(8, 8));
        $saved = $this->keeper()->markPosition($this->character, new Position(8, 8))->bookmark;

        $stranger = Character::factory()->create(['archetype' => 3]);

        $this->assertSame(BookmarkRefusal::NotYours, $this->keeper()->forget($stranger, $saved)->refusal);
        $this->assertSame(BookmarkRefusal::NotYours, $this->keeper()->rename($stranger, $saved, 'mine now')->refusal);
        $this->assertSame(1, Bookmark::query()->count());
    }

    public function test_deleting_a_character_takes_their_bookmarks(): void
    {
        $this->sweep(new Position(8, 8));
        $this->keeper()->markPosition($this->character, new Position(8, 8));

        $this->character->delete();

        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_every_refusal_has_a_string(): void
    {
        foreach (BookmarkRefusal::cases() as $refusal) {
            $this->assertNotSame(
                "cyberspace.bookmarks.refused.{$refusal->value}",
                $refusal->message(),
                "BookmarkRefusal::{$refusal->name} has no string",
            );
        }

        foreach (BookmarkKind::cases() as $kind) {
            $this->assertNotSame("cyberspace.bookmarks.kind.{$kind->value}", $kind->label());
        }
    }
}
