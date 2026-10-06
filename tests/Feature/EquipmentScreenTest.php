<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\ArmourSlot;
use App\Combat\WearSlot;
use App\Drugs\Family;
use App\Equipment\BeltEquipper;
use App\Equipment\BeltRefusal;
use App\Models\Armour;
use App\Models\Character;
use App\Models\Drug;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Equipment tab: four armour places, a belt of three, and a weapon in each hand.
 */
final class EquipmentScreenTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    private function drug(string $name = 'Stim'): InventoryItem
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => $name, 'stackable' => true]);
        Drug::query()->create(['item_id' => $item->id, 'family' => Family::Stimulant, 'duration_minutes' => 30, 'addictive' => 1, 'tolerance_gain' => 1]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 3]);
    }

    private function belt(): BeltEquipper
    {
        return new BeltEquipper;
    }

    public function test_the_tab_is_there_and_names_every_place(): void
    {
        $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()
            ->assertSeeText('Equipment')
            ->assertSeeInOrder(['Head', 'Torso', 'Arms', 'Legs', 'Belt', 'Primary weapon', 'Secondary weapon'])
            ->assertSee(route('equipment.index'), false);

        $this->actingAs($this->character->user)->get(route('character.index'))->assertOk()->assertSee(route('equipment.index'), false);
    }

    public function test_a_piece_on_the_arms_protects_both_and_shows_once(): void
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => 'Sleeved jacket', 'stackable' => false]);
        Armour::create(['item_id' => $item->id, 'slots' => ['torso', 'arm_left']]);   // stored one-sided on purpose
        $row = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);

        $this->actingAs($this->character->user)->post(route('inventory.wear', $row))->assertRedirect();

        $slots = $this->character->wornArmour()->pluck('slot')->map(fn ($s) => $s->value)->sort()->values()->all();
        $this->assertSame(['arm_left', 'arm_right', 'torso'], $slots);
        $this->assertSame([WearSlot::Torso, WearSlot::Arms], $item->fresh()->armour->wornOn());

        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'Sleeved jacket</span>'), 'once on Torso and once on Arms, not once per arm');
        $this->assertContains(ArmourSlot::ArmRight, $item->fresh()->armour->covers());
    }

    public function test_a_drug_goes_on_the_belt_in_the_first_free_slot_and_comes_off_again(): void
    {
        $stim = $this->drug();

        $this->assertTrue($this->belt()->put($this->character, $stim));
        $this->assertSame(1, $stim->fresh()->belt_slot);
        $this->assertTrue($stim->fresh()->isOnPerson());

        $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->assertSeeText('Stim')->assertSeeText('×3');

        $this->assertTrue($this->belt()->take($this->character, $stim->fresh()));
        $this->assertNull($stim->fresh()->belt_slot);
    }

    public function test_only_drugs_go_on_the_belt_and_the_belt_holds_three(): void
    {
        $plain = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => Item::factory()->create(['container' => 'physical'])->id, 'quantity' => 1]);
        $this->assertSame(BeltRefusal::NotBeltable, $this->belt()->put($this->character, $plain));

        foreach (['A', 'B', 'C'] as $name) {
            $this->assertTrue($this->belt()->put($this->character, $this->drug($name)));
        }

        $this->assertSame(BeltRefusal::BeltFull, $this->belt()->put($this->character, $this->drug('D')));
        $this->assertSame([1, 2, 3], InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('belt_slot')->orderBy('belt_slot')->pluck('belt_slot')->all());
    }

    public function test_taking_a_belted_pile_off_merges_it_into_the_loose_pile(): void
    {
        $belted = $this->drug();
        $this->belt()->put($this->character, $belted);

        // More of the same arrives while the first pile is on the belt: its own pile, not the belted one.
        $loose = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $belted->item_id, 'quantity' => 2]);
        $this->assertNotSame($belted->id, $loose->id);

        $this->belt()->take($this->character, $belted->fresh());

        $this->assertNull(InventoryItem::find($belted->id), 'the belted row merged away');
        $this->assertSame(5, $loose->fresh()->quantity);
    }

    public function test_the_route_toggles_and_somebody_elses_row_is_not_found(): void
    {
        $stim = $this->drug();

        $this->actingAs($this->character->user)->post(route('inventory.belt', $stim))->assertRedirect()->assertSessionHas('status', 'You put Stim on your belt.');
        $this->actingAs($this->character->user)->post(route('inventory.belt', $stim))->assertRedirect()->assertSessionHas('status', 'You take Stim off your belt.');

        $other = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->actingAs($other->user)->post(route('inventory.belt', $stim))->assertNotFound();
    }

    public function test_the_hands_are_the_right_for_ranged_and_the_left_for_close(): void
    {
        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Primary weapon.*right hand/s', $html);
        $this->assertMatchesRegularExpression('/Secondary weapon.*left hand/s', $html);
    }
}
