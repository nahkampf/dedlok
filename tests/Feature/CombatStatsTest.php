<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\WeaponClass;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Stats\CombatStats;
use App\Stats\Resolver;
use App\Stats\StatRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The numbers a fight reads: aim and weapon damage per weapon class, and defence. COMBAT.md.
 */
final class CombatStatsTest extends TestCase
{
    use RefreshDatabase;

    private function character(array $attributes = []): Character
    {
        return Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3], $attributes));
    }

    public function test_every_weapon_class_brings_an_aim_and_a_damage_stat(): void
    {
        $registry = app(StatRegistry::class);

        foreach (WeaponClass::cases() as $class) {
            $this->assertSame(['combat', 'aim', $class->value], $registry->get(CombatStats::aimId($class))->tags);
            $this->assertSame(['combat', 'weapon_damage', $class->value], $registry->get(CombatStats::damageId($class))->tags);
        }
    }

    public function test_aim_is_a_pool_of_motor_and_senses_the_same_for_every_class(): void
    {
        $character = $this->character(['motor' => 18, 'senses' => 12]);

        foreach (WeaponClass::cases() as $class) {
            $this->assertEqualsWithDelta(10.0, $character->stat(CombatStats::aimId($class))->value, 0.001, $class->value);
        }

        $this->assertEqualsWithDelta(13.3, $this->character()->stat('aim.pistol')->value, 0.001, 'a starting character is near 13');
    }

    public function test_an_aim_modifier_reaches_one_class_or_all_of_them(): void
    {
        $implant = fn (array $target) => Item::factory()->physical()->create([
            'implant_region' => 'arms', 'stackable' => false,
            'modifiers' => [['op' => 'add', 'amount' => 3, 'target' => $target]],
        ]);

        $character = $this->character();
        $install = fn (Item $item) => InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1, 'installed_at' => now()]);

        $install($implant(['id' => 'aim.pistol']));
        $character = $character->fresh();
        $this->assertEqualsWithDelta(16.3, $character->stat('aim.pistol')->value, 0.001);
        $this->assertEqualsWithDelta(13.3, $character->stat('aim.rifle')->value, 0.001, 'only the pistol');

        $install($implant(['all_of' => ['aim']]));
        $character = $character->fresh();
        $this->assertEqualsWithDelta(19.3, $character->stat('aim.pistol')->value, 0.001);
        $this->assertEqualsWithDelta(16.3, $character->stat('aim.rifle')->value, 0.001, 'every class');
    }

    public function test_weapon_damage_takes_its_base_with_the_event_and_is_not_rounded(): void
    {
        $resolver = app(Resolver::class);
        $id = CombatStats::damageId(WeaponClass::Pistol);

        $this->assertEqualsWithDelta(9.6, $resolver->resolve($id, 9.6)->value, 0.0001, 'unrounded, because a tier scales it before it is a whole number');
        $this->assertSame(0.0, $resolver->resolve($id, -3.0)->value, 'floored at nothing');
    }

    public function test_weapon_damage_has_no_base_on_a_sheet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->character()->stat('weapon_damage.pistol');
    }

    public function test_defence_is_a_declared_stat_with_a_base_and_modifiers_reach_it(): void
    {
        $this->assertSame(8.0, $this->character()->stat('defence')->value);

        $character = $this->character();
        $item = Item::factory()->physical()->create([
            'implant_region' => 'legs', 'stackable' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => 25, 'target' => ['id' => 'defence']]],
        ]);
        InventoryItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'quantity' => 1, 'installed_at' => now()]);

        $this->assertSame(10.0, $character->fresh()->stat('defence')->value);
    }

    public function test_the_stats_are_named_so_the_editor_can_offer_them(): void
    {
        $names = (array) config('strings.character.names');

        foreach (WeaponClass::cases() as $class) {
            $this->assertArrayHasKey(CombatStats::aimId($class), $names);
            $this->assertArrayHasKey(CombatStats::damageId($class), $names);
        }

        foreach (['defence', 'aim', 'weapon_damage'] as $key) {
            $this->assertArrayHasKey($key, $names);
        }
    }
}
