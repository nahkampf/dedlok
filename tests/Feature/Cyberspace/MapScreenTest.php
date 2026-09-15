<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BookmarkKeeper;
use App\Cyberspace\BookmarkKind;
use App\Cyberspace\Grid;
use App\Cyberspace\MapView;
use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use App\Cyberspace\Terrain;
use App\Decks\DeckEquipper;
use App\Decks\Hardware;
use App\Decks\ModuleFitter;
use App\Decks\Variant;
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Models\User;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The map screen: the way in, the walk, and the way back.
 *
 * The one thing worth stating up front is that THE MAP IS A MEMORY. It is
 * drawn from what this character has swept and last saw, never from `systems`
 * — so a machine standing on ground they have not looked at must not appear,
 * and one that has moved since must still appear where they last saw it.
 */
final class MapScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);
        Config::set('cyberspace.map_range', 4);

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));
    }

    private function portal(): Position
    {
        return Grid::fromConfig()->portal();
    }

    private function own(Item $item): InventoryItem
    {
        return InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
    }

    private function equipReadyDeck(): void
    {
        $deckItem = Item::factory()->cyberdeck([
            'cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1,
        ])->create();

        $deck = $this->own($deckItem);
        $fitter = app(ModuleFitter::class);

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 100],
            ['ram', Hardware::Ram, Variant::Opto, 100],
            ['nic', Hardware::Nic, null, 50],
            ['psu', Hardware::Psu, null, 2000],
        ] as [$kind, $hardware, $variant, $measure]) {
            $fitter->fit(
                $this->character,
                $deck,
                $deckItem->deckSlots()->where('kind', $kind)->firstOrFail(),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: 10)->create()),
            );
        }

        app(DeckEquipper::class)->equip($this->character, $deck);
        $this->character->refresh();
    }

    // ---- the screen --------------------------------------------------------

    public function test_the_map_needs_a_signed_in_character(): void
    {
        $this->get(route('cyberspace.index'))->assertRedirect(route('login'));
    }

    /**
     * The way IN is on the map, so the map has to exist before you are in it.
     * A screen that appeared only once you were already inside would be one
     * nobody could find.
     */
    public function test_the_map_opens_when_you_are_not_jacked_in(): void
    {
        $this->actingAs($this->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee(Text::get('cyberspace.map.out'))
            ->assertSee(Text::get('cyberspace.map.jack_in'));
    }

    public function test_jacking_in_from_the_screen_puts_you_at_the_portal(): void
    {
        $this->equipReadyDeck();

        $this->actingAs($this->user)
            ->post(route('cyberspace.jack_in'))
            ->assertRedirect();

        $this->assertTrue($this->character->fresh()->gridPosition()->equals($this->portal()));
    }

    public function test_an_unready_deck_is_refused_with_a_sentence(): void
    {
        $this->actingAs($this->user)
            ->post(route('cyberspace.jack_in'))
            ->assertSessionHas('error');

        $this->assertFalse($this->character->fresh()->isJackedIn());
    }

    public function test_stepping_from_the_screen_moves_one_cell(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $to = new Position($this->portal()->x + 1, $this->portal()->y + 1);

        $this->actingAs($this->user)
            ->post(route('cyberspace.step'), ['to' => "{$to->x},{$to->y}"])
            ->assertRedirect();

        $this->assertTrue($this->character->fresh()->gridPosition()->equals($to));
    }

    public function test_a_malformed_step_target_is_rejected_by_validation(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $this->actingAs($this->user)
            ->post(route('cyberspace.step'), ['to' => 'over there'])
            ->assertSessionHasErrors('to');
    }

    public function test_jumping_to_an_address_from_the_screen(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        System::factory()->at(2, 13)->create(['address' => 'DEADBEEF']);

        $this->actingAs($this->user)
            ->post(route('cyberspace.jump'), ['address' => 'deadbeef'])
            ->assertRedirect();

        $this->assertTrue($this->character->fresh()->gridPosition()->equals(new Position(2, 13)));
    }

    // ---- the map is a memory ----------------------------------------------

    /** A machine on ground nobody has looked at is not on anybody's map. */
    public function test_an_unswept_system_does_not_appear(): void
    {
        System::factory()->at(1, 1)->create(['address' => 'AAAAAAAA', 'name' => 'Hidden Vault']);

        $this->actingAs($this->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertDontSee('Hidden Vault');
    }

    /**
     * And the mirror: one that has MOVED since you looked is still drawn where
     * you last saw it, because the map is your memory rather than a feed.
     */
    public function test_a_system_that_moved_is_still_drawn_where_it_was_seen(): void
    {
        $this->equipReadyDeck();
        $system = System::factory()
            ->at($this->portal()->x + 1, $this->portal()->y)
            ->create(['address' => 'AAAAAAAA', 'name' => 'Cold Vault 041']);

        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));
        $this->actingAs($this->user)->get(route('cyberspace.index'))->assertSee('Cold Vault 041');

        // It relocates far away, out of view.
        $system->update(['x' => 1, 'y' => 1, 'address' => 'BBBBBBBB']);

        $this->actingAs($this->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSee('Cold Vault 041');
    }

    // ---- bookmarks on the screen ------------------------------------------

    public function test_marking_the_position_you_are_standing_on(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $this->actingAs($this->user)
            ->post(route('cyberspace.bookmark'), [
                'kind' => BookmarkKind::Waypoint->value,
                'at' => "{$this->portal()->x},{$this->portal()->y}",
                'label' => 'Home',
            ])
            ->assertSessionHas('status');

        $this->assertSame('Home', Bookmark::query()->sole()->label);
    }

    /** An address may be saved without ever having been there. */
    public function test_saving_an_address_needs_no_visit(): void
    {
        $this->actingAs($this->user)
            ->post(route('cyberspace.bookmark'), [
                'kind' => BookmarkKind::System->value,
                'address' => 'DEADBEEF',
            ])
            ->assertSessionHas('status');

        $this->assertSame('DEADBEEF', Bookmark::query()->sole()->address);
        $this->assertSame(0, (new Terrain($this->character))->sweptCount());
    }

    public function test_a_waypoint_on_ground_never_swept_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('cyberspace.bookmark'), [
                'kind' => BookmarkKind::Waypoint->value,
                'at' => '1,1',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_recalling_and_forgetting_from_the_screen(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $mark = BookmarkKeeper::fromConfig()
            ->markPosition($this->character->fresh(), $this->portal(), 'Home')->bookmark;

        $away = new Position($this->portal()->x + 1, $this->portal()->y);
        $this->actingAs($this->user)->post(route('cyberspace.step'), ['to' => "{$away->x},{$away->y}"]);

        $this->actingAs($this->user)->post(route('cyberspace.recall', $mark))->assertRedirect();
        $this->assertTrue($this->character->fresh()->gridPosition()->equals($this->portal()));

        $this->actingAs($this->user)->delete(route('cyberspace.forget', $mark))->assertSessionHas('status');
        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_you_cannot_touch_another_characters_bookmark(): void
    {
        $stranger = Character::factory()->create(['archetype' => 3]);
        $mark = Bookmark::query()->create([
            'character_id' => $stranger->id,
            'kind' => BookmarkKind::System,
            'address' => 'DEADBEEF',
        ]);

        $this->actingAs($this->user)
            ->delete(route('cyberspace.forget', $mark))
            ->assertSessionHas('error');

        $this->assertSame(1, Bookmark::query()->count());
    }

    // ---- the view model ----------------------------------------------------

    public function test_the_window_is_square_and_centred_on_the_player(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $map = MapView::for($this->character->fresh());

        $this->assertSame(9, $map->span(), 'a range of 4 is a 9 x 9 window');
        $this->assertCount(9, $map->rows);
        $this->assertCount(9, $map->rows[0]);
        $this->assertTrue($map->centre->equals($this->portal()));
        $this->assertTrue($map->jackedIn);
    }

    /** Out of cyberspace the map centres on the portal and nothing steps. */
    public function test_the_window_centres_on_the_portal_when_you_are_out(): void
    {
        $map = MapView::for($this->character);

        $this->assertFalse($map->jackedIn);
        $this->assertTrue($map->centre->equals($this->portal()));

        foreach ($map->rows as $row) {
            foreach ($row as $cell) {
                $this->assertFalse($cell->isStep);
                $this->assertFalse($cell->isYou);
            }
        }
    }

    /**
     * Exactly eight steps from open ground, and every one of them adjacent.
     * These are the eight the screen turns into buttons.
     */
    public function test_exactly_eight_cells_are_steppable(): void
    {
        $this->equipReadyDeck();
        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $map = MapView::for($this->character->fresh());
        $steps = [];

        foreach ($map->rows as $row) {
            foreach ($row as $cell) {
                if ($cell->isStep) {
                    $steps[] = $cell->position;
                }
            }
        }

        $this->assertCount(8, $steps);

        foreach ($steps as $step) {
            $this->assertTrue($this->portal()->isAdjacentTo($step));
        }
    }

    /** Beyond the edge is a hole in the drawing, not a place. */
    public function test_cells_beyond_the_grid_are_void(): void
    {
        $this->character->forceFill(['grid_x' => 0, 'grid_y' => 0])->save();

        $map = MapView::for($this->character->fresh());
        $void = 0;

        foreach ($map->rows as $row) {
            foreach ($row as $cell) {
                if (! $cell->onGrid) {
                    $this->assertSame('cell--void', $cell->modifier());
                    $this->assertFalse($cell->swept);
                    $this->assertFalse($cell->isStep);
                    $void++;
                }
            }
        }

        $this->assertGreaterThan(0, $void);
    }

    public function test_a_portal_cell_is_drawn_as_the_portal(): void
    {
        $this->equipReadyDeck();
        System::factory()->at($this->portal()->x, $this->portal()->y)
            ->type(SystemType::Portal)->create(['address' => 'AAAAAAAA', 'name' => 'Cyberspace Portal']);

        $this->actingAs($this->user)->post(route('cyberspace.jack_in'));

        $map = MapView::for($this->character->fresh());
        $centre = $map->rows[$map->range][$map->range];

        $this->assertTrue($centre->isYou);
        $this->assertSame('cell--you', $centre->modifier(), 'you outrank what you are standing on');
    }
    // ---- what is lit, and what is only remembered ---------------------------

    /**
     * THE MAP DRAWS MEMORY AND THE SWEEP LIGHTS PART OF IT. Everything outside
     * what they can see from where they stand steps back, so the square they
     * are actually looking at is obvious without anything being hidden.
     */
    public function test_ground_outside_the_view_range_is_dimmed(): void
    {
        Config::set('cyberspace.view_range', 2);
        Config::set('cyberspace.map_range', 5);

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $map = MapView::for($this->character->fresh());

        $inView = [];
        $distant = [];

        foreach ($map->rows as $row) {
            foreach ($row as $cell) {
                $cell->inView ? $inView[] = $cell : $distant[] = $cell;
            }
        }

        // A view range of 2 is a 5x5 square: twenty-five cells lit out of the
        // eleven-by-eleven drawn.
        $this->assertCount(25, $inView);
        $this->assertNotEmpty($distant);

        foreach ($distant as $cell) {
            $this->assertStringContainsString('cell--distant', $cell->classes());
        }

        foreach ($inView as $cell) {
            $this->assertStringNotContainsString('cell--distant', $cell->classes());
        }
    }

    /** Nothing is lit for somebody reading the map from outside cyberspace. */
    public function test_nothing_is_lit_while_jacked_out(): void
    {
        $map = MapView::for($this->character->fresh());

        foreach ($map->rows as $row) {
            foreach ($row as $cell) {
                $this->assertFalse($cell->inView);
            }
        }
    }

    /** The drawn window is capped at the furthest anybody will ever see. */
    public function test_the_window_is_the_configured_map_range(): void
    {
        // The shipped cap is view_range 2 plus the +5 the modifiers are worth.
        // Set here rather than read, because this class narrows the map in
        // setUp — reading it would assert the fixture, not the rule.
        Config::set('cyberspace.map_range', 7);

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $map = MapView::for($this->character->fresh());

        $this->assertSame(15, $map->span());
        $this->assertCount(15, $map->rows);
    }
}
