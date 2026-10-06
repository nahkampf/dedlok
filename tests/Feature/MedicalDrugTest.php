<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Drugs\DrugLedger;
use App\Drugs\DrugTaker;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\Drug;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Medical family: treatment that acts for a while. Overdoses like a stimulant, is never addictive, tolerance per drug.
 */
final class MedicalDrugTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(12, 1, 0));
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    /** A Medical drug that mends 60 hit points an hour while it lasts: a point a minute for ten minutes. */
    private function drip(float $addictive = 0.0): InventoryItem
    {
        $item = Item::factory()->create([
            'container' => 'physical', 'name' => 'Trauma drip', 'stackable' => true,
            'modifiers' => [['op' => 'add', 'amount' => 60, 'target' => ['id' => 'hitpoints_regen']]],
        ]);
        Drug::query()->create(['item_id' => $item->id, 'family' => Family::Medical, 'duration_minutes' => 10, 'addictive' => $addictive, 'tolerance_gain' => 0]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 5]);
    }

    public function test_the_family_exists_is_labelled_and_never_addicts(): void
    {
        $this->assertSame('Medical', Family::Medical->label());
        $this->assertFalse(Family::Medical->addicts());
        $this->assertTrue(Family::Stimulant->addicts());
    }

    public function test_it_overdoses_like_a_stimulant(): void
    {
        $this->assertSame(config('drugs.overdose.families.stimulant'), config('drugs.overdose.families.medical'));
        $this->assertSame(['hp_per_excess' => 4, 'lethal_at' => 3], config('drugs.overdose.families.medical'));
    }

    /** THE USE CASE: a timed dose that carries HP regeneration, and the minute sweep mending the body while it lasts. */
    public function test_a_medical_drug_can_carry_hit_point_regeneration_for_a_while(): void
    {
        $row = $this->drip();
        $max = $this->character->maximumFor('current_hitpoints');
        $this->character->forceFill(['current_hitpoints' => $max - 20])->save();

        (new DrugTaker)->take($this->character->fresh(), $row);

        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes();
            $this->artisan('characters:regenerate')->assertSuccessful();
        }
        $this->assertSame($max - 17, $this->character->fresh()->current_hitpoints, 'a point a minute while it works');

        // Past its ten minutes, the dose wears off and so does the mending.
        $this->travel(20)->minutes();
        $before = $this->character->fresh()->current_hitpoints;
        $this->artisan('characters:regenerate')->assertSuccessful();
        $this->assertSame($before, $this->character->fresh()->current_hitpoints);
    }

    public function test_taking_one_never_raises_addiction_even_if_the_row_says_so(): void
    {
        $row = $this->drip(addictive: 80.0);   // a hand-made row; the editor would refuse it

        (new DrugTaker)->take($this->character->fresh(), $row);

        $this->assertSame(0.0, (float) (new DrugLedger)->for($this->character->fresh(), Family::Medical)->addiction);
        $this->assertFalse((new DrugLedger)->isHooked($this->character->fresh(), Family::Medical));
    }

    public function test_reconciling_a_family_that_cannot_hook_does_not_look_for_a_withdrawal(): void
    {
        // No `effects.withdrawal_medical` exists, and none is needed: this must not fail loudly.
        $this->assertFalse((new DrugLedger)->reconcile($this->character, Family::Medical));
    }

    public function test_the_editor_offers_the_family_and_refuses_an_addictive_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $base = [
            'name' => 'Dermal patch', 'container' => 'physical', 'tier' => 1, 'rarity' => 'common', 'base_cost' => 10,
            'weight' => 1, 'size' => 1, 'capacity' => 0, 'seed_quantity' => 0, 'stackable' => 1,
            'drug_family' => 'medical', 'duration_minutes' => 10, 'tolerance_gain' => 0,
            'modifiers' => [['op' => 'add', 'amount' => '30', 'target' => 'hitpoints_regen', 'carried' => '']],
        ];

        $this->actingAs($admin)->get(route('admin.items.create'))->assertOk()->assertSee('value="medical"', false);

        $this->actingAs($admin)->post(route('admin.items.store'), $base + ['slug' => 'dermal_patch', 'addictive' => '5'])->assertSessionHasErrors('addictive');
        $this->assertDatabaseMissing('items', ['slug' => 'dermal_patch']);

        $this->actingAs($admin)->post(route('admin.items.store'), $base + ['slug' => 'dermal_patch', 'addictive' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(Family::Medical, Item::query()->where('slug', 'dermal_patch')->sole()->drug->family);
    }
}
