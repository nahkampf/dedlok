<?php

namespace Tests\Feature;

use App\Decks\DeckEquipper;
use App\Decks\FittingRefusal;
use App\Decks\Hardware;
use App\Decks\ModuleFitter;
use App\Decks\Variant;
use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What you can do to an item, on the card you already have open.
 *
 * These lived in a right-click menu once. A right-click has no mobile story,
 * nothing on screen says it exists, and an extension can eat the event before
 * the page sees it — so the actions moved onto the card, which is the thing
 * you were already looking at.
 *
 * Most of them are doors with nothing behind them, and that is deliberate.
 * What is asserted here is that the ones which ARE built work, and that the
 * ones which are not say so rather than failing quietly.
 */
class ItemActionsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private ModuleFitter $fitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
        $this->fitter = new ModuleFitter;
    }

    private function own(Item $item): InventoryItem
    {
        return InventoryItem::factory()->ownedBy($this->character)->of($item)->create();
    }

    private function deckItem(array $counts = ['cpu_socket_k' => 1, 'ram_opto' => 1, 'psu' => 1]): Item
    {
        return Item::factory()->cyberdeck($counts)->create();
    }

    private function stick(): Item
    {
        return Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34)->create();
    }

    // ---- the menu itself ---------------------------------------------------

    public function test_every_stash_row_carries_its_actions_on_its_card(): void
    {
        $entry = $this->own(Item::factory()->physical()->create());

        $response = $this->actingAs($this->character->user)->get(route('inventory.index'));

        // The card is inside the slot's own template, so the actions travel
        // with it wherever ui-kit.js clones it.
        $response->assertSee('<template id="detail-'.$entry->id.'">', false);
        $response->assertSee('data-open="#destroy-'.$entry->id.'"', false);
        $response->assertSee('<dialog class="modal" id="destroy-'.$entry->id.'">', false);

        // And nothing depends on a right-click any more.
        $response->assertDontSee('data-menu', false);
    }

    public function test_what_is_not_built_says_so_rather_than_being_absent(): void
    {
        $this->own(Item::factory()->physical()->create());

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee(Text::get('inventory.actions.market'))
            ->assertSee(Text::get('inventory.actions.disassemble'))
            ->assertSee(Text::get('inventory.actions.not_yet'));
    }

    public function test_only_a_module_is_offered_a_deck_to_go_in(): void
    {
        $this->own(Item::factory()->physical()->create());

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertDontSee(Text::get('inventory.actions.fit'));

        $module = $this->own($this->stick());

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee(route('inventory.fit', $module), false);
    }

    public function test_a_fitted_module_is_offered_the_way_back_out(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $module = $this->own($this->stick());
        $socket = $deckItem->deckSlots()->where('kind', 'ram')->firstOrFail();

        $this->fitter->fit($this->character, $deck, $socket, $module);

        $response = $this->actingAs($this->character->user)->get(route('deck.show', $deck));

        // The socket's dialog opens the module's card, and the card carries
        // the way back out along with everything else.
        $response->assertSee('data-inspect-open="#detail-'.$module->id.'"', false);
        $response->assertSee(Text::get('inventory.actions.unfit'));
        $response->assertSee(route('deck.fit', [$deck, $socket]), false);

        /*
         * The menu's form has to name the socket, and asserting the URL is
         * present is not enough to know that it does — the socket's own dialog
         * renders the identical URL, and did so while the menu was posting to
         * a route with the parameter missing.
         *
         * `slot` is RESERVED in a Blade component: $slot is the default slot's
         * contents, so a prop of that name is shadowed by an empty
         * ComponentSlot. Truthy, so the form rendered; empty, so route() built
         * "/deck/1/socket" and matched nothing. A bare /socket" in an action is
         * the signature of that bug, whatever renders it.
         */
        $response->assertDontSee('/socket"', false);
    }

    // ---- fit to deck -------------------------------------------------------

    public function test_fitting_from_the_menu_finds_a_socket_itself(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);
        $module = $this->own($this->stick());

        $result = $this->fitter->fitToDeck($this->character->fresh(), $module);

        $this->assertTrue($result->succeeded());
        $this->assertSame('ram', $result->fitting->slot->kind->value);
    }

    public function test_fitting_needs_a_deck_to_fit_into(): void
    {
        $module = $this->own($this->stick());

        $this->assertSame(
            FittingRefusal::NotEquipped,
            $this->fitter->fitToDeck($this->character, $module)->refusal
        );
    }

    public function test_a_deck_with_no_socket_of_that_kind_says_which_it_is(): void
    {
        // Different from "full", and a player needs the two told apart: this
        // deck will never take the module.
        $deck = $this->own($this->deckItem(['cpu_socket_k' => 1, 'psu' => 1]));
        (new DeckEquipper)->equip($this->character, $deck);

        $this->assertSame(
            FittingRefusal::DeckHasNoSocket,
            $this->fitter->fitToDeck($this->character->fresh(), $this->own($this->stick()))->refusal
        );
    }

    public function test_a_full_deck_says_it_is_full(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        (new DeckEquipper)->equip($this->character, $deck);

        $this->fitter->fit(
            $this->character, $deck,
            $deckItem->deckSlots()->where('kind', 'ram')->firstOrFail(),
            $this->own($this->stick()),
        );

        $this->assertSame(
            FittingRefusal::NoFreeSocket,
            $this->fitter->fitToDeck($this->character->fresh(), $this->own($this->stick()))->refusal
        );
    }

    public function test_it_takes_the_first_free_socket_and_leaves_the_taken_one(): void
    {
        $deckItem = $this->deckItem(['cpu_socket_k' => 1, 'ram_opto' => 2, 'psu' => 1]);
        $deck = $this->own($deckItem);
        (new DeckEquipper)->equip($this->character, $deck);

        $sockets = $deckItem->deckSlots()->where('kind', 'ram')->get();
        $first = $this->own($this->stick());
        $this->fitter->fit($this->character, $deck, $sockets[0], $first);

        $second = $this->own($this->stick());
        $result = $this->fitter->fitToDeck($this->character->fresh(), $second);

        $this->assertTrue($result->succeeded());
        $this->assertSame($sockets[1]->id, $result->fitting->deck_slot_id);
    }

    public function test_only_a_module_can_be_fitted_from_the_menu(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);

        $this->assertSame(
            FittingRefusal::NotAModule,
            $this->fitter->fitToDeck($this->character->fresh(), $this->own(Item::factory()->physical()->create()))->refusal
        );
    }

    public function test_fitting_from_the_menu_over_http(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);
        $module = $this->own($this->stick());

        $this->actingAs($this->character->user)
            ->post(route('inventory.fit', $module))
            ->assertRedirect();

        $this->assertTrue($module->fresh()->isFitted());
    }

    public function test_a_refusal_is_reported_rather_than_thrown(): void
    {
        $module = $this->own($this->stick());

        $this->actingAs($this->character->user)
            ->post(route('inventory.fit', $module))
            ->assertRedirect()
            ->assertSessionHas('error', FittingRefusal::NotEquipped->message());
    }

    // ---- destroy -----------------------------------------------------------

    public function test_destroying_a_row_destroys_it(): void
    {
        $entry = $this->own(Item::factory()->physical()->create());

        $this->actingAs($this->character->user)
            ->post(route('inventory.destroy', $entry))
            ->assertRedirect(route('inventory.index'));

        $this->assertNull($entry->fresh());
    }

    public function test_the_confirmation_is_offered_before_the_button(): void
    {
        $entry = $this->own(Item::factory()->physical()->create(['name' => 'Rail rifle']));

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee('id="destroy-'.$entry->id.'"', false)
            ->assertSee(Text::get('inventory.destroy.confirm'))
            ->assertSee(Text::get('inventory.destroy.cancel'));
    }

    public function test_destroying_a_container_takes_what_is_on_it(): void
    {
        $card = $this->own(Item::factory()->physical()->holds(Item::CONTAINER_DATA, 16)->create());
        $skill = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);
        $book = InventoryItem::factory()
            ->ownedBy($this->character)
            ->of(Item::factory()->skillbookFor($skill)->size(8)->create())
            ->inside($card)
            ->create();

        $this->actingAs($this->character->user)
            ->post(route('inventory.destroy', $card))
            ->assertRedirect();

        // parent_id cascades. Crush the shard, lose what was written on it.
        $this->assertNull($book->fresh());
    }

    public function test_destroying_a_deck_frees_its_modules_rather_than_eating_them(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        $module = $this->own($this->stick());
        $this->fitter->fit($this->character, $deck, $deckItem->deckSlots()->where('kind', 'ram')->firstOrFail(), $module);

        $this->actingAs($this->character->user)
            ->post(route('inventory.destroy', $deck))
            ->assertRedirect();

        // Unlike a container: the fitting cascades, the module does not.
        $this->assertNull($deck->fresh());
        $this->assertNotNull($module->fresh());
        $this->assertSame(0, DeckFitting::count());
        $this->assertTrue(
            $this->character->fresh()->inventoryIn(Item::CONTAINER_PHYSICAL)->contains('id', $module->id)
        );
    }

    public function test_destroying_the_equipped_deck_leaves_you_with_none(): void
    {
        $deck = $this->own($this->deckItem());
        (new DeckEquipper)->equip($this->character, $deck);

        $this->actingAs($this->character->user)
            ->post(route('inventory.destroy', $deck))
            ->assertRedirect();

        $this->assertNull($this->character->fresh()->equipped_deck_id);
    }

    public function test_the_dialog_says_what_the_delete_will_reach(): void
    {
        $deckItem = $this->deckItem();
        $deck = $this->own($deckItem);
        (new DeckEquipper)->equip($this->character, $deck);

        // A carried deck is an ordinary slot in the grid now, so its own card
        // is the route to the confirmation — the same as everything else.
        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee('data-inspect="#detail-'.$deck->id.'"', false)
            ->assertSee(Text::get('inventory.destroy.fitted'))
            ->assertSee(Text::get('inventory.destroy.equipped'));
    }

    public function test_someone_elses_row_is_a_404_not_a_refusal(): void
    {
        $theirs = InventoryItem::factory()
            ->ownedBy(Character::factory()->create(array_merge(
                array_fill_keys(Character::ATTRIBUTES, 10), ['archetype' => 3],
            )))
            ->of(Item::factory()->physical()->create())
            ->create();

        $this->actingAs($this->character->user)
            ->post(route('inventory.destroy', $theirs))
            ->assertNotFound();

        $this->actingAs($this->character->user)
            ->post(route('inventory.fit', $theirs))
            ->assertNotFound();

        $this->assertNotNull($theirs->fresh());
    }
}
