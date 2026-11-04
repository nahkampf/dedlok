<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\ModifierField;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\DamageStats;
use App\Stats\StatRegistry;
use App\Stats\Target;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The eight damage types (docs/COMBAT.md): blunt, slashing, penetrating, fragmentation, and the
 * specialty four. They are `DamageOrigin` cases, so everything here is the existing damage
 * system learning nothing — which is the point.
 */
final class DamageTypesTest extends TestCase
{
    use RefreshDatabase;

    private const KINETIC = ['blunt', 'slashing', 'penetrating', 'fragmentation'];

    private const SPECIALTY = ['electrical', 'thermal', 'chemical', 'neural'];

    private function character(): Character
    {
        return Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    /** @param  array<string, mixed>  $target */
    private function carries(Character $character, array $target, float $amount): void
    {
        Config::set('effects.test_resist', [
            'label' => 'Test resistance', 'stacking' => 'unique', 'retired' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => $amount, 'target' => $target]],
        ]);

        CharacterModifier::query()->create(['character_id' => $character->id, 'definition_key' => 'test_resist']);
    }

    private function body(Character $character, int $amount, DamageOrigin $type): int
    {
        return DamageResolver::make()->preview($character, Damage::hitpoints($amount, $type))->toInt();
    }

    public function test_each_type_knows_its_resource_and_its_family(): void
    {
        foreach (self::KINETIC as $value) {
            $this->assertSame('current_hitpoints', DamageOrigin::from($value)->resource());
            $this->assertSame('kinetic', DamageOrigin::from($value)->family());
        }

        foreach (['electrical', 'thermal', 'chemical'] as $value) {
            $this->assertSame('current_hitpoints', DamageOrigin::from($value)->resource());
            $this->assertSame('specialty', DamageOrigin::from($value)->family());
        }

        $this->assertSame('current_synaptic_integrity', DamageOrigin::Neural->resource(), 'the one type that hurts the mind');
        $this->assertSame('specialty', DamageOrigin::Neural->family());
    }

    public function test_the_older_origins_are_causes_not_types_and_keep_exactly_the_tags_they_had(): void
    {
        foreach ([DamageOrigin::Ice, DamageOrigin::Overdose, DamageOrigin::Burnout, DamageOrigin::ReadinessLoss, DamageOrigin::RunCancelled, DamageOrigin::Encounter] as $origin) {
            $this->assertNull($origin->resource());
            $this->assertNull($origin->family());
        }

        $registry = app(StatRegistry::class);
        $this->assertSame(['damage', 'synaptic_integrity', 'ice'], $registry->get('damage.synaptic_integrity.ice')->tags);
        $this->assertSame(['damage', 'hitpoints', 'overdose'], $registry->get('damage.hitpoints.overdose')->tags);
    }

    public function test_a_type_stat_carries_its_family_as_a_tag(): void
    {
        $registry = app(StatRegistry::class);

        $this->assertSame(['damage', 'hitpoints', 'slashing', 'kinetic'], $registry->get('damage.hitpoints.slashing')->tags);
        $this->assertSame(['damage', 'synaptic_integrity', 'neural', 'specialty'], $registry->get('damage.synaptic_integrity.neural')->tags);
        // The pair exists whichever resource the type defaults to: a weapon that does both needs no code.
        $this->assertTrue($registry->has('damage.synaptic_integrity.blunt'));
        $this->assertTrue($registry->has('damage.hitpoints.neural'));
    }

    public function test_a_family_reaches_four_types_on_each_of_two_resources(): void
    {
        $registry = app(StatRegistry::class);

        $kinetic = $registry->matching(Target::allOf(['damage', 'kinetic']));
        $specialty = $registry->matching(Target::allOf(['damage', 'specialty']));

        $this->assertCount(8, $kinetic);
        $this->assertCount(8, $specialty);
        $this->assertSame([], array_intersect($kinetic, $specialty));
        $this->assertContains('damage.hitpoints.fragmentation', $kinetic);
        $this->assertContains('damage.hitpoints.thermal', $specialty);
    }

    public function test_a_resistance_by_family_by_type_and_by_exact_stat(): void
    {
        $kinetic = $this->character();
        $this->carries($kinetic, ['all_of' => ['damage', 'kinetic']], -50);
        $this->assertSame(4, $this->body($kinetic, 8, DamageOrigin::Slashing));
        $this->assertSame(4, $this->body($kinetic, 8, DamageOrigin::Penetrating));
        $this->assertSame(8, $this->body($kinetic, 8, DamageOrigin::Thermal), 'a family covers its own types and no others');

        CharacterModifier::query()->delete();
        $blunt = $this->character();
        $this->carries($blunt, ['all_of' => ['damage', 'blunt']], -50);
        $this->assertSame(4, $this->body($blunt, 8, DamageOrigin::Blunt));
        $this->assertSame(8, $this->body($blunt, 8, DamageOrigin::Slashing));

        CharacterModifier::query()->delete();
        $exact = $this->character();
        $this->carries($exact, ['id' => 'damage.hitpoints.penetrating'], -25);
        $this->assertSame(6, $this->body($exact, 8, DamageOrigin::Penetrating));
    }

    public function test_neural_damage_hurts_the_mind_and_is_resisted_there(): void
    {
        $mind = $this->character();
        $this->carries($mind, ['all_of' => ['damage', 'neural']], -50);
        $before = $mind->current_synaptic_integrity;

        $result = DamageResolver::make()->apply($mind, Damage::synapticIntegrity(8, DamageOrigin::Neural));

        $this->assertSame(4, $result->resolved);
        $this->assertSame($before - 4, $mind->fresh()->current_synaptic_integrity);
        $this->assertSame(DamageStats::id('current_synaptic_integrity', DamageOrigin::Neural), $result->damage->statId());
    }

    public function test_every_type_and_family_is_named_so_the_picker_and_the_card_can_say_it(): void
    {
        $names = (array) config('strings.character.names');

        foreach (['damage+kinetic', 'damage+specialty'] as $set) {
            $this->assertArrayHasKey($set, $names);
        }

        foreach ([...self::KINETIC, ...self::SPECIALTY] as $type) {
            $this->assertArrayHasKey("damage+{$type}", $names, "{$type} as a tag set");
            $this->assertArrayHasKey("damage.hitpoints.{$type}", $names);
            $this->assertArrayHasKey("damage.synaptic_integrity.{$type}", $names);
            $this->assertNotSame("damage.origins.{$type}", Text::get("damage.origins.{$type}"), "{$type} has a label");
        }
    }

    public function test_the_modifier_picker_offers_the_families_and_the_types(): void
    {
        $offered = collect(ModifierField::targets())->flatMap(fn (array $group): array => array_keys($group))->all();

        $this->assertContains('damage+kinetic', $offered, 'the family sets are pickable');
        $this->assertContains('damage+specialty', $offered);
        $this->assertContains('damage+slashing', $offered);
        $this->assertContains('damage.hitpoints.penetrating', $offered);
    }
}
