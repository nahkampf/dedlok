<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\DeletionImpact;
use App\Combat\AmmoType;
use App\Combat\ArmourSlot;
use App\Combat\Fight\CombatantBuilder;
use App\Combat\Fight\Range;
use App\Combat\Fight\Side;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\Item;
use App\Models\Npc;
use App\Models\Weapon;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * An opponent row becoming something a fight can use. docs/COMBAT.md, step 3.
 */
final class NpcTest extends TestCase
{
    use RefreshDatabase;

    private function weapon(WeaponClass $class, int $damage, array $extra = []): Item
    {
        $item = Item::factory()->physical()->create(['name' => ucfirst($class->value).' item', 'stackable' => false]);
        Weapon::create($extra + [
            'item_id' => $item->id, 'class' => $class, 'damage' => $class->firearm() ? null : $damage, 'damage_profile' => $class->firearm() ? null : CombatantBuilder::defaultProfile($class), 'accuracy' => 0, 'rate' => 1,
            'magazine' => $class->firearm() ? 12 : null, 'calibre' => $class->firearm() ? '9mm' : null,
        ]);

        return $item;
    }

    private function round(int $damage, array $extra = []): Item
    {
        $item = Item::factory()->physical()->create(['name' => 'Round', 'stackable' => true]);
        Ammunition::create($extra + ['item_id' => $item->id, 'calibre' => '9mm', 'type' => AmmoType::Standard, 'damage' => $damage, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return $item;
    }

    private function npc(array $attributes = []): Npc
    {
        return Npc::create($attributes + ['slug' => 'thug', 'name' => 'Thug', 'grade' => 2, 'hitpoints' => 50, 'aim' => 11, 'defence' => 9, 'pursuit' => 12]);
    }

    public function test_an_opponents_numbers_come_straight_off_its_row(): void
    {
        $foe = CombatantBuilder::forNpc($this->npc(['flees_at' => 0.25]));

        $this->assertSame('Thug', $foe->name);
        $this->assertSame(50, $foe->hp);
        $this->assertSame(50, $foe->hpMax);
        $this->assertSame(9.0, $foe->defence);
        $this->assertSame(12.0, $foe->pursuit);
        $this->assertSame(0.25, $foe->fleesAt);
        $this->assertNull($foe->si);
    }

    public function test_an_unarmed_opponent_fights_with_bare_hands_and_cannot_shoot(): void
    {
        $foe = CombatantBuilder::forNpc($this->npc());

        $this->assertNull($foe->ranged);
        $this->assertSame(WeaponClass::Unarmed, $foe->arm(Range::Close)->class);
        $this->assertSame(11.0, $foe->melee->aim, 'its own aim');
    }

    public function test_its_weapons_are_the_items_it_points_at_with_the_weapons_own_numbers(): void
    {
        $gun = $this->weapon(WeaponClass::Smg, 5, ['rate' => 3, 'accuracy' => 2, 'riders' => [['kind' => 'stun', 'chance' => 10, 'turns' => 1, 'amount' => 0]]]);
        $knife = $this->weapon(WeaponClass::Blade, 7);

        $foe = CombatantBuilder::forNpc($this->npc(['ranged_weapon_id' => $gun->id, 'melee_weapon_id' => $knife->id, 'ammo_item_id' => $this->round(5)->id]));

        $this->assertSame('Smg item', $foe->ranged->name);
        $this->assertSame(3, $foe->ranged->rate);
        $this->assertSame(13.0, $foe->ranged->aim, 'its aim plus the weapon\'s accuracy');
        $this->assertNull($foe->ranged->rounds, 'it never runs dry');
        $this->assertSame(5.0, $foe->ranged->spec()->damage);
        $this->assertSame(['stun'], array_column($foe->ranged->spec()->riders, 'kind'));
        $this->assertSame(['slashing' => 100], $foe->melee->spec()->profile);
        $this->assertSame(7.0, $foe->melee->spec()->damage);
        $this->assertSame(11.0, $foe->melee->aim);
    }

    public function test_a_standing_round_changes_the_ranged_weapon_as_a_loaded_one_would(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 0, ['riders' => [['kind' => 'stun', 'chance' => 10, 'turns' => 1, 'amount' => 0]]]);
        $round = $this->round(10, [
            'type' => AmmoType::Incendiary, 'penetration' => 50,
            'damage_profile' => ['penetrating' => 60, 'thermal' => 40], 'riders' => [['kind' => 'burning', 'chance' => 30, 'turns' => 3, 'amount' => 2]],
        ]);

        $spec = CombatantBuilder::forNpc($this->npc(['ranged_weapon_id' => $gun->id, 'ammo_item_id' => $round->id]))->ranged->spec();

        $this->assertSame(10.0, $spec->damage, 'the round\'s figure: the gun has none');
        $this->assertSame(50, $spec->penetration);
        $this->assertSame(['penetrating' => 60, 'thermal' => 40], $spec->profile, 'the round\'s profile');
        $this->assertSame(['stun', 'burning'], array_column($spec->riders, 'kind'), 'and its riders add');
    }

    public function test_a_firearm_with_no_standing_round_has_nothing_to_fire(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 0);

        $this->assertNull(CombatantBuilder::forNpc($this->npc(['ranged_weapon_id' => $gun->id]))->ranged);
    }

    public function test_an_opponents_resistances_apply_by_type_and_by_body_part(): void
    {
        $m = $this->npc(['resistances' => ['penetrating' => 20], 'slot_resistances' => ['head' => ['penetrating' => 40]]])->mitigation();
        $hit = fn (?ArmourSlot $at, int $pen = 0) => Damage::hitpoints(10, DamageOrigin::Penetrating, $at, $pen);

        $this->assertSame(4, $m->resolve(Side::B, $hit(ArmourSlot::Head)), 'a helmet: 60');
        $this->assertSame(8, $m->resolve(Side::B, $hit(ArmourSlot::Torso)));
        $this->assertSame(7, $m->resolve(Side::B, $hit(ArmourSlot::Head, 50)), 'penetration halves it');
        $this->assertSame(10, $this->npc(['slug' => 'bare'])->mitigation()->resolve(Side::B, $hit(ArmourSlot::Head)));
    }

    public function test_the_snapshot_is_taken_so_a_later_edit_does_not_reach_it(): void
    {
        $npc = $this->npc();
        $foe = CombatantBuilder::forNpc($npc);

        $npc->update(['hitpoints' => 1, 'name' => 'Changed']);

        $this->assertSame(50, $foe->hp);
        $this->assertSame('Thug', $foe->name);
    }

    public function test_the_delete_dialog_says_how_many_opponents_lose_a_weapon(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 5);
        $knife = $this->weapon(WeaponClass::Blade, 5);
        $this->npc(['ranged_weapon_id' => $gun->id, 'melee_weapon_id' => $knife->id]);
        $this->npc(['slug' => 'second', 'ranged_weapon_id' => $gun->id]);

        $this->assertSame(2, DeletionImpact::forItem($gun)->counts()['npc_arms']);
        $this->assertSame(1, DeletionImpact::forItem($knife)->counts()['npc_arms']);
        $this->assertArrayNotHasKey('npc_arms', DeletionImpact::forItem(Item::factory()->physical()->create())->counts());
    }

    // ---- the simulator ------------------------------------------------------------------

    private function character(): Character
    {
        return Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    public function test_the_simulator_fights_an_opponent_from_the_editor(): void
    {
        $character = $this->character();
        $this->npc(['slug' => 'weakling', 'name' => 'Weakling', 'hitpoints' => 1, 'defence' => 0, 'aim' => 0]);

        $this->artisan('fight:simulate', ['handle' => $character->handle, '--foe' => 'weakling', '--runs' => 30])
            ->expectsOutputToContain('against Weakling')
            ->assertExitCode(0);

        Artisan::call('fight:simulate', ['handle' => $character->handle, '--foe' => 'weakling', '--runs' => 30]);
        $this->assertStringContainsString('100.0%', Artisan::output(), 'a single hit point and no defence: bare hands win every time');
    }

    public function test_the_simulator_applies_the_opponents_resistances_and_refuses_an_unknown_one(): void
    {
        $character = $this->character();
        $this->npc(['slug' => 'wall', 'name' => 'Wall', 'hitpoints' => 30, 'resistances' => ['blunt' => 100]]);

        Artisan::call('fight:simulate', ['handle' => $character->handle, '--foe' => 'wall', '--runs' => 30]);
        $this->assertStringContainsString('0.0%', Artisan::output());
        $this->assertDoesNotMatchRegularExpression('/Win\s+\|\s+[1-9]/', Artisan::output(), 'fists do nothing to something immune to blunt');

        $this->artisan('fight:simulate', ['handle' => $character->handle, '--foe' => 'nobody'])
            ->expectsOutputToContain('No opponent')->assertExitCode(1);
    }
}
