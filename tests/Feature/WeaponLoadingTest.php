<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\AmmunitionEditor;
use App\Combat\LoadRefusal;
use App\Combat\WeaponClass;
use App\Combat\WeaponEditor;
use App\Combat\WeaponLoader;
use App\Inventory\ItemPlacer;
use App\Inventory\PlacementRefusal;
use App\Market\OfferBook;
use App\Market\OfferRefusal;
use App\Models\Ammunition;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Weapon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rounds in a weapon. COMBAT.md.
 *
 * The magazine is a fact about one COPY of a weapon, so it lives on the inventory row. Rounds
 * come off the loose pile and go back to it, and no door that takes a weapon away loses them.
 */
final class WeaponLoadingTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private InventoryItem $pistol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));

        $item = Item::factory()->physical()->create(['name' => 'Pistol', 'stackable' => false]);
        Weapon::create(['item_id' => $item->id, 'class' => WeaponClass::Pistol, 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'accuracy' => 0, 'rate' => 1, 'magazine' => 10, 'calibre' => '9mm']);
        $this->pistol = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function round(string $name, string $calibre = '9mm'): Item
    {
        $item = Item::factory()->physical()->create(['name' => $name, 'stackable' => true]);
        // One item per kind of round, so the name picks the type.
        $type = match (true) {
            str_contains($name, 'FMJ') => 'full_metal_jacket',
            str_contains($name, 'Hollow') => 'hollow_point',
            str_contains($name, 'AP') => 'armour_piercing',
            default => 'standard',
        };
        Ammunition::create(['item_id' => $item->id, 'calibre' => $calibre, 'type' => $type, 'damage' => 0, 'penetration' => 0]);

        return $item->fresh();
    }

    private function have(Item $item, int $quantity): InventoryItem
    {
        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity]);
    }

    private function loader(): WeaponLoader
    {
        return app(WeaponLoader::class);
    }

    private function stock(Item $item): int
    {
        return (int) InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $item->id)->whereNull('parent_id')->sum('quantity');
    }

    // ---- loading ----------------------------------------------------------------------

    public function test_loading_fills_the_magazine_from_the_stash_up_to_its_size(): void
    {
        $fmj = $this->round('9mm FMJ');
        $this->have($fmj, 25);

        $this->assertTrue($this->loader()->load($this->character, $this->pistol, $fmj));

        $row = $this->pistol->fresh();
        $this->assertSame(10, $row->rounds());
        $this->assertSame($fmj->id, $row->loaded_ammo_id);
        $this->assertSame(15, $this->stock($fmj), 'a magazine of ten comes off the pile');
    }

    public function test_a_short_pile_loads_what_there_is_and_deletes_the_empty_row(): void
    {
        $fmj = $this->round('9mm FMJ');
        $pile = $this->have($fmj, 4);

        $this->assertTrue($this->loader()->load($this->character, $this->pistol, $fmj));

        $this->assertSame(4, $this->pistol->fresh()->rounds());
        $this->assertNull(InventoryItem::query()->find($pile->id), 'a pile that reaches zero is gone');
    }

    public function test_topping_up_the_same_round_adds_to_what_is_there(): void
    {
        $fmj = $this->round('9mm FMJ');
        $this->have($fmj, 6);
        $this->loader()->load($this->character, $this->pistol, $fmj);                 // takes 6
        $this->have($fmj, 20);

        $this->assertTrue($this->loader()->load($this->character->fresh(), $this->pistol->fresh(), $fmj));

        $this->assertSame(10, $this->pistol->fresh()->rounds(), 'topped up to the size, not past it');
        $this->assertSame(16, $this->stock($fmj));
        $this->assertSame(LoadRefusal::Full, $this->loader()->load($this->character->fresh(), $this->pistol->fresh(), $fmj));
    }

    public function test_switching_type_returns_the_old_rounds_to_a_merged_stack(): void
    {
        $fmj = $this->round('9mm FMJ');
        $hollow = $this->round('9mm Hollow point');
        $this->have($fmj, 10);
        $this->have($hollow, 30);
        $this->loader()->load($this->character, $this->pistol, $fmj);               // ten FMJ in the gun, none loose
        $this->have($fmj, 5);                                                        // a few more FMJ picked up since

        $this->assertTrue($this->loader()->load($this->character->fresh(), $this->pistol->fresh(), $hollow));

        $row = $this->pistol->fresh();
        $this->assertSame($hollow->id, $row->loaded_ammo_id);
        $this->assertSame(10, $row->rounds());
        $this->assertSame(20, $this->stock($hollow));
        $this->assertSame(15, $this->stock($fmj), 'the ten came back and merged with the five');
        $this->assertSame(1, InventoryItem::query()->where('item_id', $fmj->id)->count(), 'one pile, not two');
    }

    public function test_switching_back_to_a_type_draws_on_what_was_just_returned(): void
    {
        $fmj = $this->round('9mm FMJ');
        $hollow = $this->round('9mm Hollow point');
        $this->have($fmj, 10);
        $this->have($hollow, 1);
        $this->loader()->load($this->character, $this->pistol, $fmj);
        $this->loader()->load($this->character->fresh(), $this->pistol->fresh(), $hollow);   // one hollow point, ten FMJ back in the stash

        $this->assertTrue($this->loader()->load($this->character->fresh(), $this->pistol->fresh(), $fmj));

        $this->assertSame(10, $this->pistol->fresh()->rounds());
        $this->assertSame($fmj->id, $this->pistol->fresh()->loaded_ammo_id);
        $this->assertSame(1, $this->stock($hollow));
    }

    public function test_a_failed_swap_loses_nothing(): void
    {
        $fmj = $this->round('9mm FMJ');
        $hollow = $this->round('9mm Hollow point');                                   // owned: none
        $this->have($fmj, 10);
        $this->loader()->load($this->character, $this->pistol, $fmj);

        $this->assertSame(LoadRefusal::NoAmmunition, $this->loader()->load($this->character->fresh(), $this->pistol->fresh(), $hollow));

        $this->assertSame(10, $this->pistol->fresh()->rounds());
        $this->assertSame($fmj->id, $this->pistol->fresh()->loaded_ammo_id, 'refused before the old rounds were taken out');
    }

    public function test_unloading_puts_every_round_back(): void
    {
        $fmj = $this->round('9mm FMJ');
        $this->have($fmj, 10);
        $this->loader()->load($this->character, $this->pistol, $fmj);

        $this->assertTrue($this->loader()->unload($this->character->fresh(), $this->pistol->fresh()));

        $this->assertSame(0, $this->pistol->fresh()->rounds());
        $this->assertNull($this->pistol->fresh()->loaded_ammo_id);
        $this->assertSame(10, $this->stock($fmj));
        $this->assertSame(LoadRefusal::NothingLoaded, $this->loader()->unload($this->character->fresh(), $this->pistol->fresh()));
    }

    public function test_it_refuses_what_it_should(): void
    {
        $fmj = $this->round('9mm FMJ');
        $this->have($fmj, 10);

        $this->assertSame(LoadRefusal::WrongCalibre, $this->loader()->load($this->character, $this->pistol, $this->round('.45', '.45')));
        $this->assertSame(LoadRefusal::NotAmmunition, $this->loader()->load($this->character, $this->pistol, Item::factory()->physical()->create()));
        $this->assertSame(LoadRefusal::NoAmmunition, $this->loader()->load($this->character, $this->pistol, $this->round('9mm AP')));

        $stranger = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->assertSame(LoadRefusal::NotOwned, $this->loader()->load($stranger, $this->pistol, $fmj));

        $blade = Item::factory()->physical()->create(['stackable' => false]);
        Weapon::create(['item_id' => $blade->id, 'class' => WeaponClass::Blade, 'damage' => 6, 'damage_profile' => ['slashing' => 100], 'accuracy' => 0, 'rate' => 1]);
        $knife = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $blade->id, 'quantity' => 1]);
        $this->assertSame(LoadRefusal::NotAFirearm, $this->loader()->load($this->character, $knife, $fmj));

        $this->assertSame(10, $this->stock($fmj), 'every refusal left the pile alone');
    }

    public function test_ammunition_in_a_container_is_not_in_the_hands(): void
    {
        $fmj = $this->round('9mm FMJ');
        $bag = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->physical()->create(['capacity' => 50, 'accepts' => 'physical'])->id,
            'quantity' => 1,
        ]);
        $this->have($fmj, 10)->forceFill(['parent_id' => $bag->id])->save();

        $this->assertSame(LoadRefusal::NoAmmunition, $this->loader()->load($this->character, $this->pistol, $fmj));
    }

    public function test_a_null_ammunition_pointer_is_an_empty_magazine_whatever_the_count_says(): void
    {
        $this->pistol->forceFill(['loaded_ammo_id' => null, 'loaded_rounds' => 7])->save();

        $this->assertSame(0, $this->pistol->fresh()->rounds());
        $this->assertFalse($this->pistol->fresh()->isOnPerson());
    }

    // ---- every door out ----------------------------------------------------------------

    private function loaded(int $rounds = 10): Item
    {
        $fmj = $this->round('9mm FMJ');
        $this->have($fmj, $rounds);
        $this->loader()->load($this->character, $this->pistol, $fmj);

        return $fmj;
    }

    public function test_a_loaded_weapon_cannot_be_listed_or_put_away(): void
    {
        $this->loaded();

        $this->assertSame(OfferRefusal::Worn, app(OfferBook::class)->list($this->character->fresh(), $this->pistol->fresh(), 100));

        $bag = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->physical()->create(['capacity' => 10, 'accepts' => 'physical'])->id,
            'quantity' => 1,
        ]);
        $this->assertSame(PlacementRefusal::Worn, app(ItemPlacer::class)->place($this->character->fresh(), $this->pistol->fresh(), $bag)->refusal);

        $this->loader()->unload($this->character->fresh(), $this->pistol->fresh());
        $this->assertNotSame(OfferRefusal::Worn, app(OfferBook::class)->list($this->character->fresh(), $this->pistol->fresh(), 100), 'unloaded, it may go');
    }

    public function test_destroying_a_loaded_weapon_returns_its_rounds(): void
    {
        $fmj = $this->loaded();

        $this->actingAs($this->character->user)->post(route('inventory.destroy', $this->pistol))->assertRedirect();

        $this->assertNull(InventoryItem::query()->find($this->pistol->id));
        $this->assertSame(10, $this->stock($fmj), 'the rounds were property, and are in the stash');
    }

    public function test_a_weapon_that_changes_calibre_hands_back_every_holders_rounds(): void
    {
        $fmj = $this->loaded();

        WeaponEditor::apply($this->pistol->item->fresh(), ['class' => WeaponClass::Pistol, 'damage' => 8, 'damage_profile' => ['penetrating' => 100], 'accuracy' => 0, 'rate' => 1, 'magazine' => 8, 'calibre' => '.45', 'riders' => null]);

        $this->assertSame(0, $this->pistol->fresh()->rounds());
        $this->assertSame(10, $this->stock($fmj));
    }

    public function test_a_weapon_that_stays_a_9mm_keeps_its_magazine(): void
    {
        $this->loaded();

        WeaponEditor::apply($this->pistol->item->fresh(), ['class' => WeaponClass::Pistol, 'damage' => 12, 'damage_profile' => ['penetrating' => 100], 'accuracy' => 0, 'rate' => 1, 'magazine' => 10, 'calibre' => '9mm', 'riders' => null]);

        $this->assertSame(10, $this->pistol->fresh()->rounds());
    }

    public function test_a_weapon_that_stops_being_one_hands_back_its_rounds(): void
    {
        $fmj = $this->loaded();

        WeaponEditor::apply($this->pistol->item->fresh(), null);

        $this->assertSame(10, $this->stock($fmj));
        $this->assertSame(0, $this->pistol->fresh()->rounds());
    }

    public function test_a_round_that_changes_calibre_comes_out_of_every_weapon_holding_it(): void
    {
        $fmj = $this->loaded();

        AmmunitionEditor::apply($fmj->fresh(), ['calibre' => '.45', 'type' => 'standard', 'damage' => 0, 'penetration' => 0, 'damage_profile' => null, 'riders' => null]);

        $this->assertSame(0, $this->pistol->fresh()->rounds());
        $this->assertSame(10, $this->stock($fmj), 'back in the stash, as the .45 it now is');
    }

    // ---- the screens --------------------------------------------------------------------

    public function test_the_card_loads_and_unloads_and_says_what_is_in_it(): void
    {
        $fmj = $this->round('9mm FMJ');
        $this->have($fmj, 25);
        $user = $this->character->user;

        $this->actingAs($user)->get(route('inventory.index'))->assertOk()->assertSeeText('Empty')->assertSeeText('9mm FMJ × 25');

        $this->actingAs($user)->post(route('inventory.load', $this->pistol), ['ammo' => $fmj->id])
            ->assertRedirect()->assertSessionHas('status', 'You load 9mm FMJ into Pistol.');

        $this->actingAs($user)->get(route('inventory.index'))->assertOk()->assertSeeText('Loaded: 9mm FMJ 10 / 10')->assertSeeText('Unload');

        $this->actingAs($user)->post(route('inventory.unload', $this->pistol))
            ->assertSessionHas('status', 'You unload Pistol.');
        $this->assertSame(25, $this->stock($fmj));
    }

    public function test_a_bad_round_on_the_card_is_refused_in_words(): void
    {
        $this->actingAs($this->character->user)->post(route('inventory.load', $this->pistol), ['ammo' => 999999])
            ->assertSessionHas('error', LoadRefusal::NotAmmunition->message());
    }

    public function test_nobody_loads_somebody_elses_weapon(): void
    {
        $other = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));

        $this->actingAs($other->user)->post(route('inventory.unload', $this->pistol))->assertNotFound();
    }
}
