<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\ArmourSlot;
use App\Combat\ArmourWearer;
use App\Combat\Fight\CharacterMitigation;
use App\Combat\Fight\CombatantBuilder;
use App\Combat\Fight\NpcMitigation;
use App\Combat\Fight\Range;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Armour;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Weapon;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\DamageStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What turns a character or a description into the engine's snapshot, and what answers for
 * resistance. COMBAT.md, *The engine's shape*.
 */
final class FightAdaptersTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    private function give(Item $item, int $quantity = 1, array $extra = []): InventoryItem
    {
        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity] + $extra);
    }

    private function pistol(array $riders = []): InventoryItem
    {
        $item = Item::factory()->physical()->create(['name' => 'Pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $item->id, 'class' => WeaponClass::Pistol, 'accuracy' => 2, 'rate' => 3, 'magazine' => 12, 'calibre' => '9mm', 'riders' => $riders ?: null]);

        $row = $this->give($item);
        $this->character->forceFill(['equipped_ranged_id' => $row->id])->save();

        return $row;
    }

    private function round(string $name, string $type, int $damage = 8, int $pen = 0, string $calibre = '9mm', ?array $riders = null, ?array $profile = null): Item
    {
        $item = Item::factory()->physical()->create(['name' => $name, 'stackable' => true]);
        Ammunition::create(['item_id' => $item->id, 'calibre' => $calibre, 'type' => $type, 'damage' => $damage, 'penetration' => $pen, 'damage_profile' => $profile ?? ['penetrating' => 100], 'riders' => $riders]);

        return $item;
    }

    // ---- an NPC's resistance ------------------------------------------------------------

    private function hit(int $amount, string $type = 'penetrating', ?ArmourSlot $at = null, int $pen = 0): Damage
    {
        return Damage::hitpoints($amount, DamageOrigin::from($type), $at, $pen);
    }

    public function test_an_npc_resists_by_type_and_a_vulnerability_adds(): void
    {
        $m = new NpcMitigation(['penetrating' => 60, 'thermal' => -25]);

        $this->assertSame(4, $m->resolve(Side::B, $this->hit(10)));
        $this->assertSame(10, $m->resolve(Side::B, $this->hit(10, 'blunt')), 'no entry, no effect');
        $this->assertSame(13, $m->resolve(Side::B, $this->hit(10, 'thermal')), 'a quarter more, 12.5 rounding away from zero');
    }

    public function test_a_body_part_adds_to_the_type_and_a_hit_with_no_place_ignores_it(): void
    {
        $m = new NpcMitigation(['penetrating' => 20], ['head' => ['penetrating' => 40]]);

        $this->assertSame(4, $m->resolve(Side::B, $this->hit(10, 'penetrating', ArmourSlot::Head)), 'a helmet: 60 in all');
        $this->assertSame(8, $m->resolve(Side::B, $this->hit(10, 'penetrating', ArmourSlot::Torso)), 'no vest: just the 20');
        $this->assertSame(8, $m->resolve(Side::B, $this->hit(10, 'penetrating')), 'a burn strikes no part');
    }

    public function test_penetration_scales_a_resistance_and_nothing_else(): void
    {
        $m = new NpcMitigation(['penetrating' => 60, 'thermal' => -25, 'chemical' => 100]);

        $this->assertSame(7, $m->resolve(Side::B, $this->hit(10, 'penetrating', null, 50)), '30% off');
        $this->assertSame(10, $m->resolve(Side::B, $this->hit(10, 'penetrating', null, 100)));
        $this->assertSame(1, $m->resolve(Side::B, $this->hit(10, 'penetrating', null, -50)), '90% off');
        $this->assertSame(13, $m->resolve(Side::B, $this->hit(10, 'thermal', null, 50)), 'a vulnerability is not softened');
        $this->assertSame(0, $m->resolve(Side::B, $this->hit(10, 'chemical', null, 100)), 'an immunity cannot be pierced');
    }

    // ---- a character's resistance -------------------------------------------------------

    public function test_a_characters_mitigation_is_the_resolvers_own_sum_and_applies_nothing(): void
    {
        $item = Item::factory()->physical()->create([
            'stackable' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => -60, 'target' => ['id' => DamageStats::id('current_hitpoints', DamageOrigin::Penetrating)]]],
        ]);
        Armour::create(['item_id' => $item->id, 'slots' => ['torso']]);
        $this->assertTrue(app(ArmourWearer::class)->wear($this->character, $this->give($item)));

        $character = $this->character->fresh();
        $mitigation = new CharacterMitigation($character);
        $hpBefore = $character->current_hitpoints;

        foreach ([[ArmourSlot::Torso, 0], [ArmourSlot::Torso, 50], [ArmourSlot::LegLeft, 0], [null, 0]] as [$at, $pen]) {
            $hit = $this->hit(10, 'penetrating', $at, $pen);

            $this->assertSame(DamageResolver::make()->preview($character, $hit)->toInt(), $mitigation->resolve(Side::A, $hit));
        }

        $this->assertSame(4, $mitigation->resolve(Side::A, $this->hit(10, 'penetrating', ArmourSlot::Torso)));
        $this->assertSame($hpBefore, $character->fresh()->current_hitpoints, 'asked, never applied');
    }

    // ---- building a character -----------------------------------------------------------

    public function test_a_characters_numbers_come_from_their_stats(): void
    {
        $you = CombatantBuilder::forCharacter($this->character->fresh());

        $this->assertSame($this->character->current_hitpoints, $you->hp);
        $this->assertSame((int) $this->character->stat('hitpoints_max')->value, $you->hpMax);
        $this->assertSame($this->character->current_synaptic_integrity, $you->si);
        $this->assertSame(8.0, $you->defence);
        $this->assertSame(8.0, $you->pursuit);
        $this->assertNull($you->ranged, 'no ranged weapon in hand');
    }

    public function test_bare_hands_are_the_close_fallback(): void
    {
        $you = CombatantBuilder::forCharacter($this->character->fresh());

        $hands = $you->arm(Range::Close);
        $this->assertSame(WeaponClass::Unarmed, $hands->class);
        $this->assertSame((float) config('combat.unarmed.damage'), $hands->spec()->damage);
        $this->assertSame(['blunt' => 100], $hands->spec()->profile);
        $this->assertNull($hands->rounds, 'fists do not run dry');
        $this->assertEqualsWithDelta($this->character->stat('aim.unarmed')->value, $hands->aim, 1e-9);
    }

    public function test_a_firearm_carries_every_round_it_could_fire_with_its_stash_and_what_is_loaded(): void
    {
        $gun = $this->pistol([['kind' => 'stun', 'chance' => 10, 'turns' => 1, 'amount' => 0]]);
        $fmj = $this->round('9mm FMJ', 'full_metal_jacket');
        $hollow = $this->round('9mm Hollow point', 'hollow_point', damage: 10, pen: -50, riders: [['kind' => 'burning', 'chance' => 30, 'turns' => 3, 'amount' => 2]]);
        $this->round('.45 ACP', 'standard', calibre: '.45');                                       // not this gun's
        $this->give($fmj, 30);
        $this->give($hollow, 10);
        $this->give($this->round('.45 ball', 'full_metal_jacket', calibre: '.45'), 99);
        $gun->forceFill(['loaded_ammo_id' => $fmj->id, 'loaded_rounds' => 5])->save();

        $arm = CombatantBuilder::forCharacter($this->character->fresh())->ranged;

        $this->assertSame('Pistol', $arm->name);
        $this->assertSame(3, $arm->rate);
        $this->assertSame(12, $arm->magazine);
        $this->assertSame((string) $fmj->id, $arm->loaded);
        $this->assertSame(5, $arm->rounds);
        $this->assertEqualsCanonicalizing([(string) $fmj->id, (string) $hollow->id], array_keys($arm->specs), 'only this calibre');

        $this->assertSame(30, $arm->specs[(string) $fmj->id]->stock);
        $this->assertSame(10, $arm->specs[(string) $hollow->id]->stock);
        $this->assertEqualsWithDelta(8.0, $arm->specs[(string) $fmj->id]->damage, 1e-9);
        $this->assertEqualsWithDelta(10.0, $arm->specs[(string) $hollow->id]->damage, 1e-9, 'the round\'s own figure');
        $this->assertSame(-50, $arm->specs[(string) $hollow->id]->penetration);

        $this->assertSame(['stun'], array_column($arm->specs[(string) $fmj->id]->riders, 'kind'));
        $this->assertSame(['stun', 'burning'], array_column($arm->specs[(string) $hollow->id]->riders, 'kind'), 'a round adds to the weapon');
        $this->assertEqualsWithDelta($this->character->stat('aim.pistol')->value + 2, $arm->aim, 1e-9, 'aim plus the weapon\'s accuracy');
    }

    public function test_a_round_carries_its_own_profile(): void
    {
        $this->pistol();
        $incendiary = $this->round('9mm Incendiary', 'incendiary', profile: ['penetrating' => 60, 'thermal' => 40]);
        $this->give($incendiary, 5);

        $spec = CombatantBuilder::forCharacter($this->character->fresh())->ranged->specs[(string) $incendiary->id];

        $this->assertSame(['penetrating' => 60, 'thermal' => 40], $spec->profile);
    }

    public function test_the_round_in_the_magazine_is_there_even_with_none_left_in_the_stash(): void
    {
        $gun = $this->pistol();
        $fmj = $this->round('9mm FMJ', 'full_metal_jacket');
        $gun->forceFill(['loaded_ammo_id' => $fmj->id, 'loaded_rounds' => 7])->save();

        $arm = CombatantBuilder::forCharacter($this->character->fresh())->ranged;

        $this->assertSame(0, $arm->specs[(string) $fmj->id]->stock);
        $this->assertSame(7, $arm->rounds);
        $this->assertTrue($arm->canFire());
    }

    public function test_an_empty_magazine_is_nothing_loaded(): void
    {
        $this->pistol();
        $this->give($this->round('9mm FMJ', 'full_metal_jacket'), 30);

        $arm = CombatantBuilder::forCharacter($this->character->fresh())->ranged;

        $this->assertNull($arm->loaded);
        $this->assertSame(0, $arm->rounds);
        $this->assertFalse($arm->canFire());
        $this->assertTrue($arm->canLoad((string) array_key_first($arm->specs)));
    }

    public function test_a_melee_weapon_is_one_spec_that_never_runs_dry(): void
    {
        $item = Item::factory()->physical()->create(['name' => 'Katana', 'stackable' => false]);
        Weapon::create(['item_id' => $item->id, 'class' => WeaponClass::Blade, 'damage' => 9, 'damage_profile' => ['slashing' => 100], 'accuracy' => 0, 'rate' => 1]);
        $row = $this->give($item);
        $this->character->forceFill(['equipped_melee_id' => $row->id])->save();

        $arm = CombatantBuilder::forCharacter($this->character->fresh())->melee;

        $this->assertSame('Katana', $arm->name);
        $this->assertNull($arm->rounds);
        $this->assertEqualsWithDelta(9.0, $arm->spec()->damage, 1e-9);
    }

    public function test_an_implant_that_helps_aim_or_damage_reaches_the_snapshot(): void
    {
        $this->pistol();
        $this->give($this->round('9mm FMJ', 'full_metal_jacket'), 5);
        $before = CombatantBuilder::forCharacter($this->character->fresh())->ranged;

        $implant = Item::factory()->physical()->create(['implant_region' => 'arms', 'stackable' => false, 'modifiers' => [
            ['op' => 'add', 'amount' => 3, 'target' => ['id' => 'aim.pistol']],
            ['op' => 'percentage_of_base', 'amount' => 50, 'target' => ['id' => 'weapon_damage.pistol']],
        ]]);
        $this->give($implant, 1, ['installed_at' => now()]);

        $after = CombatantBuilder::forCharacter($this->character->fresh())->ranged;

        $this->assertEqualsWithDelta($before->aim + 3, $after->aim, 1e-9);
        $this->assertEqualsWithDelta(12.0, $after->spec()?->damage ?? array_values($after->specs)[0]->damage, 1e-9, '+50% of 8');
    }

    // ---- building an opponent -----------------------------------------------------------

    public function test_an_opponent_from_a_description(): void
    {
        $foe = CombatantBuilder::fromSpec([
            'name' => 'Thug', 'hp' => 30, 'aim' => 11, 'defence' => 9, 'pursuit' => 12, 'flees_at' => 0.25,
            'ranged' => ['class' => 'shotgun', 'damage' => 7, 'rate' => 1],
        ]);

        $this->assertSame('Thug', $foe->name);
        $this->assertSame(30, $foe->hpMax);
        $this->assertNull($foe->si, 'an NPC has no mind to hurt');
        $this->assertSame(0.25, $foe->fleesAt);
        $this->assertSame(11.0, $foe->ranged->aim);
        $this->assertNull($foe->ranged->rounds, 'an NPC never counts ammunition');
        $this->assertSame(['penetrating' => 30, 'fragmentation' => 70], $foe->ranged->spec()->profile, 'the class default');
        $this->assertSame(WeaponClass::Unarmed, $foe->melee->class, 'bare hands at Close');
    }
}
