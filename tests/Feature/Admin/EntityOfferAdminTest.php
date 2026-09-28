<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Entity;
use App\Models\EntityOffer;
use App\Models\Item;
use App\Models\User;
use App\Standing\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authoring a loyalty shelf.
 *
 * THE POINT OF THE SCREEN: content that cannot be authored is content only a
 * developer can add. The shelf shipped before its editor did, and every desk
 * read "holding nothing for you today" because the only way to fill one was a
 * direct write to the database.
 */
final class EntityOfferAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();

        $this->entity = Entity::query()->interactable()->firstOrFail();

        // Start from empty shelves: a migration puts Lockjaw's heat service
        // on one, and these tests count rows.
        EntityOffer::query()->delete();
    }

    public function test_the_index_lists_entities_and_what_they_keep(): void
    {
        $this->shelve(price: 25);

        $this->actingAs($this->admin)
            ->get(route('admin.entities.index'))
            ->assertOk()
            ->assertSeeText($this->entity->name);
    }

    public function test_a_row_goes_onto_the_shelf(): void
    {
        $item = Item::factory()->create(['on_market' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'item_id' => $item->id,
                'loyalty_price' => 250,
                'min_grade' => 2,
                'max_grade' => 4,
                'min_standing' => 0,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $offer = EntityOffer::query()->sole();

        $this->assertSame($this->entity->id, $offer->entity_id);
        $this->assertSame(250, $offer->loyalty_price);
        $this->assertSame([2, 4], [$offer->min_grade, $offer->max_grade]);
    }

    /**
     * THE BLANK ROW IS THE COMMON ROW.
     *
     * A shelf entry is usually an item and a price: available at every desk,
     * with no standing gate beyond the desk's own. Leaving those three empty
     * has to mean the default rather than a zero, or the common case is the
     * one that needs the most typing.
     */
    public function test_the_span_and_the_gate_default(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'item_id' => $item->id,
                'loyalty_price' => 10,
                'min_grade' => '',
                'max_grade' => '',
                'min_standing' => '',
            ])
            ->assertSessionHasNoErrors();

        $offer = EntityOffer::query()->sole();

        $this->assertSame([1, 5], [$offer->min_grade, $offer->max_grade], 'every desk');
        $this->assertSame(0.0, $offer->min_standing);
    }

    public function test_an_inverted_span_is_refused(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'item_id' => $item->id,
                'loyalty_price' => 10,
                'min_grade' => 4,
                'max_grade' => 2,
            ])
            ->assertSessionHasErrors('min_grade');

        $this->assertSame(0, EntityOffer::query()->count());
    }

    /** One offer per item per entity — the rule the unique key keeps. */
    public function test_the_same_item_cannot_be_shelved_twice(): void
    {
        $offer = $this->shelve(price: 10);

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'item_id' => $offer->item_id,
                'loyalty_price' => 5,
            ])
            ->assertSessionHasErrors('item_id');

        $this->assertSame(1, EntityOffer::query()->count());
    }

    /** Editing a row must not trip over its own uniqueness. */
    public function test_a_row_can_be_repriced(): void
    {
        $offer = $this->shelve(price: 10);

        $this->actingAs($this->admin)
            ->put(route('admin.entities.offers.update', [$this->entity, $offer]), [
                'item_id' => $offer->item_id,
                'loyalty_price' => 999,
                'min_grade' => 1,
                'max_grade' => 1,
            ])
            ->assertSessionHasNoErrors();

        $offer->refresh();

        $this->assertSame(999, $offer->loyalty_price);
        $this->assertSame([1, 1], [$offer->min_grade, $offer->max_grade]);
    }

    /** A service row carries no item, and says how much it does. */
    public function test_a_service_goes_onto_the_shelf(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'item_id' => '',
                'service' => 'shed_heat',
                'service_amount' => 10,
                'loyalty_price' => 1000,
            ])
            ->assertSessionHasNoErrors();

        $offer = EntityOffer::query()->sole();

        $this->assertNull($offer->item_id);
        $this->assertSame(Service::ShedHeat, $offer->service);
        $this->assertSame(10.0, $offer->service_amount);

        // And it can be repriced from the table, which posts its hidden fields back.
        $this->actingAs($this->admin)
            ->put(route('admin.entities.offers.update', [$this->entity, $offer]), [
                'item_id' => '',
                'service' => 'shed_heat',
                'service_amount' => '10.0',
                'loyalty_price' => 800,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(800, $offer->fresh()->loyalty_price);
    }

    /** An item OR a service — never both, never neither. */
    public function test_a_row_is_one_kind_or_the_other(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'item_id' => $item->id,
                'service' => 'shed_heat',
                'service_amount' => 10,
                'loyalty_price' => 10,
            ])
            ->assertSessionHasErrors('item_id');

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), ['loyalty_price' => 10])
            ->assertSessionHasErrors('item_id');

        $this->actingAs($this->admin)
            ->post(route('admin.entities.offers.store', $this->entity), [
                'service' => 'shed_heat',
                'loyalty_price' => 10,
            ])
            ->assertSessionHasErrors('service_amount');

        $this->assertSame(0, EntityOffer::query()->count());
    }

    public function test_a_row_can_be_taken_off(): void
    {
        $offer = $this->shelve(price: 10);

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.offers.destroy', [$this->entity, $offer]))
            ->assertRedirect();

        $this->assertSame(0, EntityOffer::query()->count());
    }

    /** Somebody else's shelf is a row that does not exist as far as this entity is concerned. */
    public function test_another_entitys_row_is_a_404(): void
    {
        $other = Entity::query()->where('id', '!=', $this->entity->id)->firstOrFail();
        $offer = $this->shelve(price: 10);

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.offers.destroy', [$other, $offer]))
            ->assertNotFound();

        $this->assertSame(1, EntityOffer::query()->count());
    }

    /**
     * WHAT THEY SAY ABOUT THEMSELVES IS EDITABLE; what they ARE is not.
     *
     * Both lines are player-visible flavour with no mechanical weight, and
     * until this screen a typo in either was unfixable without a migration.
     */
    public function test_the_flavour_can_be_rewritten(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.entities.update', $this->entity), [
                'business' => 'Private security, mostly.',
                'description' => 'They answer the phone at four in the morning.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->entity->refresh();

        $this->assertSame('Private security, mostly.', $this->entity->business);
        $this->assertSame('They answer the phone at four in the morning.', $this->entity->description);
    }

    /** Blank is NULL, not an empty string: scenery has no long form at all. */
    public function test_clearing_the_long_form_writes_null(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.entities.update', $this->entity), [
                'business' => 'Still something.',
                'description' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->entity->fresh()->description);
    }

    /** The shelf screen shows who it belongs to, not only what they sell. */
    public function test_the_shelf_screen_carries_the_entitys_own_words(): void
    {
        $this->entity->update([
            'business' => 'A memorable line.',
            'description' => 'And a longer one underneath it.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.entities.edit', $this->entity))
            ->assertOk()
            ->assertSee('A memorable line.', false)
            ->assertSee('And a longer one underneath it.', false)
            ->assertSeeText($this->entity->category->label());
    }

    public function test_the_item_picker_marks_what_the_market_does_not_sell(): void
    {
        $sold = Item::factory()->create(['name' => 'Sold Thing', 'slug' => 'sold-thing', 'on_market' => true]);
        $unsold = Item::factory()->create(['name' => 'Unsold Thing', 'slug' => 'unsold-thing', 'on_market' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.entities.edit', $this->entity))
            ->assertOk()
            ->assertSee("⊘ Unsold Thing ({$unsold->slug})", false)
            ->assertDontSee("⊘ Sold Thing ({$sold->slug})", false)
            ->assertSee("Sold Thing ({$sold->slug})", false);
    }

    public function test_a_shelved_row_is_marked_when_the_market_does_not_sell_it(): void
    {
        $unsold = Item::factory()->create(['name' => 'Unsold Thing', 'on_market' => false]);
        $this->entity->offers()->create(['item_id' => $unsold->id, 'loyalty_price' => 10, 'min_grade' => 1, 'max_grade' => 5, 'min_standing' => 0]);

        $this->actingAs($this->admin)
            ->get(route('admin.entities.edit', $this->entity))
            ->assertOk()
            ->assertSeeInOrder(['<td>', '⊘</span>', 'Unsold Thing'], false);
    }

    public function test_it_is_behind_the_admin_gate(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)
            ->get(route('admin.entities.index'))
            ->assertForbidden();
    }

    private function shelve(int $price): EntityOffer
    {
        return $this->entity->offers()->create([
            'item_id' => Item::factory()->create()->id,
            'min_grade' => 1,
            'max_grade' => 5,
            'loyalty_price' => $price,
            'min_standing' => 0,
        ]);
    }
}
