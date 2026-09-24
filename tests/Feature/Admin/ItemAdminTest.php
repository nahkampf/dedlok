<?php

namespace Tests\Feature\Admin;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\MarketPurchase;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The item creator and editor.
 *
 * Item definitions are rows rather than config, so they get none of the
 * protection config gets from tests and review. What this covers is the
 * replacement: that the editor refuses the shapes the schema's CHECK
 * constraints would refuse, refuses the two it cannot express, and that a
 * delete really does reach as far as the confirmation says it will.
 */
class ItemAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * A complete, valid payload. Tests override the one field under test, so a
     * failure names that field rather than whichever one was forgotten.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'monowire',
            'name' => 'Monowire',
            'flavor' => 'Thinner than the eye can follow.',
            'container' => Item::CONTAINER_PHYSICAL,
            'tier' => 2,
            'rarity' => 'rare',
            'stackable' => '1',
            'use_op' => '',
            'use_skill_id' => '',
            'weight' => '0.50',
            'size' => 4,
            'capacity' => 0,
            'accepts' => '',
            'base_cost' => 9_000,
            'on_market' => '1',
            'seed_quantity' => 3,
        ], $overrides);
    }

    /**
     * What the FORM actually posts, which is not what `payload()` posts.
     *
     * The editor renders every panel whatever is being edited, and the
     * software and drug panels default their numbers to ZERO rather than to
     * blank. Every test here had been hand-building a payload without them,
     * which is why the suite never noticed that those six fields are
     * `prohibited` for an item that is neither — and that creating anything
     * else through the real form was therefore impossible.
     *
     * @return array<string, mixed>
     */
    private function asTheFormPosts(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'program_kind' => '',
            'qflops_cost' => 0,
            'memory_cost' => 0,
            'storage_size' => 0,
            'drug_family' => '',
            'duration_minutes' => 0,
            'addictive' => 0,
            'tolerance_gain' => 0,
            'implant_region' => '',
        ], $overrides));
    }

    // ---- creating ---------------------------------------------------------

    /**
     * AN ORDINARY ITEM SAVES FROM THE REAL FORM.
     *
     * The regression: the empty software and drug panels arrived as six
     * present zeroes and every one of them was refused, so the editor could
     * not create an implant, a module, or anything that was not itself a
     * program or a drug.
     */
    public function test_an_item_saves_with_the_empty_panels_the_form_sends(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->asTheFormPosts())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNotNull(Item::query()->where('slug', 'monowire')->first());
    }

    public function test_an_implant_is_authored_by_picking_a_region(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->asTheFormPosts([
                'slug' => 'cortex_shunt',
                'name' => 'Cortex Shunt',
                'implant_region' => 'spine',
            ]))
            ->assertSessionHasNoErrors();

        $item = Item::query()->where('slug', 'cortex_shunt')->firstOrFail();

        // The region is the WHOLE declaration: nothing else on the row says an
        // item is an implant.
        $this->assertSame('spine', $item->implant_region->value);
        $this->assertNull($item->program);
        $this->assertNull($item->drug);
    }

    /**
     * A TYPED value on a panel that is off is still refused.
     *
     * Only the form's own zeroes are dropped. Somebody who puts a compute cost
     * on a thing that is not a program has made a mistake worth a sentence,
     * not one to discard behind their back — the distinction `positiveCounts()`
     * already draws for the socket counters.
     */
    public function test_a_typed_cost_on_a_non_program_is_still_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->asTheFormPosts(['qflops_cost' => 5]))
            ->assertSessionHasErrors('qflops_cost');
    }

    /** The panel's own gate still decides: a real drug keeps its numbers. */
    public function test_a_drug_still_saves_its_own_figures(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->asTheFormPosts([
                'slug' => 'blue_nine',
                'name' => 'Blue Nine',
                'drug_family' => 'stimulant',
                'duration_minutes' => 45,
                // Zero is a real answer here — a drug that does not addict —
                // which is why the fix gates on the panel rather than dropping
                // zeroes.
                'addictive' => 0,
                'tolerance_gain' => 2.5,
            ]))
            ->assertSessionHasNoErrors();

        $drug = Item::query()->where('slug', 'blue_nine')->firstOrFail()->drug;

        $this->assertNotNull($drug);
        $this->assertSame(45, (int) $drug->duration_minutes);
        $this->assertSame(0.0, (float) $drug->addictive);
    }

    public function test_an_admin_can_create_an_item(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload())
            ->assertRedirect();

        $item = Item::where('slug', 'monowire')->firstOrFail();

        $this->assertSame('Monowire', $item->name);
        $this->assertSame(2, $item->tier);
        $this->assertSame('rare', $item->rarity->value);
        $this->assertTrue($item->stackable);
        $this->assertSame(9_000, $item->base_cost);
    }

    public function test_an_unchecked_switch_is_false_rather_than_missing(): void
    {
        // A browser omits an unchecked checkbox entirely; the column is not
        // nullable, so "absent" has to arrive as false rather than as nothing.
        $payload = $this->payload();
        unset($payload['stackable'], $payload['on_market']);

        $this->actingAs($this->admin)->post('/admin/items', $payload)->assertRedirect();

        $item = Item::where('slug', 'monowire')->firstOrFail();

        $this->assertFalse($item->stackable);
        $this->assertFalse($item->on_market);
    }

    public function test_a_slug_must_be_unique_and_well_formed(): void
    {
        Item::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload(['slug' => 'taken']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload(['slug' => 'Not A Slug']))
            ->assertSessionHasErrors('slug');
    }

    public function test_an_item_needs_a_name_unless_it_is_a_book(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    // ---- the pairings the schema constrains -------------------------------

    public function test_an_injecting_item_must_name_the_skill_it_teaches(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'use_op' => Item::USE_INJECT_SKILL,
                'use_skill_id' => '',
                'name' => '',
            ]))
            ->assertSessionHasErrors('use_skill_id');

        // Scoped to the slug rather than counting the table: a fresh database
        // now ships the placeholder implants, so "no items exist" stopped
        // being the same statement as "this one was not created".
        $this->assertDatabaseMissing('items', ['slug' => $this->payload()['slug']]);
    }

    public function test_a_non_injecting_item_may_not_name_a_skill(): void
    {
        $skill = Skill::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload(['use_op' => '', 'use_skill_id' => $skill->id]))
            ->assertSessionHasErrors('use_skill_id');
    }

    public function test_a_capacity_must_say_what_it_holds(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload(['capacity' => 64, 'accepts' => '', 'stackable' => '0']))
            ->assertSessionHasErrors('accepts');
    }

    public function test_a_kind_without_capacity_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload(['capacity' => 0, 'accepts' => Item::CONTAINER_DATA]))
            ->assertSessionHasErrors('accepts');
    }

    // ---- the two rules SQL cannot express ---------------------------------

    /**
     * InventoryItem derives stack_key from (character, item, parent), so two
     * stackable containers merge into one row and the second one's contents
     * are gone. The schema cannot see that from a single row.
     */
    public function test_a_container_may_not_stack(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'capacity' => 64,
                'accepts' => Item::CONTAINER_DATA,
                'stackable' => '1',
            ]))
            ->assertSessionHasErrors('stackable');
    }

    /** One book per skill, or a skill has two names and forSkill() is ambiguous. */
    public function test_a_skill_cannot_have_two_books(): void
    {
        $skill = Skill::factory()->create();
        Item::factory()->imprintFor($skill)->create();

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'slug' => 'second_book',
                'name' => '',
                'container' => Item::CONTAINER_DATA,
                'use_op' => Item::USE_INJECT_SKILL,
                'use_skill_id' => $skill->id,
            ]))
            ->assertSessionHasErrors('use_skill_id');
    }

    /** Editing the book that already exists must not trip its own uniqueness. */
    public function test_editing_a_book_does_not_collide_with_itself(): void
    {
        $skill = Skill::factory()->create();
        $book = Item::factory()->imprintFor($skill)->create();

        $this->actingAs($this->admin)
            ->put("/admin/items/{$book->id}", $this->payload([
                'slug' => null,
                'name' => '',
                'container' => Item::CONTAINER_DATA,
                'use_op' => Item::USE_INJECT_SKILL,
                'use_skill_id' => $skill->id,
                'base_cost' => 1_234,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1_234, $book->fresh()->base_cost);
    }

    // ---- editing ----------------------------------------------------------

    public function test_an_admin_can_edit_an_item(): void
    {
        $item = Item::factory()->create(['slug' => 'shard', 'name' => 'Old name']);

        $this->actingAs($this->admin)
            ->put("/admin/items/{$item->id}", $this->payload(['slug' => null, 'name' => 'New name']))
            ->assertRedirect();

        $this->assertSame('New name', $item->fresh()->name);
    }

    /** A slug is permanent: inventory rows and the book naming both use it. */
    public function test_a_slug_cannot_be_changed(): void
    {
        $item = Item::factory()->create(['slug' => 'shard']);

        $this->actingAs($this->admin)
            ->put("/admin/items/{$item->id}", $this->payload(['slug' => 'renamed']))
            ->assertSessionHasErrors('slug');

        $this->assertSame('shard', $item->fresh()->slug);
    }

    // ---- deleting ---------------------------------------------------------

    public function test_deleting_an_item_takes_its_inventory_rows_with_it(): void
    {
        $character = Character::factory()->create();
        $item = Item::factory()->create();

        $row = InventoryItem::factory()->ownedBy($character)->of($item)->create();
        MarketPurchase::factory()->create(['item_id' => $item->id, 'character_id' => $character->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/items/{$item->id}")
            ->assertRedirect(route('admin.items.index'));

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
        $this->assertDatabaseMissing('inventory_items', ['id' => $row->id]);
        $this->assertDatabaseMissing('market_purchases', ['item_id' => $item->id]);
    }

    /**
     * parent_id cascades, so crushing the shard loses what was written on it.
     * That is the fiction, and it is also what stops orphan rows.
     */
    public function test_deleting_a_container_destroys_what_is_inside_it(): void
    {
        $character = Character::factory()->create();
        $shard = Item::factory()->physical()->holds(Item::CONTAINER_DATA)->create();
        $book = Item::factory()->data()->create();

        $card = InventoryItem::factory()->ownedBy($character)->of($shard)->create();
        $inside = InventoryItem::factory()->ownedBy($character)->of($book)->create(['parent_id' => $card->id]);

        $this->actingAs($this->admin)->delete("/admin/items/{$shard->id}")->assertRedirect();

        $this->assertDatabaseMissing('inventory_items', ['id' => $card->id]);
        $this->assertDatabaseMissing('inventory_items', ['id' => $inside->id]);
    }

    /** The confirmation has to say what it is about to destroy. */
    public function test_the_edit_screen_reports_what_a_delete_would_reach(): void
    {
        $character = Character::factory()->create();
        $item = Item::factory()->create();
        InventoryItem::factory()->ownedBy($character)->of($item)->quantity(3)->create();

        $this->actingAs($this->admin)
            ->get("/admin/items/{$item->id}/edit")
            ->assertOk()
            ->assertSee('1 inventory row')
            ->assertSee('3 copies players are carrying');
    }
}
