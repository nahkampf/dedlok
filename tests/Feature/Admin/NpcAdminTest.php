<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\DeletionImpact;
use App\Combat\AmmoType;
use App\Combat\WeaponClass;
use App\Models\Ammunition;
use App\Models\Entity;
use App\Models\Item;
use App\Models\Npc;
use App\Models\NpcLoot;
use App\Models\User;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The opponent editor. docs/COMBAT.md, step 3.
 */
final class NpcAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function weapon(WeaponClass $class, string $name, ?string $calibre = null): Item
    {
        $item = Item::factory()->physical()->create(['name' => $name, 'stackable' => false]);
        Weapon::create([
            'item_id' => $item->id, 'class' => $class, 'damage' => $class->firearm() ? null : 6, 'damage_profile' => $class->firearm() ? null : ['penetrating' => 100], 'accuracy' => 0, 'rate' => 1,
            'magazine' => $class->firearm() ? 12 : null, 'calibre' => $class->firearm() ? ($calibre ?? '9mm') : null,
        ]);

        return $item;
    }

    private int $rounds = 0;

    private function round(string $calibre = '9mm'): Item
    {
        // One item per kind of round, so each one a test makes is a different type.
        $type = AmmoType::cases()[$this->rounds++ % count(AmmoType::cases())];
        $item = Item::factory()->physical()->create(['name' => 'A round', 'stackable' => true]);
        Ammunition::create(['item_id' => $item->id, 'calibre' => $calibre, 'type' => $type, 'damage' => 6, 'damage_profile' => ['penetrating' => 100], 'penetration' => 0]);

        return $item;
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        return array_merge(['slug' => 'gang_member', 'name' => 'Gang member', 'grade' => 1, 'hitpoints' => 40, 'aim' => 10, 'defence' => 8, 'pursuit' => 8], $overrides);
    }

    private function save(array $payload): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.npcs.store'), $payload);
    }

    public function test_only_an_admin_gets_in(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.npcs.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('admin.npcs.store'), $this->valid())->assertForbidden();
        $this->assertSame(0, Npc::query()->count());
    }

    public function test_an_opponent_is_created_listed_and_shown_back(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 'Street pistol');
        $knife = $this->weapon(WeaponClass::Blade, 'Switchblade');
        $round = $this->round();

        $this->save($this->valid([
            'flavor' => 'Twitchy.', 'flees_at' => '0.25', 'ranged_weapon_id' => $gun->id, 'melee_weapon_id' => $knife->id, 'ammo_item_id' => $round->id,
            'resistances' => ['penetrating' => '30', 'thermal' => '-25', 'blunt' => '', 'neural' => '0'],
            'slot_resistances' => ['head' => ['penetrating' => '40', 'blunt' => ''], 'torso' => ['penetrating' => '']],
        ]))->assertRedirect();

        $npc = Npc::query()->where('slug', 'gang_member')->sole();

        $this->assertSame(40, $npc->hitpoints);
        $this->assertSame(0.25, $npc->flees_at);
        $this->assertSame($gun->id, $npc->ranged_weapon_id);
        $this->assertSame($round->id, $npc->ammo_item_id);
        $this->assertSame(['penetrating' => 30, 'thermal' => -25], $npc->resistances, 'blanks and zeros are none');
        $this->assertSame(['head' => ['penetrating' => 40]], $npc->slot_resistances, 'a part with nothing is not stored');

        $this->actingAs($this->admin())->get(route('admin.npcs.index'))->assertOk()->assertSeeText('Gang member')->assertSeeText('Street pistol')->assertSeeText('Switchblade');

        $html = $this->actingAs($this->admin())->get(route('admin.npcs.edit', $npc))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="resistances\[penetrating\]"[^>]*value="30"/', $html);
        $this->assertMatchesRegularExpression('/name="slot_resistances\[head\]\[penetrating\]"[^>]*value="40"/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$gun->id.'"[^>]*selected/', $html);
        $this->assertStringContainsString('public/images/npcs/'.$npc->id.'.jpg', $html, 'the picture convention is said where it matters');
    }

    public function test_the_weapon_pickers_offer_each_hand_only_its_own_kind(): void
    {
        $this->weapon(WeaponClass::Rifle, 'Long gun');
        $this->weapon(WeaponClass::Blunt, 'Pipe');

        $html = $this->actingAs($this->admin())->get(route('admin.npcs.create'))->assertOk()->getContent();

        preg_match('/name="ranged_weapon_id".*?<\/select>/s', $html, $ranged);
        preg_match('/name="melee_weapon_id".*?<\/select>/s', $html, $melee);
        $this->assertStringContainsString('Long gun', $ranged[0]);
        $this->assertStringNotContainsString('Pipe', $ranged[0]);
        $this->assertStringContainsString('Pipe', $melee[0]);
        $this->assertStringNotContainsString('Long gun', $melee[0]);
    }

    public function test_an_opponent_is_updated_and_a_slug_is_permanent(): void
    {
        $this->save($this->valid())->assertRedirect();
        $npc = Npc::query()->sole();

        $update = $this->valid(['name' => 'Gang lieutenant', 'hitpoints' => 60, 'slug' => '']);
        $this->actingAs($this->admin())->put(route('admin.npcs.update', $npc), $update)->assertRedirect();

        $this->assertSame('Gang lieutenant', $npc->fresh()->name);
        $this->assertSame('gang_member', $npc->fresh()->slug, 'an empty slug on update is dropped, not written');

        $this->actingAs($this->admin())->put(route('admin.npcs.update', $npc), $this->valid(['slug' => 'other']))->assertSessionHasErrors('slug');
    }

    public function test_clearing_the_weapons_and_resistances_clears_them(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 'Street pistol');
        $this->save($this->valid(['ranged_weapon_id' => $gun->id, 'ammo_item_id' => $this->round()->id, 'resistances' => ['blunt' => '20']]))->assertRedirect();
        $npc = Npc::query()->sole();

        $this->actingAs($this->admin())->put(route('admin.npcs.update', $npc), $this->valid(['slug' => '', 'ranged_weapon_id' => '', 'resistances' => ['blunt' => '']]))->assertRedirect();

        $this->assertNull($npc->fresh()->ranged_weapon_id);
        $this->assertNull($npc->fresh()->resistances);
    }

    public function test_a_standing_round_without_a_ranged_weapon_is_dropped(): void
    {
        $this->save($this->valid(['ammo_item_id' => '']))->assertRedirect();

        $this->assertNull(Npc::query()->sole()->ammo_item_id);
    }

    public function test_deleting_a_weapon_leaves_the_opponent_unarmed_and_deleting_the_opponent_is_allowed(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 'Street pistol');
        $this->save($this->valid(['ranged_weapon_id' => $gun->id, 'ammo_item_id' => $this->round()->id]))->assertRedirect();
        $npc = Npc::query()->sole();

        $gun->delete();
        $this->assertNull($npc->fresh()->ranged_weapon_id);

        $this->actingAs($this->admin())->delete(route('admin.npcs.destroy', $npc))->assertRedirect(route('admin.npcs.index'));
        $this->assertSame(0, Npc::query()->count());
    }

    public function test_the_dashboard_counts_them(): void
    {
        $this->save($this->valid())->assertRedirect();

        $this->actingAs($this->admin())->get(route('admin.index'))->assertOk()->assertSeeText('Opponents');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): iterable
    {
        yield 'a name' => [['name' => ''], 'name'];
        yield 'a slug with capitals' => [['slug' => 'Gang Member'], 'slug'];
        yield 'a grade of six' => [['grade' => 6], 'grade'];
        yield 'no hit points' => [['hitpoints' => 0], 'hitpoints'];
        yield 'an aim past the scale' => [['aim' => 150], 'aim'];
        yield 'a flee point over a whole' => [['flees_at' => '1.5'], 'flees_at'];
        yield 'a weapon that does not exist' => [['ranged_weapon_id' => 999999], 'ranged_weapon_id'];
        yield 'a resistance of a made-up type' => [['resistances' => ['plasma' => '10']], 'resistances'];
        yield 'a resistance past 100' => [['resistances' => ['blunt' => '150']], 'resistances'];
        yield 'a fractional resistance' => [['resistances' => ['blunt' => '12.5']], 'resistances'];
        yield 'a resistance on a made-up body part' => [['slot_resistances' => ['tail' => ['blunt' => '10']]], 'slot_resistances'];
        yield 'a slot resistance past 100' => [['slot_resistances' => ['head' => ['blunt' => '-200']]], 'slot_resistances'];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('refusals')]
    public function test_it_refuses(array $override, string $field): void
    {
        $this->save($this->valid($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Npc::query()->count());
    }

    public function test_it_refuses_the_wrong_kind_of_weapon_in_a_hand(): void
    {
        $knife = $this->weapon(WeaponClass::Blade, 'Switchblade');
        $gun = $this->weapon(WeaponClass::Pistol, 'Street pistol');
        $plain = Item::factory()->physical()->create();

        $this->save($this->valid(['ranged_weapon_id' => $knife->id]))->assertSessionHasErrors('ranged_weapon_id');
        $this->save($this->valid(['melee_weapon_id' => $gun->id]))->assertSessionHasErrors('melee_weapon_id');
        $this->save($this->valid(['ranged_weapon_id' => $plain->id]))->assertSessionHasErrors('ranged_weapon_id');
    }

    public function test_a_firearm_needs_its_standing_round_but_a_grenade_does_not(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 'Street pistol');
        $thrown = $this->weapon(WeaponClass::Explosive, 'Grenade');

        $this->save($this->valid(['ranged_weapon_id' => $gun->id]))->assertSessionHasErrors('ammo_item_id');
        $this->assertSame(0, Npc::query()->count());

        $this->save($this->valid(['ranged_weapon_id' => $thrown->id]))->assertRedirect();
    }

    public function test_it_refuses_a_round_that_does_not_fit(): void
    {
        $gun = $this->weapon(WeaponClass::Pistol, 'Street pistol');
        $thrown = $this->weapon(WeaponClass::Explosive, 'Grenade');

        $this->save($this->valid(['ranged_weapon_id' => $gun->id, 'ammo_item_id' => $this->round('.45')->id]))->assertSessionHasErrors('ammo_item_id');
        $this->save($this->valid(['ranged_weapon_id' => $gun->id, 'ammo_item_id' => Item::factory()->physical()->create()->id]))->assertSessionHasErrors('ammo_item_id');
        $this->save($this->valid(['ranged_weapon_id' => $thrown->id, 'ammo_item_id' => $this->round()->id]))->assertSessionHasErrors('ammo_item_id');
        $this->save($this->valid(['ammo_item_id' => $this->round()->id]))->assertSessionHasErrors('ammo_item_id');
    }

    // ---- spoils and defeat (docs/COMBAT.md, step 6) ----------------------------------------------------

    public function test_an_owner_tags_and_drops_are_saved_and_shown_back(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();
        $scrap = Item::factory()->physical()->create(['name' => 'Scrap', 'stackable' => true]);
        $knife = Item::factory()->physical()->create(['name' => 'Knife', 'stackable' => false]);

        $this->save($this->valid([
            'entity_id' => $entity->id,
            'tags' => ['maim', 'mugged'],
            'loot' => [
                0 => ['item_id' => $scrap->id, 'chance' => '40', 'min' => '2', 'max' => '5'],
                1 => ['item_id' => '', 'chance' => '100', 'min' => '1', 'max' => '1'],
                2 => ['item_id' => $knife->id, 'chance' => '5', 'min' => '1', 'max' => '1'],
            ],
        ]))->assertRedirect();

        $npc = Npc::query()->sole();
        $this->assertSame($entity->id, $npc->entity_id);
        $this->assertSame(['mugged', 'maim'], $npc->tags, 'stored in the vocabulary\'s order');
        $this->assertSame([[$scrap->id, 40, 2, 5], [$knife->id, 5, 1, 1]], $npc->loot->map(fn ($d) => [$d->item_id, $d->chance, $d->min_quantity, $d->max_quantity])->all(), 'the blank row is none');

        $html = $this->actingAs($this->admin())->get(route('admin.npcs.edit', $npc))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="tags\[\]" value="maim" checked/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$scrap->id.'"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/name="loot\[0\]\[chance\]" value="40"/', $html);
    }

    public function test_ticking_nothing_is_the_default_and_the_drops_are_replaced_wholesale(): void
    {
        $scrap = Item::factory()->physical()->create(['name' => 'Scrap']);
        $this->save($this->valid(['tags' => ['maim'], 'loot' => [0 => ['item_id' => $scrap->id, 'chance' => '40', 'min' => '1', 'max' => '1']]]))->assertRedirect();
        $npc = Npc::query()->sole();

        $this->actingAs($this->admin())->put(route('admin.npcs.update', $npc), $this->valid(['slug' => '']))->assertRedirect();

        $this->assertNull($npc->fresh()->tags, 'no box ticked is NULL, which means the default');
        $this->assertSame(0, NpcLoot::query()->count());
        $this->assertNull($npc->fresh()->entity_id);
    }

    public function test_it_refuses_a_bad_owner_tag_or_drop(): void
    {
        $scenery = Entity::query()->create(['name' => 'A shopfront', 'category' => Entity::query()->value('category'), 'interactable' => false, 'business' => 'Scenery.']);
        $data = Item::factory()->create(['container' => Item::CONTAINER_DATA]);
        $scrap = Item::factory()->physical()->create();
        $row = fn (Item $item, array $extra = []): array => [0 => $extra + ['item_id' => $item->id, 'chance' => '50', 'min' => '1', 'max' => '1']];

        $this->save($this->valid(['entity_id' => $scenery->id]))->assertSessionHasErrors('entity_id');

        $this->save($this->valid(['entity_id' => 999999]))->assertSessionHasErrors('entity_id');
        $this->save($this->valid(['tags' => ['sap']]))->assertSessionHasErrors('tags.0');
        $this->save($this->valid(['loot' => $row($data)]))->assertSessionHasErrors('loot.0');
        $this->save($this->valid(['loot' => $row($scrap, ['chance' => '0'])]))->assertSessionHasErrors('loot.0');
        $this->save($this->valid(['loot' => $row($scrap, ['chance' => '101'])]))->assertSessionHasErrors('loot.0');
        $this->save($this->valid(['loot' => $row($scrap, ['min' => '0'])]))->assertSessionHasErrors('loot.0');
        $this->save($this->valid(['loot' => $row($scrap, ['min' => '3', 'max' => '2'])]))->assertSessionHasErrors('loot.0');
        $this->save($this->valid(['loot' => [0 => $row($scrap)[0], 1 => $row($scrap)[0]]]))->assertSessionHasErrors('loot.1');

        $this->assertSame(0, Npc::query()->count());
    }

    public function test_deleting_a_dropped_item_says_how_many_opponents_stop_dropping_it(): void
    {
        $scrap = Item::factory()->physical()->create(['name' => 'Scrap']);
        $this->save($this->valid(['loot' => [0 => ['item_id' => $scrap->id, 'chance' => '40', 'min' => '1', 'max' => '1']]]))->assertRedirect();

        $this->assertSame(1, DeletionImpact::forItem($scrap)->counts()['npc_loot']);

        $scrap->delete();

        $this->assertSame(0, NpcLoot::query()->count(), 'the row goes with the item');
        $this->assertSame(1, Npc::query()->count(), 'the opponent stays');
    }
}
