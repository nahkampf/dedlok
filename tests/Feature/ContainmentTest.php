<?php

namespace Tests\Feature;

use App\Inventory\ItemPlacer;
use App\Inventory\PlacementRefusal;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Items that hold other items.
 *
 * The case driving all of this is a memory card: a physical object carrying
 * data. It breaks two assumptions the flat inventory was built on — that
 * nothing is inside anything, and that two copies of an item are
 * interchangeable — and most of what is asserted here is that neither
 * assumption is load-bearing any more.
 */
class ContainmentTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Skill $hacking;

    private Item $book;

    private Item $card;

    private ItemPlacer $placer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = $this->character();
        $this->hacking = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);
        $this->book = Item::factory()->skillbookFor($this->hacking)->size(8)->create();
        $this->card = Item::factory()->physical()->holds(Item::CONTAINER_DATA, 16)->create();
        $this->placer = new ItemPlacer;
    }

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    private function give(Item $item, int $quantity = 1, ?Character $to = null, ?InventoryItem $inside = null): InventoryItem
    {
        $factory = InventoryItem::factory()
            ->ownedBy($to ?? $this->character)
            ->of($item)
            ->quantity($quantity);

        return ($inside ? $factory->inside($inside) : $factory)->create();
    }

    // ---- what a container is ----------------------------------------------

    public function test_capacity_is_what_makes_something_a_container(): void
    {
        $this->assertTrue($this->card->isContainer());
        $this->assertFalse($this->book->isContainer());

        // The kind it takes is checked against the other item's container:
        // a card is physical and holds data, which is the whole point.
        $this->assertTrue($this->card->canHold($this->book));
        $this->assertFalse($this->card->canHold($this->card));
    }

    // ---- nesting ----------------------------------------------------------

    public function test_a_book_on_a_card_is_not_also_loose_on_the_deck(): void
    {
        $card = $this->give($this->card);
        $this->give($this->book, inside: $card);

        // The card is carried; the book is on the card and nowhere else.
        $this->assertCount(1, $this->character->inventoryIn(Item::CONTAINER_PHYSICAL));
        $this->assertCount(0, $this->character->inventoryIn(Item::CONTAINER_DATA));
        $this->assertCount(1, $card->fresh()->children);
    }

    public function test_storing_moves_a_row_onto_the_card(): void
    {
        $card = $this->give($this->card);
        $loose = $this->give($this->book);

        $result = $this->placer->place($this->character, $loose, $card);

        $this->assertTrue($result->succeeded());
        $this->assertSame($card->id, $loose->fresh()->parent_id);
        $this->assertCount(0, $this->character->inventoryIn(Item::CONTAINER_DATA));
    }

    public function test_ejecting_puts_it_back_in_the_loose_pile(): void
    {
        $card = $this->give($this->card);
        $stored = $this->give($this->book, inside: $card);

        $result = $this->placer->place($this->character, $stored, null);

        $this->assertTrue($result->succeeded());
        $this->assertNull($stored->fresh()->parent_id);
        $this->assertCount(1, $this->character->inventoryIn(Item::CONTAINER_DATA));
    }

    public function test_destroying_the_card_destroys_what_was_written_on_it(): void
    {
        $card = $this->give($this->card);
        $stored = $this->give($this->book, inside: $card);

        $card->delete();

        $this->assertNull(InventoryItem::find($stored->id));
    }

    // ---- the rules ---------------------------------------------------------

    public function test_capacity_is_enforced(): void
    {
        // 16 units of card, 8 per book: the third will not go on.
        $card = $this->give($this->card);
        $this->give($this->book, 2, inside: $card);

        $result = $this->placer->place($this->character, $this->give($this->book), $card->fresh());

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::NoRoom, $result->refusal);
    }

    public function test_a_quantity_counts_against_capacity_per_object(): void
    {
        $card = $this->give($this->card);

        // Three books at 8 units each is 24 against a capacity of 16.
        $result = $this->placer->place($this->character, $this->give($this->book, 3), $card);

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::NoRoom, $result->refusal);
    }

    public function test_a_card_will_not_take_a_rifle(): void
    {
        $rifle = Item::factory()->physical()->create();

        $result = $this->placer->place($this->character, $this->give($rifle), $this->give($this->card));

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::WrongKind, $result->refusal);
    }

    public function test_most_items_hold_nothing(): void
    {
        $rifle = Item::factory()->physical()->create();

        $result = $this->placer->place($this->character, $this->give($this->book), $this->give($rifle));

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::NotAContainer, $result->refusal);
    }

    public function test_containers_do_not_nest(): void
    {
        $outer = $this->give($this->card);
        $inner = $this->give($this->card);

        $result = $this->placer->place($this->character, $inner, $outer);

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::ContainersDoNotNest, $result->refusal);
    }

    public function test_a_card_cannot_go_inside_itself(): void
    {
        $card = $this->give($this->card);

        $result = $this->placer->place($this->character, $card, $card);

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::ContainersDoNotNest, $result->refusal);
    }

    public function test_someone_elses_card_is_not_somewhere_to_put_things(): void
    {
        $theirs = $this->give($this->card, to: $this->character());

        $result = $this->placer->place($this->character, $this->give($this->book), $theirs);

        $this->assertFalse($result->succeeded());
        $this->assertSame(PlacementRefusal::NotOwned, $result->refusal);
    }

    // ---- per-instance identity ---------------------------------------------

    public function test_two_cards_are_two_rows_not_a_quantity(): void
    {
        $first = $this->give($this->card);
        $second = $this->give($this->card);

        // The old unique key on (character, item) said these were the same
        // object. They are not: one of them has a skillbook on it.
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $this->character->inventory()->count());
        $this->assertNull($first->fresh()->stack_key);
    }

    public function test_stackable_items_still_refuse_a_second_pile(): void
    {
        $this->give($this->book);

        $this->expectException(QueryException::class);

        $this->give($this->book);
    }

    public function test_the_same_book_loose_and_on_a_card_are_two_piles(): void
    {
        $card = $this->give($this->card);

        $loose = $this->give($this->book);
        $stored = $this->give($this->book, inside: $card);

        $this->assertNotSame($loose->fresh()->stack_key, $stored->fresh()->stack_key);
    }

    public function test_storing_onto_a_pile_that_is_already_there_merges(): void
    {
        $card = $this->give($this->card);
        $onCard = $this->give($this->book, inside: $card);
        $loose = $this->give($this->book);

        $result = $this->placer->place($this->character, $loose, $card);

        $this->assertTrue($result->succeeded());
        $this->assertSame(2, $onCard->fresh()->quantity);
        $this->assertNull(InventoryItem::find($loose->id));
    }

    // ---- the gate still holds ----------------------------------------------

    public function test_a_book_on_a_card_can_still_be_injected(): void
    {
        $card = $this->give($this->card);
        $stored = $this->give($this->book, inside: $card);

        $result = (new SkillInjector)->inject($this->character, $stored);

        $this->assertTrue($result->succeeded());
        $this->assertTrue($this->character->hasInjected($this->hacking));
        $this->assertNull(InventoryItem::find($stored->id));
    }

    // ---- the screen ---------------------------------------------------------

    public function test_the_stash_shows_what_is_on_the_card(): void
    {
        $card = $this->give($this->card);
        $this->give($this->book, inside: $card);

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(Text::get('inventory.contents'))
            // 16 units of capacity, one 8-unit book on it.
            ->assertSee(Text::get('inventory.contents_free', ['free' => 8, 'capacity' => 16]));
    }

    public function test_storing_and_ejecting_over_http(): void
    {
        $card = $this->give($this->card);
        $loose = $this->give($this->book);

        $this->actingAs($this->character->user)
            ->post(route('inventory.place', $loose), ['parent' => $card->id])
            ->assertRedirect();

        $this->assertSame($card->id, $loose->fresh()->parent_id);

        $this->actingAs($this->character->user)
            ->post(route('inventory.place', $loose))
            ->assertRedirect();

        $this->assertNull($loose->fresh()->parent_id);
    }

    public function test_someone_elses_row_is_a_404_not_a_refusal(): void
    {
        $theirs = $this->give($this->book, to: $this->character());

        $this->actingAs($this->character->user)
            ->post(route('inventory.place', $theirs))
            ->assertNotFound();
    }
}
