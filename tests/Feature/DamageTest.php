<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterModifier;
use App\Stats\Damage;
use App\Stats\DamageOrigin;
use App\Stats\DamageResolver;
use App\Stats\DamageStats;
use App\Stats\StatRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A hit is a resolved number, not an argument.
 *
 * The rule this file holds is that mitigation is DATA: a resistance is a
 * modifier against a tag set, and nothing in the damage path knows whether one
 * exists. Every case below is written the way content will be — a definition
 * in config, a row on the character — rather than by reaching into the
 * resolver.
 */
final class DamageTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    /**
     * Give the character something that changes what damage does to them.
     *
     * @param  array<string, mixed>  $target
     */
    private function carries(Character $character, array $target, string $op, float $amount): void
    {
        Config::set('effects.test_effect', [
            'label' => 'Test effect',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [['op' => $op, 'amount' => $amount, 'target' => $target]],
        ]);

        CharacterModifier::query()->create([
            'character_id' => $character->id,
            'definition_key' => 'test_effect',
        ]);
    }

    private function hit(Character $character, int $amount, ?DamageOrigin $origin = null): int
    {
        return DamageResolver::make()
            ->apply($character, Damage::synapticIntegrity($amount, $origin ?? DamageOrigin::ReadinessLoss))
            ->resolved;
    }

    // ---- the vocabulary ----------------------------------------------------

    public function test_every_resource_and_origin_has_a_stat(): void
    {
        $registry = app(StatRegistry::class);

        foreach (array_keys(Character::RESOURCES) as $resource) {
            foreach (DamageOrigin::cases() as $origin) {
                $id = DamageStats::id($resource, $origin);

                $this->assertTrue($registry->has($id), "{$id} is not declared");

                $stat = $registry->get($id);
                $this->assertTrue($stat->hasTag('damage'));
                $this->assertTrue($stat->hasTag(DamageStats::resourceTag($resource)));
                $this->assertTrue($stat->hasTag($origin->tag()));
                $this->assertSame(0.0, $stat->min, 'mitigation may never heal');
            }
        }
    }

    /** The tag is the plain word, so a modifier reads as a sentence. */
    public function test_an_origin_tags_itself_plainly(): void
    {
        $this->assertSame('ice', DamageOrigin::Ice->tag());
        $this->assertSame('damage.synaptic_integrity.ice', DamageStats::id('current_synaptic_integrity', DamageOrigin::Ice));
        $this->assertSame('damage.hitpoints.burnout', DamageStats::id('current_hitpoints', DamageOrigin::Burnout));
    }

    public function test_every_origin_has_a_label(): void
    {
        foreach (DamageOrigin::cases() as $origin) {
            $this->assertNotSame($origin->tag(), $origin->label(), "{$origin->value} has no string");
        }
    }

    // ---- what a hit costs --------------------------------------------------

    public function test_an_unmitigated_hit_costs_what_it_says(): void
    {
        $character = $this->character();
        $before = $character->current_synaptic_integrity;

        $result = DamageResolver::make()->apply(
            $character,
            Damage::synapticIntegrity(8, DamageOrigin::Ice),
        );

        $this->assertSame(8, $result->requested);
        $this->assertSame(8, $result->resolved);
        $this->assertSame(8, $result->taken);
        $this->assertFalse($result->wasModified());
        $this->assertSame($before - 8, $character->fresh()->current_synaptic_integrity);
    }

    /** Less left than the hit was for. The pool floors; the hit does not shrink. */
    public function test_taken_is_what_was_there_to_take(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 3])->save();

        $result = DamageResolver::make()->apply(
            $character,
            Damage::synapticIntegrity(8, DamageOrigin::Ice),
        );

        $this->assertSame(8, $result->resolved, 'the hit was still for eight');
        $this->assertSame(3, $result->taken);
        $this->assertSame(0, (int) $character->fresh()->current_synaptic_integrity);
    }

    // ---- mitigation, which is only ever data -------------------------------

    /** Everything that hurts, however it hurts. */
    public function test_a_blanket_resistance_softens_every_hit(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage']], 'percentage_of_base', -25);

        $this->assertSame(6, $this->hit($character, 8, DamageOrigin::Ice));
        $this->assertSame(6, $this->hit($character, 8, DamageOrigin::Burnout));
    }

    /** One resource, every origin. */
    public function test_a_resistance_can_name_the_resource(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage', 'synaptic_integrity']], 'percentage_of_base', -50);

        $this->assertSame(4, $this->hit($character, 8, DamageOrigin::Ice));

        $body = DamageResolver::make()
            ->preview($character, Damage::hitpoints(8, DamageOrigin::Ice))
            ->toInt();

        $this->assertSame(8, $body, 'the mind is armoured, the body is not');
    }

    /** One origin, every resource. */
    public function test_a_resistance_can_name_the_origin(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage', 'ice']], 'percentage_of_base', -50);

        $this->assertSame(4, $this->hit($character, 8, DamageOrigin::Ice));
        $this->assertSame(8, $this->hit($character, 8, DamageOrigin::ReadinessLoss));
    }

    public function test_a_resistance_can_name_the_exact_pair(): void
    {
        $character = $this->character();
        $this->carries(
            $character,
            ['id' => DamageStats::id('current_synaptic_integrity', DamageOrigin::Burnout)],
            'percentage_of_base',
            -75,
        );

        $this->assertSame(2, $this->hit($character, 8, DamageOrigin::Burnout));
        $this->assertSame(8, $this->hit($character, 8, DamageOrigin::Ice));
    }

    /** Effects that make things worse are the same sentence with a sign. */
    public function test_a_vulnerability_takes_a_hit_past_where_it_started(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage']], 'percentage_of_base', 25);

        $this->assertSame(10, $this->hit($character, 8));
    }

    /** Said on purpose, and the only way to get there. */
    public function test_immunity_is_a_multiplier_of_zero(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage', 'ice']], 'multiplier', 0);

        $this->assertSame(0, $this->hit($character, 8, DamageOrigin::Ice));
    }

    /**
     * Percentages SUM and apply once, so resistances cannot creep towards
     * immunity by multiplying against each other. The same rule the rest of
     * the system follows, stated here because this is where it will be tested
     * against by a balance pass.
     */
    public function test_two_resistances_add_up_rather_than_compounding(): void
    {
        $character = $this->character();

        Config::set('effects.sheathing', [
            'label' => 'Sheathing', 'stacking' => 'unique', 'retired' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => -30, 'target' => ['all_of' => ['damage']]]],
        ]);
        Config::set('effects.padding', [
            'label' => 'Padding', 'stacking' => 'unique', 'retired' => false,
            'modifiers' => [['op' => 'percentage_of_base', 'amount' => -30, 'target' => ['all_of' => ['damage']]]],
        ]);

        foreach (['sheathing', 'padding'] as $key) {
            CharacterModifier::query()->create([
                'character_id' => $character->id,
                'definition_key' => $key,
            ]);
        }

        // 40% of 10, not 49%.
        $this->assertSame(4, $this->hit($character, 10));
    }

    /** Mitigation stops at nothing. It never turns a hit into healing. */
    public function test_mitigation_cannot_heal(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage']], 'percentage_of_base', -300);

        $before = $character->current_synaptic_integrity;
        $result = DamageResolver::make()->apply($character, Damage::synapticIntegrity(8, DamageOrigin::Ice));

        $this->assertSame(0, $result->resolved);
        $this->assertSame(0, $result->taken);
        $this->assertSame($before, $character->fresh()->current_synaptic_integrity);
    }

    // ---- saying why --------------------------------------------------------

    /** The resolver's own steps, so a log and the sheet cannot disagree. */
    public function test_the_result_carries_the_breakdown(): void
    {
        $character = $this->character();
        $this->carries($character, ['all_of' => ['damage']], 'percentage_of_base', -25);

        $result = DamageResolver::make()->apply($character, Damage::synapticIntegrity(8, DamageOrigin::Ice));

        $this->assertSame(8.0, $result->breakdown->base);
        $this->assertSame(6.0, $result->breakdown->value);
        $this->assertNotEmpty($result->breakdown->steps);
        $this->assertTrue($result->wasModified());
    }

    /** Asking what a hit would be must not spend anything. */
    public function test_a_preview_takes_nothing(): void
    {
        $character = $this->character();
        $before = $character->current_synaptic_integrity;

        $preview = DamageResolver::make()->preview($character, Damage::synapticIntegrity(8, DamageOrigin::Ice));

        $this->assertSame(8, $preview->toInt());
        $this->assertSame($before, $character->fresh()->current_synaptic_integrity);
    }

    // ---- the guards --------------------------------------------------------

    public function test_damage_is_never_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Damage::synapticIntegrity(-1, DamageOrigin::Ice);
    }

    public function test_a_hit_has_to_name_a_real_resource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Damage(1, 'creds', DamageOrigin::Ice);
    }
}
