<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Combat\WeaponClass;
use App\Decks\Hardware;
use App\Drugs\Family;
use App\Items\ConsumableEditor;
use App\Models\Armour;
use App\Models\Drug;
use App\Models\Item;
use App\Models\User;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The item list's one Type filter: every kind of item, not only deck hardware. */
final class ItemTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    private function names(string $type): array
    {
        $html = $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/items?type='.urlencode($type))->assertOk()->getContent();

        return collect(['Alpha Plain', 'Beta Medic', 'Gamma Stim', 'Delta Vest', 'Epsilon Knife', 'Zeta Kit', 'Eta Cpu'])->filter(fn (string $n): bool => str_contains($html, $n))->values()->all();
    }

    public function test_each_kind_finds_its_own_items(): void
    {
        Item::factory()->create(['container' => 'physical', 'slug' => '0-alpha-plain', 'name' => 'Alpha Plain']);
        $medic = Item::factory()->create(['container' => 'physical', 'slug' => '0-beta-medic', 'name' => 'Beta Medic']);
        Drug::query()->create(['item_id' => $medic->id, 'family' => Family::Medical, 'duration_minutes' => 10, 'addictive' => 0, 'tolerance_gain' => 0]);
        $stim = Item::factory()->create(['container' => 'physical', 'slug' => '0-gamma-stim', 'name' => 'Gamma Stim']);
        Drug::query()->create(['item_id' => $stim->id, 'family' => Family::Stimulant, 'fight_only' => true, 'duration_minutes' => 0, 'duration_turns' => 3, 'addictive' => 0, 'tolerance_gain' => 0]);
        $vest = Item::factory()->create(['container' => 'physical', 'slug' => '0-delta-vest', 'name' => 'Delta Vest', 'stackable' => false]);
        Armour::create(['item_id' => $vest->id, 'slots' => ['torso']]);
        $knife = Item::factory()->create(['container' => 'physical', 'slug' => '0-epsilon-knife', 'name' => 'Epsilon Knife', 'stackable' => false]);
        Weapon::create(['item_id' => $knife->id, 'class' => WeaponClass::Blade, 'damage' => 4, 'damage_profile' => ['slashing' => 100], 'accuracy' => 0, 'rate' => 1]);
        $kit = Item::factory()->create(['container' => 'physical', 'slug' => '0-zeta-kit', 'name' => 'Zeta Kit']);
        ConsumableEditor::apply($kit, [['kind' => 'heal_hitpoints', 'amount' => 5]]);
        Item::factory()->create(['container' => 'data', 'slug' => '0-eta-cpu', 'name' => 'Eta Cpu', 'hardware' => Hardware::Cpu]);

        $this->assertSame(['Beta Medic', 'Gamma Stim'], $this->names('drug'));
        $this->assertSame(['Beta Medic'], $this->names('drug:medical'));
        $this->assertSame(['Gamma Stim'], $this->names('drug:fight_only'));
        $this->assertSame(['Delta Vest'], $this->names('armour'));
        $this->assertSame(['Epsilon Knife'], $this->names('weapon'));
        $this->assertSame(['Zeta Kit'], $this->names('consumable'));
        $this->assertSame(['Eta Cpu'], $this->names('hardware:cpu'));
        $this->assertSame(['Eta Cpu'], $this->names('hardware'));
        $this->assertSame(['Alpha Plain'], $this->names('plain'));
        // An unknown type narrows nothing (the same page as no filter at all).
        $this->assertSame($this->names(''), $this->names('nonsense'));
    }

    public function test_the_dropdown_lists_every_group_and_the_old_urls_still_work(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/items')->assertOk()
            ->assertSee('Any type')->assertSee('Deck hardware')->assertSee('Software')->assertSee('Drugs')->assertSee('Armour')->assertSee('Consumable')->assertSee('Eidetic imprint')
            ->assertSee('value="drug:medical"', false)->assertSee('value="software:icebreaker"', false);
        $this->actingAs($admin)->get('/admin/items?hardware=cpu')->assertOk();
    }
}
