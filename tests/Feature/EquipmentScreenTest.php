<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\ArmourSlot;
use App\Combat\WeaponClass;
use App\Combat\WearSlot;
use App\Drugs\Family;
use App\Equipment\BeltEquipper;
use App\Equipment\BeltRefusal;
use App\Models\Armour;
use App\Models\Character;
use App\Models\Drug;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Weapon;
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

    public function test_a_piece_in_a_place_is_a_tag_that_opens_its_card_once(): void
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => 'Sleeved jacket', 'stackable' => false]);
        Armour::create(['item_id' => $item->id, 'slots' => ['torso', 'arm_left']]);
        $row = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->actingAs($this->character->user)->post(route('inventory.wear', $row))->assertRedirect();

        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        // On two places, so two tags, and one card between them.
        $this->assertSame(2, substr_count($html, 'data-inspect-open="#equipped-detail-'.$row->id.'"'));
        $this->assertSame(1, substr_count($html, '<template id="equipped-detail-'.$row->id.'"'));
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

    /** One dialog's table, from its opening tag to its close. */
    private function dialog(string $html, string $key): string
    {
        preg_match('/<dialog id="equip-'.$key.'".*?<\/dialog>/s', $html, $m);

        return $m[0] ?? '';
    }

    private function weapon(string $class, string $name): InventoryItem
    {
        $item = Item::factory()->create(['container' => 'physical', 'name' => $name, 'stackable' => false]);
        Weapon::create([
            'item_id' => $item->id, 'class' => WeaponClass::from($class), 'damage' => 6, 'damage_profile' => ['blunt' => 100],
            'accuracy' => 0, 'rate' => 1,
        ]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    public function test_the_lede_is_the_new_sentence_and_the_stash_line_is_gone(): void
    {
        $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()
            ->assertSee('Equipment like armour, weapons, consumables etc that you have equipped')
            ->assertDontSee('Things go on from your stash');
    }

    public function test_the_map_uses_the_short_names_for_the_hands(): void
    {
        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/bodymap__label[^>]*>Primary 0\/1</', $html);
        $this->assertMatchesRegularExpression('/bodymap__label[^>]*>Secondary 0\/1</', $html);
        $this->assertDoesNotMatchRegularExpression('/bodymap__label[^>]*>Secondary weapon/', $html);
    }

    public function test_every_place_has_an_equip_button_and_a_dialog_headed_with_its_name(): void
    {
        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        foreach (['head' => 'Head', 'torso' => 'Torso', 'arms' => 'Arms', 'legs' => 'Legs', 'belt' => 'Belt', 'primary' => 'Primary weapon', 'secondary' => 'Secondary weapon'] as $key => $label) {
            $this->assertStringContainsString('data-open="#equip-'.$key.'"', $html);
            $this->assertStringContainsString('Equip: '.$label, $this->dialog($html, $key));
            $this->assertStringContainsString('Nothing in your stash fits here.', $this->dialog($html, $key));
        }
    }

    public function test_each_dialog_lists_what_the_stash_holds_that_would_go_there(): void
    {
        $helmet = Item::factory()->create(['container' => 'physical', 'name' => 'Visor helm', 'stackable' => false]);
        Armour::create(['item_id' => $helmet->id, 'slots' => ['head']]);
        InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $helmet->id, 'quantity' => 1]);
        $this->drug('Calm pill');
        $this->weapon('pistol', 'Sidearm');
        $this->weapon('blade', 'Combat knife');

        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Visor helm', $this->dialog($html, 'head'));
        $this->assertStringNotContainsString('Visor helm', $this->dialog($html, 'torso'));
        $this->assertStringContainsString('Calm pill', $this->dialog($html, 'belt'));
        $this->assertStringContainsString('Sidearm', $this->dialog($html, 'primary'));
        $this->assertStringNotContainsString('Combat knife', $this->dialog($html, 'primary'));
        $this->assertStringContainsString('Combat knife', $this->dialog($html, 'secondary'));
        $this->assertStringNotContainsString('Sidearm', $this->dialog($html, 'secondary'));
    }

    public function test_something_already_equipped_is_not_offered_again(): void
    {
        $row = $this->weapon('pistol', 'Sidearm');
        $this->character->forceFill(['equipped_ranged_id' => $row->id])->save();

        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Sidearm', $this->dialog($html, 'primary'));
        $this->assertStringContainsString('Nothing in your stash fits here.', $this->dialog($html, 'primary'));
    }

    public function test_the_hands_are_the_right_for_ranged_and_the_left_for_close(): void
    {
        $html = $this->actingAs($this->character->user)->get(route('equipment.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Primary weapon.*right hand/s', $html);
        $this->assertMatchesRegularExpression('/Secondary weapon.*left hand/s', $html);
    }
}
