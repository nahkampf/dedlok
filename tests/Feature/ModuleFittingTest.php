<?php

namespace Tests\Feature;

use App\Decks\DeckEquipper;
use App\Decks\DeckRequirement;
use App\Decks\DeckTotals;
use App\Decks\FittingRefusal;
use App\Decks\Hardware;
use App\Decks\HardwareEditor;
use App\Decks\ModuleFitter;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Decks\ThermalExposure;
use App\Decks\ThermalVerdict;
use App\Decks\Variant;
use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\DeckSlot;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Stats\Modifier;
use App\Stats\Op;
use App\Stats\Resolver;
use App\Stats\Target;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putting a module into a deck, and taking it back out.
 *
 * The row `deck_slots` was built for. What is asserted here is mostly that a
 * socket is a PLACE rather than a capacity: it takes one particular kind of
 * thing, one at a time, on one particular copy of the deck.
 */
class ModuleFittingTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private ModuleFitter $fitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = $this->character();
        $this->fitter = new ModuleFitter;
    }

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    private function deckItem(array $counts = ['cpu_socket_k' => 1, 'ram_opto' => 2, 'psu' => 1]): Item
    {
        return Item::factory()->cyberdeck($counts)->create();
    }

    private function own(Item $item, ?Character $to = null): InventoryItem
    {
        return InventoryItem::factory()->ownedBy($to ?? $this->character)->of($item)->create();
    }

    private function socket(Item $deck, string $kind): DeckSlot
    {
        return $deck->deckSlots()->where('kind', $kind)->firstOrFail();
    }

    // ---- fitting -----------------------------------------------------------

    public function test_a_module_goes_into_a_socket_that_takes_it(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34)->create());

        $result = $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick);

        $this->assertTrue($result->succeeded());
        $this->assertSame($stick->id, $result->fitting->module_id);
        $this->assertTrue($stick->fresh()->isFitted());
    }

    public function test_a_fitted_module_leaves_the_stash(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->assertTrue(
            $this->character->inventoryIn(Item::CONTAINER_PHYSICAL)->contains('id', $stick->id)
        );

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick);

        // Not in a container and not to hand either. loose() is the one place
        // that decides what "in your inventory" means, so nothing else had to
        // learn about fittings.
        $this->assertFalse(
            $this->character->inventoryIn(Item::CONTAINER_PHYSICAL)->contains('id', $stick->id)
        );
    }

    public function test_a_socket_takes_only_what_it_is_for(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $psu = $this->own(Item::factory()->module(Hardware::Psu, measure: 600)->create());

        $result = $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $psu);

        $this->assertSame(FittingRefusal::WrongSocket, $result->refusal);
    }

    public function test_a_variant_has_to_match_not_merely_the_kind(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_qdram' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);
        $opto = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->assertSame(
            FittingRefusal::WrongSocket,
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $opto)->refusal
        );
    }

    public function test_a_universal_socket_takes_either_stick(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_universal' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);
        $qdram = $this->own(Item::factory()->module(Hardware::Ram, Variant::QDram)->create());

        // universal is a socket and never a module — Variant::accepts(), not ===.
        $this->assertTrue(
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $qdram)->succeeded()
        );
    }

    public function test_one_module_per_socket(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'ram');
        $stickItem = Item::factory()->module(Hardware::Ram, Variant::Opto)->create();

        $this->fitter->fit($this->character, $deck, $socket, $this->own($stickItem));

        $this->assertSame(
            FittingRefusal::SocketOccupied,
            $this->fitter->fit($this->character, $deck, $socket, $this->own($stickItem))->refusal
        );
    }

    public function test_one_socket_per_module(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $sockets = $deckItem->deckSlots()->where('kind', 'ram')->get();

        $this->fitter->fit($this->character, $deck, $sockets[0], $stick);

        $this->assertSame(
            FittingRefusal::AlreadyFitted,
            $this->fitter->fit($this->character, $deck, $sockets[1], $stick)->refusal
        );
    }

    public function test_two_copies_of_a_deck_have_their_own_sockets(): void
    {
        $deckItem = $this->deckItem();
        $mine = $this->own($deckItem);
        $spare = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'ram');
        $stickItem = Item::factory()->module(Hardware::Ram, Variant::Opto)->create();

        // The same deck_slots row, two different objects. It is the PAIR that
        // is a place in the world, which is why the unique key is on both.
        $this->assertTrue($this->fitter->fit($this->character, $mine, $socket, $this->own($stickItem))->succeeded());
        $this->assertTrue($this->fitter->fit($this->character, $spare, $socket, $this->own($stickItem))->succeeded());
    }

    public function test_a_socket_from_another_deck_is_refused(): void
    {
        $mine = $this->own($this->deckItem());
        $other = $this->deckItem(['cpu_socket_z' => 1, 'ram_qdram' => 1, 'psu' => 1]);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::QDram)->create());

        $this->assertSame(
            FittingRefusal::WrongDeck,
            $this->fitter->fit($this->character, $mine, $this->socket($other, 'ram'), $stick)->refusal
        );
    }

    public function test_a_deck_is_not_a_module_and_a_rifle_is_not_either(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'ram');

        $this->assertSame(
            FittingRefusal::NotAModule,
            $this->fitter->fit($this->character, $deck, $socket, $this->own($this->deckItem()))->refusal
        );

        $this->assertSame(
            FittingRefusal::NotAModule,
            $this->fitter->fit($this->character, $deck, $socket, $this->own(Item::factory()->physical()->create()))->refusal
        );
    }

    public function test_you_cannot_fit_into_something_that_is_not_a_deck(): void
    {
        $deckItem = $this->deckItem();
        $rifle = $this->own(Item::factory()->physical()->create());
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->assertSame(
            FittingRefusal::NotADeck,
            $this->fitter->fit($this->character, $rifle, $this->socket($deckItem, 'ram'), $stick)->refusal
        );
    }

    public function test_someone_elses_deck_or_module_is_refused(): void
    {
        $deckItem = $this->deckItem();
        $theirs = $this->character();
        $socket = $this->socket($deckItem, 'ram');
        $stickItem = Item::factory()->module(Hardware::Ram, Variant::Opto)->create();

        $this->assertSame(
            FittingRefusal::NotOwned,
            $this->fitter->fit($this->character, $this->own($deckItem, $theirs), $socket, $this->own($stickItem))->refusal
        );

        $this->assertSame(
            FittingRefusal::NotOwned,
            $this->fitter->fit($this->character, $this->own($deckItem), $socket, $this->own($stickItem, $theirs))->refusal
        );
    }

    public function test_a_module_in_a_bag_has_to_come_out_first(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $case = $this->own(Item::factory()->physical()->holds(Item::CONTAINER_PHYSICAL, 40)->create());

        $stick = InventoryItem::factory()
            ->ownedBy($this->character)
            ->of(Item::factory()->module(Hardware::Ram, Variant::Opto)->create())
            ->inside($case)
            ->create();

        // Refused rather than quietly ejected: a screen that moves your
        // possessions without saying so is a screen you cannot trust.
        $this->assertSame(
            FittingRefusal::Stowed,
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick)->refusal
        );
    }

    // ---- taking it out -----------------------------------------------------

    public function test_taking_a_module_out_puts_it_back_in_the_stash(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'ram');
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->fitter->fit($this->character, $deck, $socket, $stick);

        $this->assertTrue($this->fitter->unfit($this->character, $deck, $socket)->succeeded());

        $this->assertFalse($stick->fresh()->isFitted());
        $this->assertTrue(
            $this->character->inventoryIn(Item::CONTAINER_PHYSICAL)->contains('id', $stick->id)
        );

        // The module survives; only the fitting is gone.
        $this->assertNotNull($stick->fresh());
        $this->assertSame(0, DeckFitting::count());
    }

    public function test_an_empty_socket_has_nothing_to_take_out(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);

        $this->assertSame(
            FittingRefusal::NotFitted,
            $this->fitter->unfit($this->character, $deck, $this->socket($deckItem, 'ram'))->refusal
        );
    }

    public function test_destroying_the_deck_frees_the_modules_rather_than_eating_them(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick);

        $deck->delete();

        // Deliberately unlike a memory shard, which destroys what is written
        // on it. Stripping a deck for parts is what anyone would do.
        $this->assertNotNull($stick->fresh());
        $this->assertFalse($stick->fresh()->isFitted());
        $this->assertSame(0, DeckFitting::count());
    }

    // ---- stripping ---------------------------------------------------------

    /** A deck with a CPU, a stick of RAM and a supply in it. */
    private function loadedDeck(): array
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);

        $modules = [];

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 165],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['psu', Hardware::Psu, null, 300, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            $row = $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw)->create());
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind), $row);
            $modules[] = $row;
        }

        return [$deck, $modules];
    }

    public function test_stripping_empties_every_socket_at_once(): void
    {
        [$deck, $modules] = $this->loadedDeck();

        $result = $this->fitter->strip($this->character, $deck);

        $this->assertTrue($result->succeeded());
        $this->assertSame(3, $result->removed);
        $this->assertSame(0, DeckFitting::count());
    }

    public function test_stripping_destroys_nothing(): void
    {
        [$deck, $modules] = $this->loadedDeck();

        $this->fitter->strip($this->character, $deck);

        $carried = $this->character->inventoryIn(Item::CONTAINER_PHYSICAL);

        foreach ($modules as $module) {
            $this->assertNotNull($module->fresh());
            $this->assertFalse($module->fresh()->isFitted());
            // Back to hand, which is the whole of what taking one out means.
            $this->assertTrue($carried->contains('id', $module->id));
        }
    }

    public function test_stripping_an_empty_deck_says_so(): void
    {
        $this->assertSame(
            FittingRefusal::NotFitted,
            $this->fitter->strip($this->character, $this->own($this->deckItem()))->refusal
        );
    }

    public function test_stripping_touches_only_the_deck_asked_about(): void
    {
        [$mine] = $this->loadedDeck();
        [$other] = $this->loadedDeck();

        $this->fitter->strip($this->character, $mine);

        $this->assertSame(0, $mine->fresh()->fittings()->count());
        $this->assertSame(3, $other->fresh()->fittings()->count());
    }

    public function test_stripping_over_http(): void
    {
        [$deck] = $this->loadedDeck();

        $this->actingAs($this->character->user)
            ->post(route('deck.strip', $deck))
            ->assertRedirect();

        $this->assertSame(0, DeckFitting::count());
    }

    public function test_stripping_someone_elses_deck_is_a_404(): void
    {
        $theirs = $this->own($this->deckItem(), $this->character());

        $this->actingAs($this->character->user)
            ->post(route('deck.strip', $theirs))
            ->assertNotFound();
    }

    // ---- which deck is live ------------------------------------------------

    public function test_a_character_starts_jacked_out(): void
    {
        // NULL is a real state and the ordinary one, not missing data.
        $this->assertNull($this->character->equipped_deck_id);
    }

    public function test_equipping_makes_it_the_live_deck(): void
    {
        $deck = $this->own($this->deckItem());

        $this->assertTrue((new DeckEquipper)->equip($this->character, $deck)->succeeded());

        $this->assertTrue($this->character->fresh()->hasEquipped($deck));
    }

    public function test_equipping_another_deck_swaps_rather_than_refusing(): void
    {
        $equipper = new DeckEquipper;
        $first = $this->own($this->deckItem());
        $second = $this->own($this->deckItem());

        $equipper->equip($this->character, $first);
        $equipper->equip($this->character, $second);

        // One column holds one value, so "only one at a time" is not a rule
        // anybody has to enforce.
        $this->assertTrue($this->character->fresh()->hasEquipped($second));
        $this->assertFalse($this->character->fresh()->hasEquipped($first));
    }

    public function test_only_a_cyberdeck_you_own_can_be_equipped(): void
    {
        $equipper = new DeckEquipper;

        $this->assertSame(
            FittingRefusal::NotADeck,
            $equipper->equip($this->character, $this->own(Item::factory()->physical()->create()))->refusal
        );

        $this->assertSame(
            FittingRefusal::NotOwned,
            $equipper->equip($this->character, $this->own($this->deckItem(), $this->character()))->refusal
        );
    }

    public function test_jacking_out_leaves_the_deck_built_by_default(): void
    {
        [$deck] = $this->loadedDeck();
        $equipper = new DeckEquipper;
        $equipper->equip($this->character, $deck);

        $result = $equipper->unequip($this->character->fresh(), strip: false, fitter: $this->fitter);

        $this->assertTrue($result->succeeded());
        $this->assertSame(0, $result->removed);
        $this->assertNull($this->character->fresh()->equipped_deck_id);

        // Still built. Leaving it is what you do when you mean to come back.
        $this->assertSame(3, $deck->fresh()->fittings()->count());
    }

    public function test_jacking_out_can_take_the_modules_with_you(): void
    {
        [$deck] = $this->loadedDeck();
        $equipper = new DeckEquipper;
        $equipper->equip($this->character, $deck);

        $result = $equipper->unequip($this->character->fresh(), strip: true, fitter: $this->fitter);

        $this->assertSame(3, $result->removed);
        $this->assertNull($this->character->fresh()->equipped_deck_id);
        $this->assertSame(0, $deck->fresh()->fittings()->count());
    }

    public function test_stripping_an_empty_deck_still_jacks_you_out(): void
    {
        $deck = $this->own($this->deckItem());
        $equipper = new DeckEquipper;
        $equipper->equip($this->character, $deck);

        // An empty deck refuses a strip, and that is not a reason to refuse
        // the jack-out the player actually asked for.
        $result = $equipper->unequip($this->character->fresh(), strip: true, fitter: $this->fitter);

        $this->assertTrue($result->succeeded());
        $this->assertNull($this->character->fresh()->equipped_deck_id);
    }

    public function test_jacking_out_when_nothing_is_in_says_so(): void
    {
        $this->assertSame(
            FittingRefusal::NotEquipped,
            (new DeckEquipper)->unequip($this->character, strip: false, fitter: $this->fitter)->refusal
        );
    }

    public function test_destroying_the_equipped_deck_jacks_you_out(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);

        $deck->delete();

        // nullOnDelete: losing the deck jacks you out, it does not delete you.
        $this->assertNotNull($this->character->fresh());
        $this->assertNull($this->character->fresh()->equipped_deck_id);
    }

    public function test_equipping_and_jacking_out_through_the_screen(): void
    {
        [$deck] = $this->loadedDeck();

        $this->actingAs($this->character->user)
            ->post(route('deck.equip', $deck))
            ->assertRedirect();

        $this->assertTrue($this->character->fresh()->hasEquipped($deck));

        $this->actingAs($this->character->user)
            ->post(route('deck.unequip'), ['strip' => '1'])
            ->assertRedirect();

        $this->assertNull($this->character->fresh()->equipped_deck_id);
        $this->assertSame(0, DeckFitting::count());
    }

    public function test_the_rail_resolves_which_deck_you_mean(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);

        $this->actingAs($this->character->user)
            ->get(route('deck.index'))
            ->assertRedirect(route('deck.show', $deck));
    }

    public function test_the_rail_says_so_when_there_is_no_deck_to_go_to(): void
    {
        // Sent to the stash, which is where a deck gets equipped — rather
        // than to a screen that cannot exist, or a menu item that vanished.
        $this->actingAs($this->character->user)
            ->get(route('deck.index'))
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('error', FittingRefusal::NotEquipped->message());
    }

    public function test_the_screen_offers_the_way_in_and_the_way_out(): void
    {
        [$deck] = $this->loadedDeck();

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee(Text::get('decks.equip.equip'))
            ->assertSee(Text::get('decks.fitting.strip'))
            ->assertDontSee('id="unequip"', false);

        (new DeckEquipper)->equip($this->character, $deck);

        // Equipped, the button becomes the modal that asks what to do with the
        // modules — two right answers, so it asks rather than assumes.
        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee('id="unequip"', false)
            ->assertSee(Text::get('decks.equip.leave'))
            ->assertSee(Text::get('decks.equip.take'));
    }

    /**
     * A carried deck is still a thing you have, so it stays in the stash and
     * wears its tag there. Being equipped is NOT one of scopeLoose()'s ways of
     * being spoken for — it used to be, and taking it out of the grid meant the
     * stash needed a panel whose only job was to be the route back to it.
     */
    public function test_an_equipped_deck_stays_in_the_stash(): void
    {
        $deck = $this->own($this->deckItem());
        $equipper = new DeckEquipper;

        $carried = fn () => $this->character->fresh()->inventoryIn(Item::CONTAINER_PHYSICAL);

        $this->assertTrue($carried()->contains('id', $deck->id));

        $equipper->equip($this->character, $deck);
        $this->assertTrue($carried()->contains('id', $deck->id), 'it is on you, not elsewhere');

        $equipper->unequip($this->character->fresh(), strip: false, fitter: $this->fitter);
        $this->assertTrue($carried()->contains('id', $deck->id));
    }

    /** One listing, and the slot's own card is the route to the board. */
    public function test_the_stash_lists_an_equipped_deck_like_anything_else(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee(route('deck.show', $deck), false)
            ->assertDontSee(Text::get('inventory.in_use'))
            // Its card is what says it is the one being carried.
            ->assertSee(Text::get('decks.equip.is_equipped'));
    }

    public function test_a_deck_stays_equipped_while_its_modules_change(): void
    {
        [$deck] = $this->loadedDeck();
        (new DeckEquipper)->equip($this->character, $deck);

        $this->fitter->strip($this->character, $deck);

        // Stripping is about the modules; equipping is about the deck. The
        // one does not imply the other in either direction.
        $this->assertTrue($this->character->fresh()->hasEquipped($deck));
    }

    // ---- the sockets moving underneath -------------------------------------

    public function test_editing_an_unrelated_field_keeps_every_fitting(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick);

        // HardwareEditor used to replace sockets wholesale, which would have
        // emptied every player's deck on any save. It diffs now.
        HardwareEditor::apply($deckItem, Hardware::Deck, $deckItem->fresh()->slotLayout());

        $this->assertTrue($stick->fresh()->isFitted());
    }

    public function test_adding_a_socket_keeps_the_ones_already_filled(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick);

        HardwareEditor::apply($deckItem, Hardware::Deck, SlotLayout::fromCounts([
            'cpu_socket_k' => 1, 'ram_opto' => 4, 'psu' => 1, 'nic' => 1,
        ]));

        $this->assertTrue($stick->fresh()->isFitted());
        $this->assertSame(7, $deckItem->fresh()->deckSlots()->count());

        // And the positions are canonical again, with no gaps.
        $this->assertSame(
            range(1, 7),
            $deckItem->fresh()->deckSlots()->pluck('position')->all()
        );
    }

    public function test_removing_a_socket_drops_an_empty_one_before_a_filled_one(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        // Two RAM sockets, one occupied. Cutting back to one must keep the
        // one somebody is using.
        $this->fitter->fit($this->character, $deck, $deckItem->deckSlots()->where('kind', 'ram')->get()[1], $stick);

        HardwareEditor::apply($deckItem, Hardware::Deck, SlotLayout::fromCounts([
            'cpu_socket_k' => 1, 'ram_opto' => 1, 'psu' => 1,
        ]));

        $this->assertTrue($stick->fresh()->isFitted());
        $this->assertSame(1, $deckItem->fresh()->deckSlots()->where('kind', 'ram')->count());
    }

    public function test_removing_an_occupied_socket_returns_the_module(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'ram'), $stick);

        HardwareEditor::apply($deckItem, Hardware::Deck, SlotLayout::fromCounts([
            'cpu_socket_k' => 1, 'psu' => 1,
        ]));

        // The fitting cascades with the socket; the module comes back to hand
        // rather than being destroyed with it.
        $this->assertNotNull($stick->fresh());
        $this->assertFalse($stick->fresh()->isFitted());
        $this->assertTrue(
            $this->character->inventoryIn(Item::CONTAINER_PHYSICAL)->contains('id', $stick->id)
        );
    }

    // ---- what it adds up to ------------------------------------------------

    /** Everything a deck needs to run, so a test can take one thing away. */
    private function readyDeck(): array
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 165],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['nic', Hardware::Nic, null, 2, 18],
            ['psu', Hardware::Psu, null, 300, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            $this->fitter->fit(
                $this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw)->create()),
            );
        }

        return [$deckItem, $deck];
    }

    private function totals(InventoryItem $deck): DeckTotals
    {
        return DeckTotals::of($deck->fresh()->fittings()->with('module.item.deckModule')->get());
    }

    public function test_a_deck_totals_every_measure_it_holds(): void
    {
        [, $deck] = $this->readyDeck();

        $totals = $this->totals($deck);

        $this->assertSame(11, $totals->qflops);
        $this->assertSame(16, $totals->quantabits);
        $this->assertSame(2, $totals->bandwidth);
        $this->assertSame(217, $totals->draw);
        $this->assertSame(300, $totals->supply);
    }

    public function test_an_npu_adds_to_the_same_compute_figure_as_a_cpu(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'npu' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'cpu'),
            $this->own(Item::factory()->module(Hardware::Cpu, Variant::SocketK, measure: 11, draw: 165)->create()));
        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'npu'),
            $this->own(Item::factory()->module(Hardware::Npu, null, measure: 7, draw: 40)->create()));

        // Labelled by the measure, not the socket: a deck with one of each has
        // one compute figure rather than two halves of one.
        $this->assertSame(18, $this->totals($deck)->qflops);
    }

    public function test_a_deck_with_everything_is_ready(): void
    {
        [, $deck] = $this->readyDeck();

        $totals = $this->totals($deck);

        $this->assertTrue($totals->isReady());
        $this->assertSame([], $totals->unmet());
    }

    public function test_an_empty_deck_names_everything_it_still_needs(): void
    {
        $totals = $this->totals($this->own($this->deckItem()));

        $this->assertFalse($totals->isReady());
        $this->assertSame(
            [
                DeckRequirement::Compute,
                DeckRequirement::Memory,
                DeckRequirement::Link,
                DeckRequirement::Power,
            ],
            $totals->unmet(),
        );

        // Not the budget: nothing draws anything, so nothing is over.
        $this->assertNotContains(DeckRequirement::Budget, $totals->unmet());
    }

    public function test_an_npu_satisfies_the_compute_requirement_on_its_own(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'npu' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        foreach ([
            ['npu', Hardware::Npu, null, 7, 40],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['nic', Hardware::Nic, null, 2, 18],
            ['psu', Hardware::Psu, null, 300, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw)->create()));
        }

        // The CPU socket is empty and that is fine — either seats the thinking.
        $this->assertTrue($this->totals($deck)->isReady());
    }

    public function test_each_missing_part_is_named_on_its_own(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        // Everything but the NIC.
        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 165],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['psu', Hardware::Psu, null, 300, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw)->create()));
        }

        $this->assertSame([DeckRequirement::Link], $this->totals($deck)->unmet());
    }

    public function test_a_deck_drawing_more_than_it_makes_is_not_ready(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 900],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['nic', Hardware::Nic, null, 2, 18],
            ['psu', Hardware::Psu, null, 300, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            // Still allowed. Over-commitment is a design tension, not a row to
            // correct, so it is reported here and refused nowhere.
            $this->assertTrue($this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw)->create()))->succeeded());
        }

        $totals = $this->totals($deck);

        $this->assertTrue($totals->isOver());
        $this->assertSame([DeckRequirement::Budget], $totals->unmet());
    }

    // ---- heat ---------------------------------------------------------------

    public function test_a_deck_runs_at_what_it_makes_less_what_is_taken_away(): void
    {
        $deckItem = Item::factory()->cyberdeck([
            'cpu_socket_k' => 1, 'psu' => 1, 'thermal' => 1,
        ])->create();
        $deck = $this->own($deckItem);

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'cpu'),
            $this->own(Item::factory()->module(Hardware::Cpu, Variant::SocketK, measure: 11, draw: 165, thermalLoad: 90)->create()));
        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'psu'),
            $this->own(Item::factory()->module(Hardware::Psu, measure: 300, thermalLoad: 10)->create()));

        $totals = $this->totals($deck);

        $this->assertSame(100, $totals->thermalLoad);
        $this->assertSame(0, $totals->cooling);
        $this->assertSame(100, $totals->netThermalLoad());

        // A radiator in the exhaust takes it back down.
        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'thermal'),
            $this->own(Item::factory()->module(Hardware::Thermal, measure: 40, draw: 5)->create()));

        $cooled = $this->totals($deck);

        $this->assertSame(40, $cooled->cooling);
        $this->assertSame(60, $cooled->netThermalLoad());
    }

    public function test_a_radiator_cools_and_heats_none(): void
    {
        // The mirror of a supply giving power and drawing none, and the
        // schema's chk_deck_modules_thermal says so too.
        $radiator = Item::factory()->module(Hardware::Thermal, measure: 40, draw: 5, thermalLoad: 999)->create();

        $this->assertSame(0, $radiator->deckModule->thermal_load);
        $this->assertSame(40, $radiator->deckModule->cooling);
        // It still draws: the fan runs on something.
        $this->assertSame(5, $radiator->deckModule->power_draw);
    }

    public function test_cooling_past_zero_is_a_cold_deck_not_a_negative_one(): void
    {
        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1, 'thermal' => 1])
            ->create();
        $deck = $this->own($deckItem);

        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'cpu'),
            $this->own(Item::factory()->module(Hardware::Cpu, Variant::SocketK, measure: 11, draw: 165, thermalLoad: 10)->create()));
        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'thermal'),
            $this->own(Item::factory()->module(Hardware::Thermal, measure: 500, draw: 5)->create()));

        $this->assertSame(0, $this->totals($deck)->netThermalLoad());
    }

    public function test_heat_never_refuses_a_fitting_and_never_blocks_readiness(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 165, 200],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34, 10],
            ['nic', Hardware::Nic, null, 2, 18, 5],
            ['psu', Hardware::Psu, null, 600, 0, 20],
        ] as [$kind, $hardware, $variant, $measure, $draw, $thermalLoad]) {
            // Nothing refuses a fitting for being hot. That is the player's
            // problem to solve, with a radiator or with a colder part.
            $this->assertTrue($this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw, thermalLoad: $thermalLoad)->create()))->succeeded());
        }

        $totals = $this->totals($deck);

        $this->assertSame(235, $totals->netThermalLoad());

        // A deck is indifferent to its own heat. There is nothing on DeckTotals
        // that says otherwise, and readiness never asks about it — what the
        // number means is decided per module, by ThermalExposure.
        $this->assertTrue($totals->isReady());
        $this->assertSame([], $totals->unmet());
    }

    public function test_a_thermal_socket_takes_a_radiator_and_nothing_else(): void
    {
        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1, 'thermal' => 1])->create();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'thermal');

        $this->assertSame(
            FittingRefusal::WrongSocket,
            $this->fitter->fit($this->character, $deck, $socket,
                $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create()))->refusal
        );

        $this->assertTrue($this->fitter->fit($this->character, $deck, $socket,
            $this->own(Item::factory()->module(Hardware::Thermal, measure: 40, draw: 5)->create()))->succeeded());
    }

    public function test_a_deck_may_present_no_thermal_socket_at_all(): void
    {
        // "Decks can have zero, one or more thermal slots" — nothing requires
        // an exhaust, and readiness does not ask for one.
        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1])
            ->create();
        $deck = $this->own($deckItem);

        $this->assertSame(0, $deckItem->slotLayout()->ofKind(SlotKind::Thermal));

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 165],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['nic', Hardware::Nic, null, 2, 18],
            ['psu', Hardware::Psu, null, 600, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw, thermalLoad: 10)->create()));
        }

        $this->assertTrue($this->totals($deck)->isReady());
    }

    public function test_a_skill_that_runs_a_deck_cool_moves_the_heat(): void
    {
        [, $deck, $socket] = $this->deckAt(150, warnAt: 100, dieAt: 200);

        $base = $this->totals($deck);
        $this->assertSame(150, $base->netThermalLoad());
        $this->assertSame(ThermalVerdict::AtRisk, $this->exposure($deck, $base)->verdict($socket->id));

        // Heat is a resolved stat like everything else a deck holds, so the
        // system that will one day carry a cooling-running skill already works
        // — and cooling the deck is what takes the module out of danger.
        $resolved = $base->resolvedWith(
            $this->modifier('deck_thermal_load', Op::PercentageOfBase, -40),
            app(Resolver::class),
        );

        $this->assertSame(90, $resolved->netThermalLoad());
        $this->assertSame(ThermalVerdict::Safe, $this->exposure($deck, $resolved)->verdict($socket->id));
    }

    public function test_the_screen_shows_the_heat_without_a_ceiling_to_judge_it_by(): void
    {
        [, $deck] = $this->deckAt(200, warnAt: null, dieAt: null);

        $response = $this->actingAs($this->character->user)->get(route('deck.show', $deck));

        $response->assertSee(Text::get('decks.ready.thermal'));
        $response->assertSee(Text::get('decks.units.thermal_load', ['value' => '200']));

        // A deck is indifferent to heat, so nothing on the screen compares the
        // figure to a rating — there is no meter beside the power one, and no
        // "running hot" verdict about the deck itself. Only modules can be in
        // trouble, and this one carries no limits at all.
        $response->assertDontSee('decks.thermal.budget');
        $response->assertDontSee(Text::get('decks.thermal.at_risk'));
        $response->assertDontSee(Text::get('decks.thermal.over_limit_code'));
    }

    // ---- what the heat does to each module -----------------------------------

    private function exposure(InventoryItem $deck, DeckTotals $totals): ThermalExposure
    {
        return ThermalExposure::of(
            $totals->netThermalLoad(),
            $deck->fresh()->fittings()->with(['slot', 'module.item.deckModule'])->get(),
            [],
            app(Resolver::class),
        );
    }

    /**
     * A deck at $thermalLoad TU per turn with one module in it that warns and
     * dies where told.
     *
     * @return array{0: Item, 1: InventoryItem, 2: DeckSlot}
     */
    private function deckAt(int $thermalLoad, ?int $warnAt, ?int $dieAt): array
    {
        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1])
            ->create();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'cpu');

        $this->fitter->fit($this->character, $deck, $socket, $this->own(
            Item::factory()->module(
                Hardware::Cpu, Variant::SocketK,
                measure: 11, draw: 165, thermalLoad: $thermalLoad, warnAt: $warnAt, dieAt: $dieAt,
            )->create(['name' => 'Kurogane CX-1'])
        ));

        return [$deckItem, $deck, $socket];
    }

    public function test_a_module_below_its_warning_level_is_left_alone(): void
    {
        [, $deck, $socket] = $this->deckAt(100, warnAt: 150, dieAt: 220);

        $exposure = $this->exposure($deck, $this->totals($deck));

        $this->assertSame(ThermalVerdict::Safe, $exposure->verdict($socket->id));
        $this->assertTrue($exposure->isSafe());
        $this->assertSame('', ThermalVerdict::Safe->slotClass());
    }

    public function test_reaching_the_warning_level_puts_a_module_at_risk(): void
    {
        // At, not above: "if the deck reaches a module's warning level".
        [, $deck, $socket] = $this->deckAt(150, warnAt: 150, dieAt: 220);

        $exposure = $this->exposure($deck, $this->totals($deck));

        $this->assertSame(ThermalVerdict::AtRisk, $exposure->verdict($socket->id));
        $this->assertSame(['Kurogane CX-1'], $exposure->atRisk());
        $this->assertSame([], $exposure->overLimit());
        $this->assertStringContainsString('slot--hot', ThermalVerdict::AtRisk->slotClass());
    }

    public function test_reaching_the_limit_is_a_certainty_and_not_a_risk(): void
    {
        [, $deck, $socket] = $this->deckAt(220, warnAt: 150, dieAt: 220);

        $exposure = $this->exposure($deck, $this->totals($deck));

        $this->assertSame(ThermalVerdict::OverLimit, $exposure->verdict($socket->id));
        $this->assertSame(['Kurogane CX-1'], $exposure->overLimit());
        // Past both thresholds it reports the one that matters, not both.
        $this->assertSame([], $exposure->atRisk());
    }

    public function test_a_module_with_no_limits_does_not_care_how_hot_it_gets(): void
    {
        // Null is a real answer and the one every module in the catalogue
        // already gives — not a limit of zero.
        [, $deck, $socket] = $this->deckAt(9_000, warnAt: null, dieAt: null);

        $this->assertSame(ThermalVerdict::Safe, $this->exposure($deck, $this->totals($deck))->verdict($socket->id));
    }

    public function test_the_same_module_is_safe_in_a_cold_deck_and_dying_in_a_hot_one(): void
    {
        // The whole point of the mechanic: tolerance is a verdict about a
        // pair, not a property of either half.
        [, $cold, $coldSocket] = $this->deckAt(10, warnAt: 150, dieAt: 220);
        [, $hot, $hotSocket] = $this->deckAt(300, warnAt: 150, dieAt: 220);

        $this->assertSame(ThermalVerdict::Safe, $this->exposure($cold, $this->totals($cold))->verdict($coldSocket->id));
        $this->assertSame(ThermalVerdict::OverLimit, $this->exposure($hot, $this->totals($hot))->verdict($hotSocket->id));
    }

    public function test_cooling_saves_a_module_the_raw_heat_would_have_cooked(): void
    {
        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'psu' => 1, 'thermal' => 1])
            ->create();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'cpu');

        $this->fitter->fit($this->character, $deck, $socket, $this->own(
            Item::factory()->module(Hardware::Cpu, Variant::SocketK,
                measure: 11, draw: 165, thermalLoad: 240, warnAt: 150, dieAt: 220)->create()
        ));

        $this->assertSame(ThermalVerdict::OverLimit, $this->exposure($deck, $this->totals($deck))->verdict($socket->id));

        // The balancing act: a radiator costs power and a socket, and buys the
        // module back.
        $this->fitter->fit($this->character, $deck, $this->socket($deckItem, 'thermal'), $this->own(
            Item::factory()->module(Hardware::Thermal, measure: 150, draw: 12)->create()
        ));

        $this->assertSame(ThermalVerdict::Safe, $this->exposure($deck, $this->totals($deck))->verdict($socket->id));
    }

    public function test_a_deck_can_cook_one_module_while_another_shrugs(): void
    {
        $deckItem = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'ram_opto' => 1, 'psu' => 1])
            ->create();
        $deck = $this->own($deckItem);

        $cpuSocket = $this->socket($deckItem, 'cpu');
        $ramSocket = $this->socket($deckItem, 'ram');

        $this->fitter->fit($this->character, $deck, $cpuSocket, $this->own(
            Item::factory()->module(Hardware::Cpu, Variant::SocketK, measure: 11, draw: 165, thermalLoad: 200,
                warnAt: 900, dieAt: 1_000)->create(['name' => 'Hardened CPU'])
        ));
        $this->fitter->fit($this->character, $deck, $ramSocket, $this->own(
            Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34, thermalLoad: 0,
                warnAt: 100, dieAt: 150)->create(['name' => 'Fragile RAM'])
        ));

        $exposure = $this->exposure($deck, $this->totals($deck));

        // 200 TU: fine for the hardened part, fatal for the fragile one. A
        // deck's tolerance is its weakest part, not the sum of its parts.
        $this->assertSame(ThermalVerdict::Safe, $exposure->verdict($cpuSocket->id));
        $this->assertSame(ThermalVerdict::OverLimit, $exposure->verdict($ramSocket->id));
        $this->assertSame(['Fragile RAM'], $exposure->overLimit());
    }

    public function test_a_perk_that_hardens_chrome_moves_the_thresholds(): void
    {
        [, $deck, $socket] = $this->deckAt(200, warnAt: 150, dieAt: 220);
        $totals = $this->totals($deck);

        $hot = ThermalExposure::of($totals->netThermalLoad(), $deck->fresh()->fittings()->with(['slot', 'module.item.deckModule'])->get(), [], app(Resolver::class));
        $this->assertSame(ThermalVerdict::AtRisk, $hot->verdict($socket->id));

        // Tolerance is a resolved stat like everything else, so a perk that
        // makes chrome run cooler works without deck code knowing about perks.
        $hardened = ThermalExposure::of(
            $totals->netThermalLoad(),
            $deck->fresh()->fittings()->with(['slot', 'module.item.deckModule'])->get(),
            [new Modifier(Op::PercentageOfBase, 50, Target::allOf(['tolerance']), 'a perk')],
            app(Resolver::class),
        );

        $this->assertSame(ThermalVerdict::Safe, $hardened->verdict($socket->id));
    }

    public function test_the_screen_names_what_is_at_risk_and_what_is_doomed(): void
    {
        [, $deck] = $this->deckAt(150, warnAt: 150, dieAt: 220);

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee(Text::get('decks.thermal.at_risk'))
            ->assertSee('Kurogane CX-1')
            ->assertSee('slot--hot', false)
            ->assertDontSee(Text::get('decks.thermal.over_limit_code'));

        [, $doomed] = $this->deckAt(400, warnAt: 150, dieAt: 220);

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $doomed))
            ->assertSee(Text::get('decks.thermal.over_limit_code'))
            ->assertSee(Text::get('decks.thermal.over_limit_fix'))
            ->assertSee('slot--doomed', false);
    }

    public function test_the_thermal_bar_ends_at_the_highest_limit_and_ticks_each_threshold(): void
    {
        [, $deck] = $this->deckAt(110, warnAt: 150, dieAt: 220);

        // 150/220 and 220/220: one brass tick, one ember tick at the edge.
        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee('--x:0.682;--mark:var(--warn)', false)
            ->assertSee('--x:1;--mark:var(--crit)', false)
            ->assertSee('--v:0.5;', false);

        // No module carries a limit: nothing to measure against, no bar.
        [, $bare] = $this->deckAt(110, warnAt: null, dieAt: null);

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $bare))
            ->assertDontSee('meter__mark', false);
    }

    // ---- resolved, not merely summed ---------------------------------------

    /** @return list<Modifier> */
    private function modifier(string $statId, Op $op, float $amount): array
    {
        return [new Modifier($op, $amount, Target::id($statId), 'a skill')];
    }

    public function test_every_measure_is_the_base_of_a_resolved_stat(): void
    {
        [, $deck] = $this->readyDeck();
        $resolver = app(Resolver::class);

        $resolved = $this->totals($deck)->resolvedWith(
            $this->modifier('deck_qflops', Op::Add, 9),
            $resolver,
        );

        // The stored column was the base, not the answer.
        $this->assertSame(20, $resolved->qflops);
        // And nothing else moved with it.
        $this->assertSame(16, $resolved->quantabits);
    }

    public function test_a_percentage_lands_on_the_summed_base(): void
    {
        [, $deck] = $this->readyDeck();

        $resolved = $this->totals($deck)->resolvedWith(
            $this->modifier('deck_quantabits', Op::PercentageOfBase, 50),
            app(Resolver::class),
        );

        // 16 + 50% of 16, floored by the stat's own rounding.
        $this->assertSame(24, $resolved->quantabits);
    }

    public function test_a_tag_targets_every_deck_measure_at_once(): void
    {
        [, $deck] = $this->readyDeck();

        $resolved = $this->totals($deck)->resolvedWith(
            [new Modifier(Op::PercentageOfBase, 100, Target::allOf(['deck']), 'chrome')],
            app(Resolver::class),
        );

        // Tag targeting is the point: a stat added later is picked up without
        // anyone editing a list.
        $this->assertSame(22, $resolved->qflops);
        $this->assertSame(32, $resolved->quantabits);
        $this->assertSame(4, $resolved->bandwidth);
    }

    public function test_a_skill_that_cuts_draw_can_bring_a_deck_into_spec(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 1, 'nic' => 1, 'psu' => 1]);
        $deck = $this->own($deckItem);

        foreach ([
            ['cpu', Hardware::Cpu, Variant::SocketK, 11, 400],
            ['ram', Hardware::Ram, Variant::Opto, 16, 34],
            ['nic', Hardware::Nic, null, 2, 18],
            ['psu', Hardware::Psu, null, 300, 0],
        ] as [$kind, $hardware, $variant, $measure, $draw]) {
            $this->fitter->fit($this->character, $deck, $this->socket($deckItem, $kind),
                $this->own(Item::factory()->module($hardware, $variant, measure: $measure, draw: $draw)->create()));
        }

        $base = $this->totals($deck);

        // 452 W against 300 W of supply.
        $this->assertTrue($base->isOver());
        $this->assertSame([DeckRequirement::Budget], $base->unmet());

        // MECHANICS names this as how over-commitment is meant to be solved:
        // skills that cut draw. Readiness reads the RESOLVED numbers, so it
        // works without readiness knowing anything about skills.
        $resolved = $base->resolvedWith(
            $this->modifier('deck_power_draw', Op::PercentageOfBase, -40),
            app(Resolver::class),
        );

        $this->assertSame(271, $resolved->draw);
        $this->assertFalse($resolved->isOver());
        $this->assertTrue($resolved->isReady());
    }

    public function test_a_measure_is_floored_at_zero_rather_than_going_negative(): void
    {
        [, $deck] = $this->readyDeck();

        $resolved = $this->totals($deck)->resolvedWith(
            $this->modifier('deck_qflops', Op::Add, -999),
            app(Resolver::class),
        );

        // Negative compute is not a state the game has.
        $this->assertSame(0, $resolved->qflops);
    }

    public function test_counts_are_facts_about_the_deck_and_do_not_resolve(): void
    {
        [, $deck] = $this->readyDeck();

        $resolved = $this->totals($deck)->resolvedWith(
            [new Modifier(Op::PercentageOfBase, 100, Target::allOf(['deck']), 'chrome')],
            app(Resolver::class),
        );

        // How many sticks are in a deck is not a number anything modifies.
        $this->assertSame(1, $resolved->countOf(Hardware::Ram));
        $this->assertTrue($resolved->isReady());
    }

    public function test_the_equipped_deck_resolves_and_the_others_show_bases(): void
    {
        [, $equipped] = $this->readyDeck();
        [, $spare] = $this->readyDeck();

        (new DeckEquipper)->equip($this->character, $equipped);

        // No modifier in the game moves a deck measure yet, so both render the
        // same numbers today. What this pins is which PATH each takes.
        $this->actingAs($this->character->user)
            ->get(route('deck.show', $equipped))
            ->assertOk()
            ->assertSee(Text::get('decks.units.qflops', ['value' => '11']));

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $spare))
            ->assertOk()
            ->assertSee(Text::get('decks.units.qflops', ['value' => '11']));
    }

    public function test_the_screen_shows_the_totals_and_the_verdict(): void
    {
        [, $deck] = $this->readyDeck();

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee(Text::get('decks.ready.yes'))
            ->assertSee(Text::get('decks.ready.compute'))
            ->assertSee(Text::get('decks.units.qflops', ['value' => '11']))
            ->assertSee(Text::get('decks.units.quantabits', ['value' => '16']))
            ->assertSee(Text::get('decks.units.bandwidth', ['value' => '2']));
    }

    public function test_the_screen_names_what_is_missing(): void
    {
        $deck = $this->own($this->deckItem());

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee(Text::get('decks.ready.no'))
            ->assertSee(Text::get('decks.ready.missing.link'))
            ->assertSee(Text::get('decks.ready.missing.power'));
    }

    public function test_the_power_sum_is_draw_against_supply(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);

        $this->fitter->fit(
            $this->character, $deck, $this->socket($deckItem, 'ram'),
            $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34)->create()),
        );
        $this->fitter->fit(
            $this->character, $deck, $this->socket($deckItem, 'psu'),
            $this->own(Item::factory()->module(Hardware::Psu, measure: 600)->create()),
        );

        $budget = DeckTotals::of($deck->fresh()->fittings()->with('module.item.deckModule')->get());

        $this->assertSame(34, $budget->draw);
        $this->assertSame(600, $budget->supply);
        $this->assertFalse($budget->isOver());
    }

    public function test_over_commitment_is_reported_and_never_refused(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 2, 'psu' => 1]);
        $deck = $this->own($deckItem);

        foreach ($deckItem->deckSlots()->where('kind', 'ram')->get() as $socket) {
            $result = $this->fitter->fit(
                $this->character, $deck, $socket,
                $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 400)->create()),
            );

            // A deck that cannot power everything it presents is intentional.
            $this->assertTrue($result->succeeded());
        }

        $this->fitter->fit(
            $this->character, $deck, $this->socket($deckItem, 'psu'),
            $this->own(Item::factory()->module(Hardware::Psu, measure: 600)->create()),
        );

        $budget = DeckTotals::of($deck->fresh()->fittings()->with('module.item.deckModule')->get());

        $this->assertSame(800, $budget->draw);
        $this->assertTrue($budget->isOver());
    }

    // ---- over HTTP ---------------------------------------------------------

    public function test_fitting_and_unfitting_through_the_screen(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $socket = $this->socket($deckItem, 'ram');
        $stick = $this->own(Item::factory()->module(Hardware::Ram, Variant::Opto)->create());

        $this->actingAs($this->character->user)
            ->post(route('deck.fit', [$deck, $socket]), ['module' => $stick->id])
            ->assertRedirect();

        $this->assertTrue($stick->fresh()->isFitted());

        // An absent module means empty the socket — the same shape as
        // inventory.place, where an absent parent means the loose pile.
        $this->actingAs($this->character->user)
            ->post(route('deck.fit', [$deck, $socket]))
            ->assertRedirect();

        $this->assertFalse($stick->fresh()->isFitted());
    }

    public function test_a_refusal_is_reported_rather_than_thrown(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $psu = $this->own(Item::factory()->module(Hardware::Psu, measure: 600)->create());

        $this->actingAs($this->character->user)
            ->post(route('deck.fit', [$deck, $this->socket($deckItem, 'ram')]), ['module' => $psu->id])
            ->assertRedirect()
            ->assertSessionHas('error', FittingRefusal::WrongSocket->message());
    }

    public function test_someone_elses_deck_is_a_404_not_a_refusal(): void
    {
        $deckItem = $this->deckItem();
        $theirs = $this->own($deckItem, $this->character());

        $this->actingAs($this->character->user)
            ->post(route('deck.fit', [$theirs, $this->socket($deckItem, 'ram')]))
            ->assertNotFound();
    }

    // ---- the other half of the feature -------------------------------------

    public function test_a_module_may_not_stack(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // A fitting points at a row, and a row holding two sticks cannot be
        // pointed at. Decks were refused this from the start; modules join
        // them here.
        $this->actingAs($admin)
            ->post(route('admin.items.store'), [
                'slug' => 'veritas_os16', 'name' => 'Veritas OS-16', 'flavor' => '',
                'container' => Item::CONTAINER_PHYSICAL, 'tier' => 1, 'rarity' => 'common',
                'stackable' => '1',
                'use_op' => '', 'use_skill_id' => '', 'weight' => '0.20', 'size' => 1,
                'capacity' => 0, 'accepts' => '', 'base_cost' => 500,
                'on_market' => '1', 'seed_quantity' => 2,
                'hardware' => 'ram', 'variant' => 'opto', 'quantabits' => 16, 'power_draw' => 34,
            ])
            ->assertSessionHasErrors('stackable');
    }
}
