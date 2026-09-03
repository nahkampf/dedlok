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
 * The right-click menu, and the two things on it that do anything.
 *
 * Most of the menu is a door with nothing behind it, and that is deliberate:
 * a menu that grows rows as features land is a menu nobody learns. What is
 * asserted here is that the rows which ARE built work, and that the ones which
 * are not say so rather than failing quietly.
 */
class ItemMenuTest extends TestCase
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

    public function test_every_stash_row_carries_a_menu(): void
    {
        $entry = $this->own(Item::factory()->physical()->create());

        $response = $this->actingAs($this->character->user)->get(route('inventory.index'));

        $response->assertSee('data-menu="#menu-'.$entry->id.'"', false);
        $response->assertSee('<template id="menu-'.$entry->id.'">', false);

        // The row that opens the same card the slot itself opens.
        $response->assertSee('data-inspect-open="#detail-'.$entry->id.'"', false);
        $response->assertSee(Text::get('inventory.menu.details'));
    }

    public function test_what_is_not_built_says_so_rather_than_being_absent(): void
    {
        $this->own(Item::factory()->physical()->create());

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee(Text::get('inventory.menu.market'))
            ->assertSee(Text::get('inventory.menu.disassemble'))
            ->assertSee(Text::get('inventory.menu.not_yet'));
    }

    public function test_only_a_module_is_offered_a_deck_to_go_in(): void
    {
        $this->own(Item::factory()->physical()->create());

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertDontSee(Text::get('inventory.menu.fit'));

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

        $this->actingAs($this->character->user)
            ->get(route('deck.show', $deck))
            ->assertSee('data-menu="#menu-'.$module->id.'"', false)
            ->assertSee(Text::get('inventory.menu.unfit'))
            ->assertSee(route('deck.fit', [$deck, $socket]), false);
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

        // An equipped deck is in no grid, so the stash's "in use" panel is
        // where its menu — and its confirmation — has to live.
        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee('data-menu="#menu-'.$deck->id.'"', false)
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
