<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Achievements\Achievements;
use App\Achievements\Metric;
use App\Checks\CheckRoller;
use App\Combat\Fight\Action;
use App\Combat\Fight\FightEngine;
use App\Combat\Fights;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\Fight;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * What a WON fight is worth to the achievements: only a fight where most of the player's damage came from the blade
 * (or the Katana) in the close hand, and the opponent for the blade one carries a firearm.
 */
final class FightAchievementsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    private function wield(string $slug, WeaponClass $class, array $weapon = []): InventoryItem
    {
        Item::query()->where('slug', $slug)->delete(); // the real Katana arrives with the migrations
        $item = Item::factory()->physical()->create(['name' => ucfirst($slug), 'slug' => $slug, 'stackable' => false]);
        Weapon::create(['item_id' => $item->id, 'class' => $class, 'accuracy' => 0, 'rate' => 1] + $weapon);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function pistol(): void
    {
        $row = $this->wield('pistol', WeaponClass::Pistol, ['magazine' => 12, 'calibre' => '9mm']);
        $round = Item::factory()->physical()->create(['name' => '9mm', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => '9mm', 'type' => 'full_metal_jacket', 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);
        $row->forceFill(['loaded_ammo_id' => $round->id, 'loaded_rounds' => 12])->save();
        InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $round->id, 'quantity' => 24]);
        $this->character->forceFill(['equipped_ranged_id' => $row->id])->save();
    }

    private function foe(): Npc
    {
        $gun = Item::factory()->physical()->create(['name' => 'Gang pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $gun->id, 'class' => WeaponClass::Pistol, 'accuracy' => 0, 'rate' => 1, 'magazine' => 12, 'calibre' => 'gang']);
        $round = Item::factory()->physical()->create(['name' => 'Gang round', 'stackable' => true]);
        Ammunition::create(['item_id' => $round->id, 'calibre' => 'gang', 'type' => 'standard', 'damage' => 5, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return Npc::create(['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => 40, 'aim' => 99, 'defence' => 0, 'pursuit' => 8, 'ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]);
    }

    /** One player's blow that finishes the foe (it has one hit point), at the given distance. */
    private function finish(int $distance): void
    {
        $fights = new Fights(new FightEngine(CheckRoller::fromConfig()), new QueueDice([0.0, 0.5, 0.5, 0.5]));
        $fight = $fights->open($this->character, $this->foe());
        $this->assertInstanceOf(Fight::class, $fight);

        $state = $fight->state;
        $state['distance'] = $distance;
        $state['you_at'] = intdiv($distance, 2);
        $state['b']['hp'] = 1;
        $state['first'] = 'a';
        $fight->forceFill(['state' => $state])->save();

        $done = $fights->act($this->character->fresh(), Action::attack());
        $this->assertInstanceOf(Fight::class, $done);
        $this->assertSame('b_down', $done->fresh()->result->value);
    }

    private function won(Metric $metric): int
    {
        return (new Achievements)->progressFor($this->character, $metric);
    }

    private function equipMelee(InventoryItem $row): void
    {
        $this->character->forceFill(['equipped_melee_id' => $row->id])->save();
    }

    public function test_a_katana_that_did_the_damage_counts_for_the_katana_and_for_the_blade(): void
    {
        $this->equipMelee($this->wield('katana', WeaponClass::Blade, ['damage' => 20, 'damage_profile' => ['slashing' => 100]]));

        $this->finish(0);

        $this->assertSame(1, $this->won(Metric::KatanaWins));
        $this->assertSame(1, $this->won(Metric::BladeVsFirearmWins));
    }

    public function test_another_blade_counts_for_the_blade_only(): void
    {
        $this->equipMelee($this->wield('sword', WeaponClass::Blade, ['damage' => 20, 'damage_profile' => ['slashing' => 100]]));

        $this->finish(0);

        $this->assertSame(0, $this->won(Metric::KatanaWins));
        $this->assertSame(1, $this->won(Metric::BladeVsFirearmWins));
    }

    public function test_a_katana_in_the_belt_does_not_count_when_the_gun_did_the_damage(): void
    {
        $this->equipMelee($this->wield('katana', WeaponClass::Blade, ['damage' => 20, 'damage_profile' => ['slashing' => 100]]));
        $this->pistol();

        $this->finish(2);

        $this->assertSame(0, $this->won(Metric::KatanaWins));
        $this->assertSame(0, $this->won(Metric::BladeVsFirearmWins));
    }
}
