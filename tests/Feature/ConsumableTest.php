<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\Fight\FightEngine;
use App\Combat\Fights;
use App\Equipment\BeltEquipper;
use App\Items\ConsumableEditor;
use App\Items\Consumer;
use App\Items\UseRefusal;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * The consumable: an item that heals current hit points once, at once, when a copy is used (a medkit).
 */
final class ConsumableTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    private function medkit(int $heal = 20, int $quantity = 2, string $name = 'Medkit'): InventoryItem
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => $name, 'stackable' => true]);
        ConsumableEditor::apply($item, [['kind' => 'heal_hitpoints', 'amount' => $heal]]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity]);
    }

    private function hurt(int $to): void
    {
        $this->character->forceFill(['current_hitpoints' => $to])->save();
        $this->character->refresh();
    }

    private function consumer(): Consumer
    {
        return new Consumer;
    }

    public function test_using_one_heals_and_spends_one_copy(): void
    {
        $row = $this->medkit(heal: 5, quantity: 2);
        $max = $this->character->maximumFor('current_hitpoints');
        $this->hurt($max - 10);

        $result = $this->consumer()->use($this->character, $row);

        $this->assertSame(['heal_hitpoints' => 5], $result);
        $this->assertSame($max - 5, $this->character->fresh()->current_hitpoints);
        $this->assertSame(1, $row->fresh()->quantity);
    }

    public function test_the_heal_stops_at_the_maximum_and_the_last_copy_takes_its_row(): void
    {
        $row = $this->medkit(heal: 500, quantity: 1);
        $max = $this->character->maximumFor('current_hitpoints');
        $this->hurt($max - 3);

        $this->assertSame(['heal_hitpoints' => 3], $this->consumer()->use($this->character, $row));

        $this->assertSame($max, $this->character->fresh()->current_hitpoints);
        $this->assertNull(InventoryItem::find($row->id));
    }

    public function test_at_full_health_it_is_refused_and_the_copy_is_kept(): void
    {
        $row = $this->medkit();

        $this->assertSame(UseRefusal::NothingToDo, $this->consumer()->use($this->character, $row));
        $this->assertSame(2, $row->fresh()->quantity);
    }

    public function test_it_refuses_what_it_should(): void
    {
        $other = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $row = $this->medkit();
        $this->hurt(1);

        $this->assertSame(UseRefusal::NotOwned, $this->consumer()->use($other, $row));

        $plain = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => Item::factory()->create(['container' => 'physical'])->id, 'quantity' => 1]);
        $this->assertSame(UseRefusal::NotUsable, $this->consumer()->use($this->character, $plain));

        // Inside a container is not to hand.
        $shard = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => Item::factory()->create(['container' => 'physical'])->id, 'quantity' => 1]);
        $inside = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $row->item_id, 'quantity' => 1, 'parent_id' => $shard->id]);
        $this->assertSame(UseRefusal::NotFree, $this->consumer()->use($this->character, $inside));

        $this->character->forceFill(['current_fight_id' => null])->save();
    }

    public function test_it_cannot_be_used_in_the_middle_of_a_fight(): void
    {
        $row = $this->medkit();
        $this->hurt(1);
        $npc = Npc::query()->create(['slug' => 'dummy', 'name' => 'Dummy', 'grade' => 1, 'hitpoints' => 10, 'aim' => 5, 'defence' => 5, 'pursuit' => 5]);
        $fights = new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([]));
        $fights->open($this->character->fresh(), $npc);

        $this->assertNotNull($this->character->fresh()->current_fight_id);
        $this->assertSame(UseRefusal::InAFight, $this->consumer()->use($this->character->fresh(), $row));
        $this->assertSame(2, $row->fresh()->quantity);
    }

    public function test_the_route_uses_one_and_says_what_it_did(): void
    {
        $row = $this->medkit(heal: 5);
        $this->hurt($this->character->maximumFor('current_hitpoints') - 10);

        $this->actingAs($this->character->user)->post(route('inventory.use', $row))
            ->assertRedirect()->assertSessionHas('status', 'You use Medkit. You recover 5 hit points.');

        $this->hurt($this->character->maximumFor('current_hitpoints'));
        $this->actingAs($this->character->user)->post(route('inventory.use', $row))
            ->assertRedirect()->assertSessionHas('error', 'It would do nothing for you right now.');

        $stranger = User::factory()->create();
        Character::factory()->create(['user_id' => $stranger->id, 'archetype' => 3]);
        $this->actingAs($stranger)->post(route('inventory.use', $row))->assertNotFound();
    }

    public function test_the_stash_card_offers_use_and_says_what_it_does(): void
    {
        $this->medkit(heal: 20);

        $this->actingAs($this->character->user)->get(route('inventory.index'))->assertOk()
            ->assertSee(route('inventory.use', InventoryItem::query()->latest('id')->first()), false)
            ->assertSee('Restores 20 hit points');
    }

    public function test_a_consumable_can_ride_the_belt_and_is_offered_on_the_equipment_screen(): void
    {
        $row = $this->medkit(heal: 20, name: 'Trauma kit');

        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();
        preg_match('/<dialog id="equip-belt".*?<\\/dialog>/s', $html, $dialog);
        $this->assertStringContainsString('Trauma kit', $dialog[0]);

        $this->assertTrue((new BeltEquipper)->put($this->character, $row));
        $this->assertSame(1, $row->fresh()->belt_slot);
    }

    // ---- the editor --------------------------------------------------------------------------------------

    private function save(array $override = [], ?Item $item = null): TestResponse
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payload = array_merge([
            'slug' => 'field_medkit', 'name' => 'Field medkit', 'container' => Item::CONTAINER_PHYSICAL, 'tier' => 1, 'rarity' => 'common',
            'base_cost' => 50, 'weight' => 1, 'size' => 1, 'capacity' => 0, 'seed_quantity' => 0, 'stackable' => 1,
        ], $override);

        // A slug is permanent, so an update does not take one.
        unset($payload['slug']);

        return $item === null
            ? $this->actingAs($admin)->post(route('admin.items.store'), $payload + ['slug' => $override['slug'] ?? 'field_medkit'])
            : $this->actingAs($admin)->put(route('admin.items.update', $item), $payload);
    }

    public function test_the_editor_makes_an_item_a_consumable_and_unmakes_it(): void
    {
        $this->save(['use_heal_hitpoints' => '25'])->assertRedirect();

        $item = Item::query()->where('slug', 'field_medkit')->sole();
        $this->assertTrue($item->isConsumable());
        $this->assertSame([['kind' => 'heal_hitpoints', 'amount' => 25]], $item->consumable->effects);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.items.edit', $item))->assertOk()->assertSee('value="25"', false);

        $this->save(['use_heal_hitpoints' => ''], $item)->assertRedirect();
        $this->assertFalse($item->fresh()->isConsumable(), 'blank clears the row');
    }

    public function test_the_editor_stores_allowed_in_combat_and_clears_it_with_the_effects(): void
    {
        $this->save(['use_heal_hitpoints' => '25', 'usable_in_fight' => '1'])->assertRedirect();
        $item = Item::query()->where('slug', 'field_medkit')->sole();
        $this->assertTrue($item->consumable->usable_in_fight);

        $this->save(['use_heal_hitpoints' => '25'], $item)->assertRedirect();
        $this->assertFalse($item->fresh()->consumable->usable_in_fight, 'unticked is off');

        // A box ticked on something that is not a consumable means nothing: there is no row to carry it.
        $this->save(['use_heal_hitpoints' => '', 'usable_in_fight' => '1'], $item)->assertRedirect();
        $this->assertFalse($item->fresh()->isConsumable());
    }

    public function test_a_consumable_may_also_be_a_drug_and_is_nothing_else(): void
    {
        $this->save(['use_heal_hitpoints' => '25', 'drug_family' => 'stimulant', 'duration_minutes' => 10, 'addictive' => 1, 'tolerance_gain' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $item = Item::query()->where('slug', 'field_medkit')->sole();
        $this->assertTrue($item->isConsumable());
        $this->assertTrue($item->isDrug());

        $this->save(['slug' => 'armoured_kit', 'use_heal_hitpoints' => '25', 'armour_slots' => ['head']])->assertSessionHasErrors();
        // Blank is the only way to say none: zero and a fraction are mistakes.
        $this->save(['use_heal_hitpoints' => '0'])->assertSessionHasErrors('use_heal_hitpoints');
        $this->save(['use_heal_hitpoints' => '2.5'])->assertSessionHasErrors('use_heal_hitpoints');
        $this->assertSame(1, Item::query()->where('slug', 'field_medkit')->count(), 'only the drug-and-consumable one was saved');
    }
}
