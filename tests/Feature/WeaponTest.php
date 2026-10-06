<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\AmmoType;
use App\Combat\DamageProfile;
use App\Combat\HitQuality;
use App\Combat\RiderKind;
use App\Combat\Riders;
use App\Combat\WeaponClass;
use App\Combat\WeaponEditor;
use App\Combat\WeaponWielder;
use App\Combat\WieldRefusal;
use App\Inventory\ItemPlacer;
use App\Inventory\PlacementRefusal;
use App\Inventory\SkillInjector;
use App\Market\OfferBook;
use App\Market\OfferRefusal;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\User;
use App\Models\Weapon;
use App\Stats\DamageOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Weapons, and which of them a character is holding. COMBAT.md.
 *
 * A fighter holds one weapon of each kind — a firearm is usable only at Ranged and a blade only
 * at Close — so the weapon's class says which hand it fills, and the player picks the weapon.
 */
final class WeaponTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    /** A weapon item and a copy of it carried. */
    private function weapon(WeaponClass $class, string $name = 'A weapon', array $profile = ['penetrating' => 100]): InventoryItem
    {
        $item = Item::factory()->physical()->create(['name' => $name, 'stackable' => false]);

        Weapon::create([
            'item_id' => $item->id, 'class' => $class, 'damage' => 8, 'damage_profile' => $profile, 'accuracy' => 0, 'rate' => 1,
            'magazine' => $class->firearm() ? 12 : null, 'calibre' => $class->firearm() ? '9mm' : null,
        ]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function wielder(): WeaponWielder
    {
        return app(WeaponWielder::class);
    }

    // ---- the vocabulary ---------------------------------------------------------

    public function test_a_class_says_which_hand_and_whether_it_takes_a_magazine(): void
    {
        foreach ([WeaponClass::Pistol, WeaponClass::Smg, WeaponClass::Rifle, WeaponClass::Shotgun, WeaponClass::Explosive] as $ranged) {
            $this->assertTrue($ranged->ranged(), $ranged->value);
        }

        foreach ([WeaponClass::Blade, WeaponClass::Blunt, WeaponClass::Unarmed] as $close) {
            $this->assertFalse($close->ranged(), $close->value);
            $this->assertFalse($close->firearm());
        }

        $this->assertFalse(WeaponClass::Explosive->firearm(), 'thrown, so no magazine and no calibre');
        $this->assertTrue(WeaponClass::Shotgun->firearm());
    }

    public function test_the_eight_types_are_the_origins_that_name_a_kind_of_hurt(): void
    {
        $this->assertSame(
            ['blunt', 'slashing', 'penetrating', 'fragmentation', 'electrical', 'thermal', 'chemical', 'neural'],
            array_map(fn (DamageOrigin $o): string => $o->value, DamageOrigin::types()),
        );
        $this->assertSame(DamageProfile::types(), array_map(fn (DamageOrigin $o): string => $o->value, DamageOrigin::types()));
    }

    // ---- the profile --------------------------------------------------------------

    public function test_a_profile_is_cleaned_to_positive_whole_shares_in_a_fixed_order(): void
    {
        $this->assertSame(
            ['blunt' => 70, 'electrical' => 30],
            DamageProfile::clean(['electrical' => '30', 'blunt' => '70', 'thermal' => '', 'chemical' => '0', 'neural' => null]),
        );
    }

    public function test_a_profile_must_name_only_types_and_add_up_to_exactly_100(): void
    {
        $this->assertNull(DamageProfile::problem(['slashing' => 100]));
        $this->assertNull(DamageProfile::problem(['blunt' => 70, 'electrical' => 30, 'thermal' => '', 'neural' => '0']));
        $this->assertSame('sum', DamageProfile::problem(['blunt' => 70, 'electrical' => 20]));
        $this->assertSame('sum', DamageProfile::problem([]));
        $this->assertSame('unknown_type', DamageProfile::problem(['ice' => 100]), 'a cause is not a type');
        $this->assertSame('bad_share', DamageProfile::problem(['blunt' => 'lots']));
        $this->assertSame('bad_share', DamageProfile::problem(['blunt' => 150]));
        $this->assertSame('bad_share', DamageProfile::problem(['blunt' => 50.5]));
    }

    // ---- holding it ---------------------------------------------------------------

    public function test_the_card_of_a_gated_weapon_says_what_it_requires(): void
    {
        Skill::factory()->create(['slug' => 'pistols', 'title' => 'Pistols']);
        $this->weapon(WeaponClass::Pistol, 'Pistol');
        $this->weapon(WeaponClass::Blade, 'Blade');

        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSee('Requires Pistols 1')->assertDontSee('Requires Blades');
    }

    public function test_a_gated_class_needs_level_one_in_its_skill_and_a_blade_needs_nothing(): void
    {
        $skill = Skill::factory()->create(['slug' => 'pistols']);
        $pistol = $this->weapon(WeaponClass::Pistol, 'Pistol');
        $blade = $this->weapon(WeaponClass::Blade, 'Blade');
        $injector = app(SkillInjector::class);

        // Not injected: refused. Injected at level 0 is still untrained: refused.
        $this->assertSame(WieldRefusal::RequiresSkill, $this->wielder()->wield($this->character, $pistol));
        $injector->grant($this->character, $skill, 0);
        $this->assertSame(WieldRefusal::RequiresSkill, $this->wielder()->wield($this->character->fresh(), $pistol));
        $this->assertTrue($this->wielder()->wield($this->character->fresh(), $blade));

        CharacterSkill::query()->where('character_id', $this->character->id)->update(['attained_level' => 1]);
        $this->assertTrue($this->wielder()->wield($this->character->fresh(), $pistol));
    }

    public function test_a_weapon_fills_the_hand_its_class_names(): void
    {
        $pistol = $this->weapon(WeaponClass::Pistol, 'Pistol');
        $katana = $this->weapon(WeaponClass::Blade, 'Katana');

        $this->assertTrue($this->wielder()->wield($this->character, $pistol));
        $this->assertTrue($this->wielder()->wield($this->character, $katana));

        $character = $this->character->fresh();
        $this->assertSame($pistol->id, $character->equipped_ranged_id);
        $this->assertSame($katana->id, $character->equipped_melee_id, 'one of each, independently');
        $this->assertTrue($pistol->isWielded());
        $this->assertTrue($katana->fresh()->isWielded());
    }

    public function test_picking_one_up_swaps_what_was_in_that_hand(): void
    {
        $first = $this->weapon(WeaponClass::Pistol, 'First');
        $second = $this->weapon(WeaponClass::Rifle, 'Second');

        $this->wielder()->wield($this->character, $first);
        $this->wielder()->wield($this->character->fresh(), $second);

        $this->assertSame($second->id, $this->character->fresh()->equipped_ranged_id);
        $this->assertFalse($first->fresh()->isWielded());
    }

    public function test_putting_it_down_empties_the_hand(): void
    {
        $knife = $this->weapon(WeaponClass::Blade);
        $this->wielder()->wield($this->character, $knife);

        $this->assertTrue($this->wielder()->unwield($this->character->fresh(), $knife));

        $this->assertNull($this->character->fresh()->equipped_melee_id, 'bare hands');
        $this->assertSame(WieldRefusal::NotWielded, $this->wielder()->unwield($this->character->fresh(), $knife));
    }

    public function test_it_refuses_what_it_should(): void
    {
        $pistol = $this->weapon(WeaponClass::Pistol);

        $stranger = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->assertSame(WieldRefusal::NotOwned, $this->wielder()->wield($stranger, $pistol));

        $plain = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => Item::factory()->physical()->create()->id, 'quantity' => 1]);
        $this->assertSame(WieldRefusal::NotAWeapon, $this->wielder()->wield($this->character, $plain));

        $this->wielder()->wield($this->character, $pistol);
        $this->assertSame(WieldRefusal::AlreadyWielded, $this->wielder()->wield($this->character->fresh(), $pistol));
    }

    public function test_a_weapon_inside_a_container_cannot_be_picked_up(): void
    {
        $bag = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->physical()->create(['capacity' => 10, 'accepts' => 'physical'])->id,
            'quantity' => 1,
        ]);
        $pistol = $this->weapon(WeaponClass::Pistol);
        $pistol->forceFill(['parent_id' => $bag->id])->save();

        $this->assertSame(WieldRefusal::NotFree, $this->wielder()->wield($this->character, $pistol->fresh()));
    }

    public function test_destroying_the_weapon_empties_the_hand(): void
    {
        $pistol = $this->weapon(WeaponClass::Pistol);
        $this->wielder()->wield($this->character, $pistol);

        $pistol->delete();

        $this->assertNull($this->character->fresh()->equipped_ranged_id);
    }

    public function test_a_held_weapon_cannot_be_listed_or_put_away(): void
    {
        $pistol = $this->weapon(WeaponClass::Pistol);
        $this->wielder()->wield($this->character, $pistol);

        $this->assertSame(OfferRefusal::Worn, app(OfferBook::class)->list($this->character->fresh(), $pistol, 100));

        $bag = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->physical()->create(['capacity' => 10, 'accepts' => 'physical'])->id,
            'quantity' => 1,
        ]);
        $this->assertSame(PlacementRefusal::Worn, app(ItemPlacer::class)->place($this->character->fresh(), $pistol, $bag)->refusal);
    }

    // ---- the editor's side ----------------------------------------------------------

    public function test_an_item_that_stops_being_a_weapon_is_put_down_by_everybody(): void
    {
        $pistol = $this->weapon(WeaponClass::Pistol);
        $this->wielder()->wield($this->character, $pistol);

        WeaponEditor::apply($pistol->item, null);

        $this->assertNull(Weapon::query()->where('item_id', $pistol->item_id)->first());
        $this->assertNull($this->character->fresh()->equipped_ranged_id);
    }

    public function test_a_weapon_that_changes_hands_is_put_down_but_one_that_stays_is_kept(): void
    {
        $pistol = $this->weapon(WeaponClass::Pistol);
        $this->wielder()->wield($this->character, $pistol);

        // Retuned, still a Ranged weapon: it stays in the hand.
        WeaponEditor::apply($pistol->item, ['class' => WeaponClass::Rifle, 'damage' => 12, 'damage_profile' => ['penetrating' => 100], 'accuracy' => 0, 'rate' => 3, 'magazine' => 30, 'calibre' => '9mm']);
        $this->assertSame($pistol->id, $this->character->fresh()->equipped_ranged_id);

        // Re-classed as a blade: the Ranged hand it was in no longer fits it.
        WeaponEditor::apply($pistol->item->fresh(), ['class' => WeaponClass::Blade, 'damage' => 12, 'damage_profile' => ['slashing' => 100], 'accuracy' => 0, 'rate' => 1, 'magazine' => null, 'calibre' => null]);
        $this->assertNull($this->character->fresh()->equipped_ranged_id);
    }

    // ---- the screens -------------------------------------------------------------------

    public function test_the_stash_card_holds_and_puts_down_and_the_sheet_says_what_is_in_hand(): void
    {
        $knife = $this->weapon(WeaponClass::Blade, 'Combat knife');

        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSeeText('Equip')->assertDontSeeText('Unequip');

        $this->actingAs($this->character->user)->get(route('equipment.index'))
            ->assertOk()->assertSeeText('Primary weapon')->assertSeeText('Secondary weapon')->assertSeeText('Bare hands')->assertSeeText('Legs');

        $this->actingAs($this->character->user)->post(route('inventory.wield', $knife))
            ->assertRedirect()->assertSessionHas('status', 'You equip Combat knife.');

        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSeeText('Unequip')->assertSeeText('Equipped');
        $this->actingAs($this->character->user)->get(route('equipment.index'))
            ->assertOk()->assertSeeText('Combat knife')->assertDontSeeText('Bare hands');
        $this->actingAs($this->character->user)->get(route('character.index'))
            ->assertOk()->assertDontSeeText('Primary weapon');

        $this->actingAs($this->character->user)->post(route('inventory.wield', $knife))
            ->assertSessionHas('status', 'You unequip Combat knife.');
        $this->assertNull($this->character->fresh()->equipped_melee_id);
    }

    public function test_nobody_picks_up_somebody_elses_weapon(): void
    {
        $knife = $this->weapon(WeaponClass::Blade);
        $other = User::factory()->create();
        Character::factory()->create(['user_id' => $other->id, 'archetype' => 3]);

        $this->actingAs($other)->post(route('inventory.wield', $knife))->assertNotFound();
    }

    public function test_hit_quality_is_five_rungs_each_a_share_of_full_damage(): void
    {
        $shares = [];

        foreach (HitQuality::cases() as $quality) {
            $shares[$quality->label()] = $quality->fraction();
        }

        $this->assertSame(['Near miss' => 0.1, 'Glancing' => 0.25, 'Partial' => 0.5, 'Clean hit' => 0.75, 'Dead-on' => 1.0], $shares);
        $this->assertSame([1, 3, 5, 8, 10], array_map(fn (HitQuality $q): int => $q->scale(10), HitQuality::cases()));
        $this->assertSame(0, HitQuality::NearMiss->scale(0));
    }

    public function test_every_ammunition_type_has_a_label(): void
    {
        foreach (AmmoType::cases() as $type) {
            $this->assertNotSame("combat.ammo_types.{$type->value}", $type->label());
        }
    }

    // ---- riders --------------------------------------------------------------------

    public function test_a_damaging_rider_takes_its_damage_type_from_its_kind(): void
    {
        $this->assertNull(RiderKind::Stun->damageType());
        $this->assertSame(DamageOrigin::Thermal, RiderKind::Burning->damageType());
        $this->assertSame(DamageOrigin::Chemical, RiderKind::Poisoned->damageType());
        $this->assertFalse(RiderKind::Stun->damaging());
    }

    public function test_riders_are_cleaned_to_a_fixed_order_and_a_blank_row_is_none(): void
    {
        $clean = Riders::clean([
            'poisoned' => ['chance' => '20', 'turns' => '4', 'amount' => '3'],
            'stun' => ['chance' => '10', 'turns' => '1', 'amount' => ''],
            'burning' => ['chance' => '', 'turns' => '', 'amount' => ''],
        ]);

        $this->assertSame([
            ['kind' => 'stun', 'chance' => 10, 'turns' => 1, 'amount' => 0],
            ['kind' => 'poisoned', 'chance' => 20, 'turns' => 4, 'amount' => 3],
        ], $clean);
    }

    public function test_riders_are_judged_as_a_whole(): void
    {
        $this->assertNull(Riders::problem([]));
        $this->assertNull(Riders::problem(['burning' => ['chance' => '', 'turns' => '', 'amount' => '']]));
        $this->assertNull(Riders::problem(['stun' => ['chance' => '10', 'turns' => '2', 'amount' => '']]));
        $this->assertNull(Riders::problem(['burning' => ['chance' => '30', 'turns' => '3', 'amount' => '2']]));

        $this->assertSame('unknown_kind', Riders::problem(['frozen' => ['chance' => '10', 'turns' => '2']]));
        $this->assertSame('bad_chance', Riders::problem(['burning' => ['chance' => '', 'turns' => '3', 'amount' => '2']]));
        $this->assertSame('bad_chance', Riders::problem(['burning' => ['chance' => '101', 'turns' => '3', 'amount' => '2']]));
        $this->assertSame('bad_turns', Riders::problem(['burning' => ['chance' => '30', 'turns' => '0', 'amount' => '2']]));
        $this->assertSame('bad_turns', Riders::problem(['burning' => ['chance' => '30', 'turns' => '11', 'amount' => '2']]));
        $this->assertSame('bad_amount', Riders::problem(['poisoned' => ['chance' => '30', 'turns' => '3', 'amount' => '']]));
        $this->assertSame('stun_amount', Riders::problem(['stun' => ['chance' => '30', 'turns' => '1', 'amount' => '5']]));
    }

    public function test_a_rounds_riders_add_to_the_weapons_rather_than_replacing_them(): void
    {
        $stun = ['kind' => 'stun', 'chance' => 10, 'turns' => 1, 'amount' => 0];
        $burn = ['kind' => 'burning', 'chance' => 30, 'turns' => 3, 'amount' => 2];

        $this->assertSame([$stun, $burn], Riders::combine([$stun], [$burn]));
        $this->assertSame([$stun], Riders::combine([$stun], null));
        $this->assertSame([$burn], Riders::combine([], [$burn]));
    }
}
