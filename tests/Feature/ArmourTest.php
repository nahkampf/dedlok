<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\ArmourEditor;
use App\Combat\ArmourSlot;
use App\Combat\ArmourWearer;
use App\Combat\WearRefusal;
use App\Inventory\ItemPlacer;
use App\Inventory\PlacementRefusal;
use App\Market\OfferBook;
use App\Market\OfferRefusal;
use App\Models\Armour;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Models\WornSlot;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Armour, worn a body part at a time. COMBAT.md, *Body parts and armour*.
 *
 * The test that matters is the one about LOCATION: the same hit against the same plate lands
 * differently on the torso and on the leg, which is the whole of why armour is per slot.
 */
final class ArmourTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    /**
     * An armour item and a copy of it carried.
     *
     * @param  list<ArmourSlot>  $slots
     * @param  list<array<string, mixed>>  $modifiers
     */
    private function piece(array $slots, array $modifiers = [], string $name = 'A plate'): InventoryItem
    {
        $item = Item::factory()->physical()->create(['name' => $name, 'stackable' => false, 'modifiers' => $modifiers ?: null]);
        Armour::create(['item_id' => $item->id, 'slots' => array_map(static fn (ArmourSlot $s): string => $s->value, $slots)]);

        return InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
    }

    private function wearer(): ArmourWearer
    {
        return app(ArmourWearer::class);
    }

    /** What a hit would be worth to the wearer, without taking it. */
    private function hit(int $amount, DamageOrigin $type, ?ArmourSlot $at): int
    {
        return DamageResolver::make()
            ->preview($this->character->fresh(), Damage::hitpoints($amount, $type, $at))
            ->toInt();
    }

    /** @return array<string, mixed> a resistance against one stat */
    private function resist(string $stat, float $percent): array
    {
        return ['op' => 'percentage_of_base', 'amount' => $percent, 'target' => ['id' => $stat]];
    }

    // ---- wearing it -----------------------------------------------------------

    public function test_a_set_takes_every_slot_it_covers_as_a_row_each(): void
    {
        $jacket = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft, ArmourSlot::ArmRight], name: 'A jacket');

        $this->assertTrue($this->wearer()->wear($this->character, $jacket));

        $this->assertEqualsCanonicalizing(
            ['torso', 'arm_left', 'arm_right'],
            WornSlot::query()->where('character_id', $this->character->id)->pluck('slot')->map(fn ($s) => $s->value)->all(),
        );
        $this->assertTrue($jacket->isWorn());
    }

    public function test_wearing_over_a_slot_takes_the_old_piece_off_whole(): void
    {
        $vest = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft, ArmourSlot::ArmRight], name: 'A vest');
        $plate = $this->piece([ArmourSlot::Torso], name: 'A plate');
        $helmet = $this->piece([ArmourSlot::Head], name: 'A helmet');

        $this->wearer()->wear($this->character, $vest);
        $this->wearer()->wear($this->character, $helmet);
        $this->wearer()->wear($this->character, $plate);

        $this->assertFalse($vest->fresh()->isWorn(), 'half a vest is not something the fiction can hold');
        $this->assertTrue($plate->fresh()->isWorn());
        $this->assertTrue($helmet->fresh()->isWorn(), 'what did not overlap stays on');
        $this->assertSame(2, WornSlot::query()->where('character_id', $this->character->id)->count());
    }

    public function test_taking_it_off_frees_every_slot(): void
    {
        $vest = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft]);
        $this->wearer()->wear($this->character, $vest);

        $this->assertTrue($this->wearer()->remove($this->character, $vest));

        $this->assertSame(0, WornSlot::query()->count());
        $this->assertSame(WearRefusal::NotWorn, $this->wearer()->remove($this->character, $vest));
    }

    public function test_it_refuses_what_it_should(): void
    {
        $vest = $this->piece([ArmourSlot::Torso]);

        $stranger = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->assertSame(WearRefusal::NotOwned, $this->wearer()->wear($stranger, $vest));

        $notArmour = InventoryItem::query()->create([
            'character_id' => $this->character->id, 'item_id' => Item::factory()->physical()->create()->id, 'quantity' => 1,
        ]);
        $this->assertSame(WearRefusal::NotArmour, $this->wearer()->wear($this->character, $notArmour));

        $this->assertTrue($this->wearer()->wear($this->character, $vest));
        $this->assertSame(WearRefusal::AlreadyWorn, $this->wearer()->wear($this->character, $vest));
    }

    public function test_armour_inside_a_container_cannot_be_put_on(): void
    {
        $bag = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->physical()->create(['capacity' => 10, 'accepts' => 'physical'])->id,
            'quantity' => 1,
        ]);
        $vest = $this->piece([ArmourSlot::Torso]);
        $vest->forceFill(['parent_id' => $bag->id])->save();

        $this->assertSame(WearRefusal::NotFree, $this->wearer()->wear($this->character, $vest->fresh()));
    }

    public function test_destroying_a_worn_piece_takes_it_off(): void
    {
        $vest = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft]);
        $this->wearer()->wear($this->character, $vest);

        $vest->delete();

        $this->assertSame(0, WornSlot::query()->count());
    }

    // ---- THE POINT: a resistance counts only where the piece is worn -------------

    public function test_a_plate_softens_the_torso_and_not_the_leg_nor_an_unlocated_hit(): void
    {
        $plate = $this->piece([ArmourSlot::Torso], [$this->resist('damage.hitpoints.penetrating', -60)]);
        $this->wearer()->wear($this->character, $plate);

        $this->assertSame(4, $this->hit(10, DamageOrigin::Penetrating, ArmourSlot::Torso), 'a shot to the torso is stopped');
        $this->assertSame(10, $this->hit(10, DamageOrigin::Penetrating, ArmourSlot::LegLeft), 'and one to the leg is not');
        $this->assertSame(10, $this->hit(10, DamageOrigin::Penetrating, null), 'a hit that struck nothing in particular is never armoured');
    }

    public function test_a_set_covers_each_of_its_slots_and_no_others(): void
    {
        $jacket = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft, ArmourSlot::ArmRight], [$this->resist('damage.hitpoints.slashing', -50)]);
        $this->wearer()->wear($this->character, $jacket);

        foreach ([ArmourSlot::Torso, ArmourSlot::ArmLeft, ArmourSlot::ArmRight] as $covered) {
            $this->assertSame(5, $this->hit(10, DamageOrigin::Slashing, $covered), $covered->value);
        }

        foreach ([ArmourSlot::Head, ArmourSlot::LegLeft, ArmourSlot::LegRight] as $bare) {
            $this->assertSame(10, $this->hit(10, DamageOrigin::Slashing, $bare), $bare->value);
        }
    }

    public function test_one_piece_can_resist_different_types_differently(): void
    {
        // A ceramic plate: strong against rounds, weak against blows.
        $ceramic = $this->piece([ArmourSlot::Torso], [
            $this->resist('damage.hitpoints.penetrating', -60),
            $this->resist('damage.hitpoints.blunt', -10),
        ]);
        $this->wearer()->wear($this->character, $ceramic);

        $this->assertSame(4, $this->hit(10, DamageOrigin::Penetrating, ArmourSlot::Torso));
        $this->assertSame(9, $this->hit(10, DamageOrigin::Blunt, ArmourSlot::Torso));
        $this->assertSame(10, $this->hit(10, DamageOrigin::Thermal, ArmourSlot::Torso), 'and nothing against what it was not made for');
    }

    public function test_a_family_tag_set_is_located_too(): void
    {
        $vest = $this->piece([ArmourSlot::Torso], [
            ['op' => 'percentage_of_base', 'amount' => -50, 'target' => ['all_of' => ['damage', 'kinetic']]],
        ]);
        $this->wearer()->wear($this->character, $vest);

        $this->assertSame(5, $this->hit(10, DamageOrigin::Fragmentation, ArmourSlot::Torso));
        $this->assertSame(10, $this->hit(10, DamageOrigin::Fragmentation, ArmourSlot::Head), 'a tag set cannot slip past the location');
        $this->assertSame(10, $this->hit(10, DamageOrigin::Electrical, ArmourSlot::Torso), 'and it covers its own family only');
    }

    public function test_what_is_carried_everywhere_adds_to_what_is_worn_only_here(): void
    {
        // Subdermal plating is an IMPLANT: it applies wherever a blow lands. Plus a vest on the torso.
        $plating = Item::factory()->physical()->create(['modifiers' => [$this->resist('damage.hitpoints.blunt', -20)]]);
        InventoryItem::query()->create([
            'character_id' => $this->character->id, 'item_id' => $plating->id, 'quantity' => 1, 'installed_at' => now(),
        ]);
        $vest = $this->piece([ArmourSlot::Torso], [$this->resist('damage.hitpoints.blunt', -30)]);
        $this->wearer()->wear($this->character, $vest);

        $this->assertSame(5, $this->hit(10, DamageOrigin::Blunt, ArmourSlot::Torso), '−20% and −30% SUM, as every resistance does');
        $this->assertSame(8, $this->hit(10, DamageOrigin::Blunt, ArmourSlot::LegLeft), 'the plating alone, off the vest');
    }

    public function test_a_modifier_that_is_not_about_damage_applies_whenever_it_is_worn(): void
    {
        $before = $this->character->fresh()->stat('reflex')->value;

        $heavy = $this->piece([ArmourSlot::Torso], [['op' => 'add', 'amount' => -3, 'target' => ['id' => 'reflex']]]);
        $this->wearer()->wear($this->character, $heavy);

        $this->assertEqualsWithDelta($before - 3, $this->character->fresh()->stat('reflex')->value, 1e-9, 'not tied to a body part');
    }

    public function test_icy_damage_that_names_no_location_is_untouched_by_a_vest(): void
    {
        $vest = $this->piece([ArmourSlot::Torso, ArmourSlot::Head], [
            ['op' => 'percentage_of_base', 'amount' => -90, 'target' => ['all_of' => ['damage']]],
        ]);
        $this->wearer()->wear($this->character, $vest);

        $ice = DamageResolver::make()->preview($this->character->fresh(), Damage::synapticIntegrity(10, DamageOrigin::Ice))->toInt();

        $this->assertSame(10, $ice, 'a vest does not help against a bad deck');
    }

    // ---- the editor's side ------------------------------------------------------

    public function test_an_item_that_stops_being_armour_comes_off_everybody(): void
    {
        $vest = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft]);
        $this->wearer()->wear($this->character, $vest);

        ArmourEditor::apply($vest->item, []);

        $this->assertNull(Armour::query()->where('item_id', $vest->item_id)->first());
        $this->assertSame(0, WornSlot::query()->count());
    }

    public function test_narrowing_the_coverage_drops_only_the_slots_no_longer_covered(): void
    {
        $vest = $this->piece([ArmourSlot::Torso, ArmourSlot::ArmLeft, ArmourSlot::ArmRight]);
        $this->wearer()->wear($this->character, $vest);

        ArmourEditor::apply($vest->item, ['torso']);

        $this->assertSame(['torso'], WornSlot::query()->pluck('slot')->map(fn ($s) => $s->value)->all());
    }

    // ---- worn is not for sale, nor for storing -------------------------------------

    public function test_worn_armour_cannot_be_listed_or_stored(): void
    {
        $vest = $this->piece([ArmourSlot::Torso]);
        $this->wearer()->wear($this->character, $vest);

        $this->assertSame(OfferRefusal::Worn, app(OfferBook::class)->list($this->character, $vest, 100));

        $bag = InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => Item::factory()->physical()->create(['capacity' => 10, 'accepts' => 'physical'])->id,
            'quantity' => 1,
        ]);
        $result = app(ItemPlacer::class)->place($this->character, $vest, $bag);
        $this->assertSame(PlacementRefusal::Worn, $result->refusal);
    }

    // ---- the screens ------------------------------------------------------------------

    public function test_the_stash_card_wears_and_takes_off_and_the_sheet_says_what_is_on(): void
    {
        $plate = $this->piece([ArmourSlot::Torso], [], 'Ceramic plate');

        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSeeText('Wear')->assertDontSeeText('Take off');

        $this->actingAs($this->character->user)->post(route('inventory.wear', $plate))
            ->assertRedirect()->assertSessionHas('status', 'You put on Ceramic plate.');

        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSeeText('Take off')->assertSeeText('Worn');

        $this->actingAs($this->character->user)->get(route('character.index'))
            ->assertOk()->assertDontSeeText('Ceramic plate');

        $this->actingAs($this->character->user)->get(route('equipment.index'))
            ->assertOk()->assertSeeText('Torso')->assertSeeText('Ceramic plate')->assertSeeText('Legs')->assertSeeText('Nothing');

        $this->actingAs($this->character->user)->post(route('inventory.wear', $plate))
            ->assertSessionHas('status', 'You take off Ceramic plate.');
        $this->assertFalse($plate->fresh()->isWorn());
    }

    public function test_nobody_wears_somebody_elses_armour(): void
    {
        $plate = $this->piece([ArmourSlot::Torso]);
        $other = User::factory()->create();
        Character::factory()->create(['user_id' => $other->id, 'archetype' => 3]);

        $this->actingAs($other)->post(route('inventory.wear', $plate))->assertNotFound();
    }
}
