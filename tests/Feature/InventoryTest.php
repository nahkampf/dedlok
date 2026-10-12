<?php

namespace Tests\Feature;

use App\Decks\Hardware;
use App\Decks\Variant;
use App\Inventory\InjectionRefusal;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two inventories, and the one thing you can do from them.
 *
 * The gate this establishes: a skill cannot be trained until its imprint has been
 * bought and spent. Injecting is the only path onto the sheet, so most of what
 * matters here is that the path cannot be walked twice, walked with someone
 * else's imprint, or walked around.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Skill $hacking;

    private Item $book;

    private SkillInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = $this->character();
        $this->hacking = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);
        $this->book = Item::factory()->imprintFor($this->hacking)->create();
        $this->injector = new SkillInjector;
    }

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    private function give(Item $item, int $quantity = 1, ?Character $to = null): InventoryItem
    {
        return InventoryItem::factory()
            ->ownedBy($to ?? $this->character)
            ->of($item)
            ->quantity($quantity)
            ->create();
    }

    private function progress(?Character $of = null): ?CharacterSkill
    {
        return CharacterSkill::query()
            ->where('character_id', ($of ?? $this->character)->id)
            ->where('skill_id', $this->hacking->id)
            ->first();
    }

    // ---- the two inventories ----------------------------------------------

    public function test_an_item_belongs_to_one_inventory_and_the_row_cannot_disagree(): void
    {
        $rifle = Item::factory()->physical()->create();
        $shard = Item::factory()->data()->create();

        $this->give($rifle);
        $this->give($shard);

        $physical = $this->character->inventoryIn(Item::CONTAINER_PHYSICAL);
        $data = $this->character->inventoryIn(Item::CONTAINER_DATA);

        $this->assertSame([$rifle->id], $physical->pluck('item_id')->all());
        $this->assertSame([$shard->id], $data->pluck('item_id')->all());
    }

    public function test_imprints_live_in_the_data_inventory(): void
    {
        $this->give($this->book);

        $this->assertCount(1, $this->character->inventoryIn(Item::CONTAINER_DATA));
        $this->assertCount(0, $this->character->inventoryIn(Item::CONTAINER_PHYSICAL));
    }

    public function test_one_row_per_character_and_item(): void
    {
        $this->give($this->book);

        $this->expectException(QueryException::class);

        $this->give($this->book);
    }

    public function test_inventories_are_per_character(): void
    {
        $other = $this->character();

        $this->give($this->book);

        $this->assertCount(1, $this->character->inventoryIn(Item::CONTAINER_DATA));
        $this->assertCount(0, $other->inventoryIn(Item::CONTAINER_DATA));
    }

    // ---- injecting ---------------------------------------------------------

    public function test_injecting_spends_the_book_and_puts_the_skill_on_the_sheet(): void
    {
        $entry = $this->give($this->book);

        $result = $this->injector->inject($this->character, $entry);

        $this->assertTrue($result->succeeded());
        $this->assertNull($entry->fresh(), 'the last copy is deleted, not left at zero');

        $progress = $this->progress();

        $this->assertNotNull($progress);
        $this->assertSame(0, $progress->skill_points, 'a fresh skill starts at nothing');
        $this->assertSame(0, $progress->attained_level);
    }

    public function test_a_stack_loses_exactly_one_copy(): void
    {
        $entry = $this->give($this->book, 3);

        $this->injector->inject($this->character, $entry);

        $this->assertSame(2, $entry->fresh()->quantity);
    }

    public function test_the_same_skill_cannot_be_injected_twice(): void
    {
        $first = $this->give($this->book);
        $this->injector->inject($this->character, $first);

        // A second copy of the same imprint, bought later.
        $second = $this->give($this->book);
        $result = $this->injector->inject($this->character, $second);

        $this->assertFalse($result->succeeded());
        $this->assertSame(InjectionRefusal::AlreadyKnown, $result->refusal);
        $this->assertNotNull($second->fresh(), 'a refused injection keeps the imprint');
        $this->assertSame(1, CharacterSkill::count());
    }

    public function test_injecting_does_not_reset_progress_on_a_skill_already_trained(): void
    {
        $entry = $this->give($this->book);
        $this->injector->inject($this->character, $entry);

        $this->progress()->update(['skill_points' => 5_000, 'attained_level' => 2]);

        $again = $this->give($this->book);
        $this->injector->inject($this->character, $again);

        $this->assertSame(5_000, $this->progress()->skill_points, 'refusing is what protects the points');
        $this->assertSame(2, $this->progress()->attained_level);
    }

    public function test_a_book_belonging_to_someone_else_is_refused(): void
    {
        $other = $this->character();
        $entry = $this->give($this->book, to: $other);

        $result = $this->injector->inject($this->character, $entry);

        $this->assertSame(InjectionRefusal::NotOwned, $result->refusal);
        $this->assertNotNull($entry->fresh());
        $this->assertNull($this->progress());
        $this->assertNull($this->progress($other));
    }

    public function test_an_item_that_is_not_an_imprint_is_refused(): void
    {
        $entry = $this->give(Item::factory()->physical()->create());

        $result = $this->injector->inject($this->character, $entry);

        $this->assertSame(InjectionRefusal::NotAnImprint, $result->refusal);
        $this->assertNotNull($entry->fresh(), 'a refused use never consumes');
    }

    // ---- the screen --------------------------------------------------------

    public function test_the_stash_lists_both_inventories(): void
    {
        $rifle = Item::factory()->physical()->create(['slug' => 'rail_rifle', 'tier' => 2]);
        $this->give($rifle);
        $this->give($this->book);

        $response = $this->actingAs($this->character->user)->get(route('inventory.index'));

        $response->assertOk();
        $response->assertSee(Text::get('inventory.physical'));
        $response->assertSee(Text::get('inventory.data'));
        $response->assertSee(Text::get('items.imprint.name', ['skill' => 'Hacking']));
        $response->assertSee(Text::get('items.tiers.2'));
    }

    public function test_every_row_is_a_slot_with_its_detail_beside_it(): void
    {
        $rifle = Item::factory()->physical()->create(['slug' => 'rail_rifle', 'tier' => 2]);
        $entry = $this->give($rifle);

        $response = $this->actingAs($this->character->user)->get(route('inventory.index'));

        // The slot and the template it names. The detail is rendered once, in
        // the template, and cloned by ui-kit.js into the hover layer and the
        // modal both — so a detail missing from the source is missing from
        // everywhere, which is what this asserts.
        $response->assertSee('data-inspect="#detail-'.$entry->id.'"', false);
        $response->assertSee('<template id="detail-'.$entry->id.'">', false);
    }

    public function test_the_detail_carries_what_the_slot_cannot_show(): void
    {
        $rifle = Item::factory()->physical()->create([
            'slug' => 'rail_rifle',
            'name' => 'Rail rifle',
            'flavor' => 'Still has someone else\'s serial on it.',
            'tier' => 2,
            'size' => 6,
        ]);
        $this->give($rifle, 2);

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee('Still has someone else\'s serial on it.')
            ->assertSee(Text::get('inventory.stats.size'))
            ->assertSee(Text::get('inventory.stats.quantity'));
    }

    public function test_the_detail_names_the_kind_of_hardware_it_is(): void
    {
        $ram = Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34)
            ->create(['slug' => 'veritas_os16', 'name' => 'Veritas OS-16']);
        $this->give($ram);

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            // The spec line carries the numbers; only this says what it is.
            ->assertSee(Hardware::Ram->label())
            ->assertSee('16 QB');
    }

    public function test_only_imprints_offer_an_inject_button(): void
    {
        $this->give(Item::factory()->physical()->create());

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertDontSee('inventory.inject');

        $entry = $this->give($this->book);

        $this->actingAs($this->character->user)
            ->get(route('inventory.index'))
            ->assertSee(route('inventory.inject', $entry), false);
    }

    public function test_injecting_through_the_screen_works_end_to_end(): void
    {
        $entry = $this->give($this->book);

        $this->actingAs($this->character->user)
            ->post(route('inventory.inject', $entry))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull($entry->fresh());
        $this->assertNotNull($this->progress());
    }

    public function test_a_refusal_is_reported_rather_than_thrown(): void
    {
        $entry = $this->give($this->book);
        $this->injector->inject($this->character, $entry);

        $second = $this->give($this->book);

        $this->actingAs($this->character->user)
            ->post(route('inventory.inject', $second))
            ->assertRedirect()
            ->assertSessionHas('error', Text::get('inventory.refused.already_known'));
    }

    public function test_someone_elses_row_is_not_found_rather_than_refused(): void
    {
        $other = $this->character();
        $entry = $this->give($this->book, to: $other);

        $this->actingAs($this->character->user)
            ->post(route('inventory.inject', $entry))
            ->assertNotFound();

        $this->assertNotNull($entry->fresh());
    }

    public function test_the_stash_needs_a_signed_in_character(): void
    {
        $this->get(route('inventory.index'))->assertRedirect(route('login'));
    }

    // ---- naming ------------------------------------------------------------

    public function test_an_imprint_is_named_from_the_skill_it_teaches(): void
    {
        // One lang entry covers every imprint, so adding a skill never means
        // editing a locale.
        $this->assertSame('Hacking', $this->book->displayName());
        $this->assertSame(Text::get('items.imprint.flavor'), $this->book->displayFlavor());
    }

    public function test_an_ordinary_item_is_named_by_its_row(): void
    {
        $item = Item::factory()->physical()->create([
            'slug' => 'kiroshi_optics',
            'name' => 'Kiroshi Optics',
        ]);

        $this->assertSame('Kiroshi Optics', $item->displayName());
    }

    public function test_an_item_with_no_flavour_entry_renders_nothing_rather_than_a_key(): void
    {
        $item = Item::factory()->physical()->create(['slug' => 'no_such_item']);

        $this->assertNull($item->displayFlavor());
    }
}
