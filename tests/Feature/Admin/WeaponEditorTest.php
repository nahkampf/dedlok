<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Combat\AmmoType;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Item;
use App\Models\User;
use App\Models\Weapon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Weapon and Ammunition panels of the item editor, and everything they refuse.
 */
final class WeaponEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array<string, mixed> */
    private function base(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'test_gear',
            'name' => 'Test gear',
            'container' => Item::CONTAINER_PHYSICAL,
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 100,
            'weight' => 1,
            'size' => 1,
            'capacity' => 0,
            'seed_quantity' => 0,
        ], $overrides);
    }

    /** @return array<string, mixed> a valid pistol: no damage, no profile, its round carries both */
    private function pistol(array $overrides = []): array
    {
        return $this->base(array_merge([
            'weapon_class' => 'pistol', 'weapon_accuracy' => 0, 'weapon_rate' => 1,
            'weapon_magazine' => 12, 'weapon_calibre' => '9mm',
        ], $overrides));
    }

    /** @return array<string, mixed> valid ammunition */
    private function round(array $overrides = []): array
    {
        return $this->base(array_merge([
            'stackable' => '1', 'ammo_calibre' => '9mm', 'ammo_type' => 'hollow_point', 'ammo_damage' => 10, 'ammo_penetration' => -50,
            'ammo_profile' => ['penetrating' => '100'],
        ], $overrides));
    }

    private function save(array $payload): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.items.store'), $payload);
    }

    // ---- weapons ---------------------------------------------------------------------

    public function test_a_firearm_is_saved_with_its_class_magazine_and_calibre_and_no_damage_of_its_own(): void
    {
        $this->save($this->pistol())->assertRedirect();

        $weapon = Item::query()->where('slug', 'test_gear')->sole()->weapon;

        $this->assertSame(WeaponClass::Pistol, $weapon->class);
        $this->assertNull($weapon->damage, 'the round carries it');
        $this->assertSame([], $weapon->profile());
        $this->assertSame(12, $weapon->magazine);
        $this->assertSame('9mm', $weapon->calibre);
    }

    public function test_a_blade_takes_no_magazine_or_calibre_and_a_profile_may_combine_types(): void
    {
        $this->save($this->base([
            'weapon_class' => 'blunt', 'weapon_damage' => 6, 'weapon_accuracy' => 0, 'weapon_rate' => 1,
            'weapon_profile' => ['blunt' => '70', 'electrical' => '30', 'thermal' => '', 'neural' => '0'],
        ]))->assertRedirect();

        $weapon = Item::query()->where('slug', 'test_gear')->sole()->weapon;

        $this->assertNull($weapon->magazine);
        $this->assertNull($weapon->calibre);
        $this->assertSame(['blunt' => 70, 'electrical' => 30], $weapon->profile(), 'a stun baton');
    }

    public function test_the_editor_shows_the_weapon_back(): void
    {
        $this->save($this->pistol())->assertRedirect();
        $item = Item::query()->where('slug', 'test_gear')->sole();

        $html = $this->actingAs($this->admin())->get(route('admin.items.edit', $item))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="pistol"[^>]*selected/', $html);
        $this->assertStringContainsString('value="9mm"', $html);
    }

    public function test_a_blade_keeps_its_damage_and_profile_on_the_weapon(): void
    {
        $this->save($this->base([
            'weapon_class' => 'blade', 'weapon_damage' => 6, 'weapon_accuracy' => 0, 'weapon_rate' => 1,
            'weapon_profile' => ['slashing' => '100'],
        ]))->assertRedirect();
        $item = Item::query()->where('slug', 'test_gear')->sole();

        $this->assertSame(6, $item->weapon->damage);

        $html = $this->actingAs($this->admin())->get(route('admin.items.edit', $item))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="weapon_profile\[slashing\]"[^>]*value="100"/', $html);
    }

    public function test_saving_again_as_not_a_weapon_removes_the_row(): void
    {
        $this->save($this->pistol())->assertRedirect();
        $item = Item::query()->where('slug', 'test_gear')->sole();

        $update = $this->base(['weapon_class' => '']);
        unset($update['slug']);
        $this->actingAs($this->admin())->put(route('admin.items.update', $item), $update)->assertRedirect();

        $this->assertNull(Weapon::query()->where('item_id', $item->id)->first());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function weaponRefusals(): iterable
    {
        yield 'a firearm with a damage of its own' => [['weapon_damage' => 8], 'weapon_damage'];
        yield 'a firearm with a profile of its own' => [['weapon_profile' => ['penetrating' => '100']], 'weapon_profile'];
        yield 'a firearm without a calibre' => [['weapon_calibre' => ''], 'weapon_calibre'];
        yield 'a firearm without a magazine' => [['weapon_magazine' => ''], 'weapon_magazine'];
        yield 'a class that is not one' => [['weapon_class' => 'bazooka'], 'weapon_class'];
        yield 'a stackable weapon (a hand holds one row)' => [['stackable' => '1'], 'stackable'];
        yield 'a data item' => [['container' => Item::CONTAINER_DATA], 'container'];
        yield 'effects on a weapon, which nothing reads' => [[
            'modifiers' => [['op' => 'add', 'amount' => '5', 'target' => 'hitpoints_max', 'carried' => '']],
        ], 'modifiers'];
        yield 'also armour' => [['armour_slots' => ['torso']], 'armour_slots'];
        yield 'also a drug' => [['drug_family' => 'stimulant', 'duration_minutes' => 10, 'addictive' => 1, 'tolerance_gain' => 1], 'drug_family'];
        yield 'also an implant' => [['implant_region' => 'spine'], 'weapon_class'];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('weaponRefusals')]
    public function test_a_weapon_refuses(array $override, string $field): void
    {
        $this->save($this->pistol($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Item::query()->where('slug', 'test_gear')->count());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function closeWeaponRefusals(): iterable
    {
        yield 'a profile that does not add up to 100' => [['weapon_profile' => ['slashing' => '60']], 'weapon_profile'];
        yield 'a profile naming a cause rather than a type' => [['weapon_profile' => ['ice' => '100']], 'weapon_profile'];
        yield 'a profile of nothing' => [['weapon_profile' => ['slashing' => '']], 'weapon_profile'];
        yield 'a damage of nothing' => [['weapon_damage' => 0], 'weapon_damage'];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('closeWeaponRefusals')]
    public function test_a_blade_refuses(array $override, string $field): void
    {
        $this->save($this->base(array_merge([
            'weapon_class' => 'blade', 'weapon_damage' => 6, 'weapon_accuracy' => 0, 'weapon_rate' => 1,
            'weapon_profile' => ['slashing' => '100'],
        ], $override)))->assertSessionHasErrors($field);
    }

    public function test_a_blade_with_a_calibre_is_refused(): void
    {
        $this->save($this->base([
            'weapon_class' => 'blade', 'weapon_damage' => 6, 'weapon_accuracy' => 0, 'weapon_rate' => 1,
            'weapon_calibre' => '9mm', 'weapon_profile' => ['slashing' => '100'],
        ]))->assertSessionHasErrors('weapon_calibre');
    }

    // ---- riders --------------------------------------------------------------------

    public function test_riders_on_a_weapon_and_a_round_are_saved_and_shown_back(): void
    {
        $this->save($this->pistol(['weapon_riders' => [
            'stun' => ['chance' => '10', 'turns' => '1', 'amount' => ''],
            'burning' => ['chance' => '', 'turns' => '', 'amount' => ''],
        ]]))->assertRedirect();

        $item = Item::query()->where('slug', 'test_gear')->sole();
        $this->assertSame([['kind' => 'stun', 'chance' => 10, 'turns' => 1, 'amount' => 0]], $item->weapon->riders());

        $html = $this->actingAs($this->admin())->get(route('admin.items.edit', $item))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="weapon_riders\[stun\]\[chance\]"[^>]*value="10"/', $html);

        $this->save($this->round(['slug' => 'test_round', 'ammo_riders' => ['burning' => ['chance' => '30', 'turns' => '3', 'amount' => '2']]]))->assertRedirect();
        $this->assertSame(
            [['kind' => 'burning', 'chance' => 30, 'turns' => 3, 'amount' => 2]],
            Item::query()->where('slug', 'test_round')->sole()->ammunition->riders(),
        );
    }

    public function test_no_riders_is_null_and_clearing_them_removes_them(): void
    {
        $this->save($this->pistol())->assertRedirect();
        $item = Item::query()->where('slug', 'test_gear')->sole();
        $this->assertNull($item->weapon->riders);

        $update = $this->pistol(['weapon_riders' => ['stun' => ['chance' => '10', 'turns' => '1']]]);
        unset($update['slug']);
        $this->actingAs($this->admin())->put(route('admin.items.update', $item), $update)->assertRedirect();
        $this->assertCount(1, $item->fresh()->weapon->riders());

        $update['weapon_riders'] = ['stun' => ['chance' => '', 'turns' => '', 'amount' => '']];
        $this->actingAs($this->admin())->put(route('admin.items.update', $item), $update)->assertRedirect();
        $this->assertNull($item->fresh()->weapon->riders);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function riderRefusals(): iterable
    {
        yield 'a stun with an amount' => [['stun' => ['chance' => '10', 'turns' => '1', 'amount' => '4']], 'weapon_riders'];
        yield 'a burn with no amount' => [['burning' => ['chance' => '10', 'turns' => '3', 'amount' => '']], 'weapon_riders'];
        yield 'a chance of nothing with the rest filled in' => [['poisoned' => ['chance' => '', 'turns' => '3', 'amount' => '2']], 'weapon_riders'];
        yield 'a kind that does not exist' => [['frozen' => ['chance' => '10', 'turns' => '1']], 'weapon_riders'];
    }

    /** @param  array<string, mixed>  $riders */
    #[DataProvider('riderRefusals')]
    public function test_riders_refuse(array $riders, string $field): void
    {
        $this->save($this->pistol(['weapon_riders' => $riders]))->assertSessionHasErrors($field);
    }

    public function test_a_round_refuses_a_bad_rider_too(): void
    {
        $this->save($this->round(['ammo_riders' => ['burning' => ['chance' => '10', 'turns' => '99', 'amount' => '2']]]))
            ->assertSessionHasErrors('ammo_riders');
    }

    // ---- ammunition ---------------------------------------------------------------------

    public function test_ammunition_is_a_calibre_with_the_damage_of_a_hit_and_it_stacks(): void
    {
        $this->save($this->round())->assertRedirect();

        $item = Item::query()->where('slug', 'test_gear')->sole();
        $round = $item->ammunition;

        $this->assertSame('9mm', $round->calibre);
        $this->assertSame(AmmoType::HollowPoint, $round->type);
        $this->assertSame(10, $round->damage, 'the whole figure of a hit, not a percent');
        $this->assertSame(-50, $round->penetration, 'a hollow point: harder on flesh, worse against a vest');
        $this->assertSame(['penetrating' => 100], $round->profile());
        $this->assertTrue($item->stackable);
    }

    public function test_a_round_may_split_its_damage_across_types(): void
    {
        $this->save($this->round(['ammo_profile' => ['penetrating' => '60', 'thermal' => '40']]))->assertRedirect();

        $this->assertSame(['penetrating' => 60, 'thermal' => 40], Item::query()->where('slug', 'test_gear')->sole()->ammunition->profile());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function roundRefusals(): iterable
    {
        yield 'a type that is not one' => [['ammo_type' => 'plasma'], 'ammo_type'];
        yield 'ammunition that does not stack' => [['stackable' => ''], 'stackable'];
        yield 'a profile of nothing' => [['ammo_profile' => ['penetrating' => '']], 'ammo_profile'];
        yield 'a profile that does not add up' => [['ammo_profile' => ['penetrating' => '60', 'thermal' => '10']], 'ammo_profile'];
        yield 'a penetration past 100' => [['ammo_penetration' => 150], 'ammo_penetration'];
        yield 'a damage of nothing' => [['ammo_damage' => 0], 'ammo_damage'];
        yield 'a round that is a percent, not a figure' => [['ammo_damage' => -25], 'ammo_damage'];
        yield 'also a weapon' => [['weapon_class' => 'pistol', 'weapon_accuracy' => 0, 'weapon_rate' => 1, 'weapon_magazine' => 12, 'weapon_calibre' => '9mm'], 'weapon_class'];
        yield 'effects on ammunition' => [[
            'modifiers' => [['op' => 'add', 'amount' => '5', 'target' => 'hitpoints_max', 'carried' => '']],
        ], 'modifiers'];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('roundRefusals')]
    public function test_ammunition_refuses(array $override, string $field): void
    {
        $this->save($this->round($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Ammunition::query()->count());
    }

    public function test_there_is_one_item_per_kind_of_round(): void
    {
        $this->save($this->round(['slug' => 'first_round', 'name' => 'First round']))->assertRedirect();

        $this->save($this->round(['slug' => 'second_round', 'name' => 'Second round']))->assertSessionHasErrors('ammo_type');
        $this->assertSame(0, Item::query()->where('slug', 'second_round')->count());

        // A different type of the same calibre, or the same type of another calibre, is a different kind.
        $this->save($this->round(['slug' => 'ap_round', 'ammo_type' => 'armour_piercing']))->assertRedirect();
        $this->save($this->round(['slug' => 'big_round', 'ammo_calibre' => '.45']))->assertRedirect();
    }

    public function test_an_item_does_not_clash_with_itself_when_it_is_saved_again(): void
    {
        $this->save($this->round(['slug' => 'first_round']))->assertRedirect();
        $item = Item::query()->where('slug', 'first_round')->sole();

        $update = $this->round(['ammo_damage' => 30]);
        unset($update['slug']);
        $this->actingAs($this->admin())->put(route('admin.items.update', $item), $update)->assertRedirect();

        $this->assertSame(30, $item->fresh()->ammunition->damage);
    }

    public function test_the_database_holds_the_same_rule(): void
    {
        $this->save($this->round(['slug' => 'first_round']))->assertRedirect();
        $other = Item::factory()->physical()->create(['stackable' => true]);

        $this->expectException(QueryException::class);
        Ammunition::create(['item_id' => $other->id, 'calibre' => '9mm', 'type' => 'hollow_point', 'damage' => 10, 'penetration' => 0]);
    }

    /** The form posts the hidden Weapon panel's defaults too: a rate of 1 and an accuracy of 0 are not values. */
    public function test_ammunition_saves_with_the_hidden_weapon_panels_defaults(): void
    {
        $this->save($this->round(['weapon_class' => '', 'weapon_accuracy' => '0', 'weapon_rate' => '1', 'weapon_magazine' => '', 'weapon_calibre' => '']))->assertRedirect();

        $this->assertTrue(Item::query()->where('slug', 'test_gear')->sole()->isAmmunition());
    }

    public function test_a_blank_calibre_is_not_ammunition(): void
    {
        $this->save($this->base(['ammo_calibre' => '']))->assertRedirect();

        $this->assertFalse(Item::query()->where('slug', 'test_gear')->sole()->isAmmunition());
    }
}
