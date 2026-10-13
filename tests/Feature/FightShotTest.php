<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Combat\ArmourSlot;
use App\Combat\Fight\Arm;
use App\Combat\Fight\Combatant;
use App\Combat\Fight\FightEngine;
use App\Combat\Fight\FightState;
use App\Combat\Fight\Range;
use App\Combat\Fight\RoundSpec;
use App\Combat\Fight\Side;
use App\Combat\HitQuality;
use App\Combat\WeaponClass;
use App\Stats\DamageOrigin;
use Tests\Support\MapMitigation;
use Tests\Support\QueueDice;
use Tests\TestCase;

/**
 * A shot, then a burst: the pure core of the fight engine. COMBAT.md, *Hit quality* and *Burst*.
 *
 * With aim equal to defence the chance is one half, so a die of 0 puts the score at 0.8 (Dead-on)
 * and a die of 0.5 at 0.5 (Partial... the middle fifth is Partial's 0.4–0.6). Slot dice: 0.0 is the
 * head and 0.99 the torso.
 */
final class FightShotTest extends TestCase
{
    private function arm(int $rate = 1, ?int $rounds = 12, array $profile = ['penetrating' => 100], float $damage = 10.0, int $penetration = 0, WeaponClass $class = WeaponClass::Pistol): Arm
    {
        return new Arm('Pistol', $class, 10.0, $rate, ['std' => new RoundSpec('std', 'Standard', $damage, $profile, $penetration)], 'std', $rounds, 12);
    }

    private function state(Arm $ranged, int $foeHp = 100, ?int $foeSi = null, float $foeDefence = 10.0): FightState
    {
        return new FightState(
            new Combatant('You', 40, 8.0, ranged: $ranged, si: 30),
            new Combatant('Gang member', $foeHp, $foeDefence, si: $foeSi),
        );
    }

    private function engine(): FightEngine
    {
        return new FightEngine(CheckRoller::fromConfig());
    }

    public function test_a_shot_rolls_its_tier_then_its_place_and_costs_a_round(): void
    {
        $dice = new QueueDice([0.0, 0.0]);           // Dead-on, the head
        $mitigation = new MapMitigation;

        $after = $this->engine()->attack($this->state($this->arm()), Side::A, $dice, $mitigation);

        $dice->assertSpent();
        $this->assertSame(90, $after->b->hp, 'Dead-on is the whole 10');
        $this->assertSame(11, $after->a->ranged->rounds);
        $this->assertSame(40, $after->a->hp, 'the attacker is untouched');

        $this->assertCount(1, $mitigation->seen);
        $this->assertSame(Side::B, $mitigation->seen[0]['side']);
        $this->assertSame(ArmourSlot::Head, $mitigation->seen[0]['damage']->location);
        $this->assertSame(DamageOrigin::Penetrating, $mitigation->seen[0]['damage']->origin);
        $this->assertSame('Pistol', $after->a->ranged->name);
    }

    public function test_a_worse_roll_is_a_weaker_hit_in_a_different_place(): void
    {
        $after = $this->engine()->attack($this->state($this->arm()), Side::A, new QueueDice([0.99, 0.99]), new MapMitigation);

        // 0.5 + 0.6 × (0.5 − 0.99) = 0.206: the second fifth, Glancing, a quarter of 10 → 3 (2.5 rounds up).
        $this->assertSame(97, $after->b->hp);
        $this->assertSame('You: Glancing to the Torso for 3 penetrating.', $after->log[0]['text']);
        $this->assertSame('ok', $after->log[0]['tone'], 'a hit on side B is good news for A');
        $this->assertSame(1, $after->log[0]['round']);
    }

    public function test_resistance_is_the_mitigations_and_penetration_goes_with_the_hit(): void
    {
        $mitigation = new MapMitigation(['b' => ['penetrating' => 60]]);

        $plain = $this->engine()->attack($this->state($this->arm()), Side::A, new QueueDice([0.0, 0.5]), $mitigation);
        $piercing = $this->engine()->attack($this->state($this->arm(penetration: 50)), Side::A, new QueueDice([0.0, 0.5]), $mitigation);

        $this->assertSame(96, $plain->b->hp, '60% off 10');
        $this->assertSame(93, $piercing->b->hp, 'armour piercing: 30% off');
    }

    public function test_a_profile_is_split_and_each_part_is_resolved_on_its_own(): void
    {
        $state = $this->state($this->arm(profile: ['blunt' => 70, 'electrical' => 30]));
        $mitigation = new MapMitigation(['b' => ['electrical' => 100]]);

        $after = $this->engine()->attack($state, Side::A, new QueueDice([0.0, 0.5]), $mitigation);

        $this->assertSame(93, $after->b->hp, 'the 3 electrical was immune; the 7 blunt landed');
        $this->assertSame('You: Dead-on to the Right leg for 7 blunt, 0 electrical.', $after->log[0]['text']);
    }

    public function test_a_hit_that_does_nothing_says_so(): void
    {
        // A weapon doing a hundredth: a near miss of it is under half a hundredth and comes to nothing.
        $after = $this->engine()->attack($this->state($this->arm(damage: 0.01)), Side::A, new QueueDice([0.999, 0.5]), new MapMitigation);

        $this->assertSame(100, $after->b->hp);
        $this->assertStringContainsString('Nothing gets through', $after->log[0]['text']);
        $this->assertSame(11, $after->a->ranged->rounds, 'the round was still fired');
    }

    public function test_neural_damage_goes_to_synaptic_integrity_and_does_nothing_to_a_side_without_any(): void
    {
        $neural = $this->arm(profile: ['neural' => 100]);

        $withSi = $this->engine()->attack($this->state($neural, foeSi: 20), Side::A, new QueueDice([0.0, 0.5]), new MapMitigation);
        $this->assertSame(10, $withSi->b->si);
        $this->assertSame(100, $withSi->b->hp);

        $mitigation = new MapMitigation;
        $noSi = $this->engine()->attack($this->state($neural, foeSi: null), Side::A, new QueueDice([0.0, 0.5]), $mitigation);
        $this->assertSame(100, $noSi->b->hp, 'an NPC has no mind to hurt: a neural hit does nothing at all');
        $this->assertSame([], $mitigation->seen, 'it was not even resolved');
        $this->assertStringContainsString('Nothing gets through', $noSi->log[0]['text']);
    }

    // ---- bursts -------------------------------------------------------------------------

    public function test_each_shot_of_a_burst_is_its_own_roll_and_each_is_worse_than_the_last(): void
    {
        $state = $this->state($this->arm(rate: 3));
        $quote = $this->engine()->quote($state, Side::A);

        $this->assertCount(3, $quote);
        $this->assertEqualsWithDelta(0.5, $quote[0], 1e-9, 'aim equals defence: a coin flip');
        $this->assertEqualsWithDelta(0.5 * 0.93, $quote[1], 1e-9);
        $this->assertEqualsWithDelta(0.5 * 0.93 ** 2, $quote[2], 1e-9);

        $dice = new QueueDice([0.0, 0.0,  0.0, 0.5,  0.0, 0.99]);
        $after = $this->engine()->attack($state, Side::A, $dice, new MapMitigation);

        $dice->assertSpent();
        $this->assertCount(3, $after->log);
        $this->assertSame(9, $after->a->ranged->rounds, 'three rounds');
        $this->assertSame(['Head', 'Right leg', 'Torso'], array_map(fn (array $l): string => preg_match('/to the (.+?) for/', $l['text'], $m) ? $m[1] : '', $after->log));
    }

    public function test_the_quote_is_what_the_roll_used(): void
    {
        // The same die against the same chances: a tier decided by quote()'s own number.
        $state = $this->state($this->arm(rate: 2));
        $chances = $this->engine()->quote($state, Side::A);
        $mitigation = new MapMitigation;

        $after = $this->engine()->attack($state, Side::A, new QueueDice([0.3, 0.5, 0.3, 0.5]), $mitigation);

        $expected = array_map(fn (float $c) => HitQuality::fromRoll(0.3, $c)->label(), $chances);
        $this->assertSame($expected, array_map(fn (array $l): string => preg_match('/: (.+?) to the/', $l['text'], $m) ? $m[1] : '', $after->log));
    }

    public function test_a_burst_fires_only_what_is_left_in_the_magazine(): void
    {
        $dice = new QueueDice([0.0, 0.5,  0.0, 0.5]);
        $after = $this->engine()->attack($this->state($this->arm(rate: 3, rounds: 2)), Side::A, $dice, new MapMitigation);

        $dice->assertSpent();
        $this->assertSame(0, $after->a->ranged->rounds);
        $this->assertCount(2, $after->log);
        $this->assertFalse($after->a->ranged->canFire(), 'at zero it cannot fire');
        $this->assertSame([], $this->engine()->quote($after, Side::A), 'and there is nothing to quote');
    }

    public function test_a_burst_stops_when_the_target_goes_down(): void
    {
        $dice = new QueueDice([0.0, 0.5,  0.0, 0.5]);
        $after = $this->engine()->attack($this->state($this->arm(rate: 3), foeHp: 15), Side::A, $dice, new MapMitigation);

        $dice->assertSpent();                             // the third shot was never rolled
        $this->assertSame(0, $after->b->hp);
        $this->assertTrue($after->b->down());
        $this->assertSame(10, $after->a->ranged->rounds, 'two rounds spent, not three');
    }

    public function test_a_side_that_does_not_count_ammunition_never_runs_dry(): void
    {
        $dice = new QueueDice([0.0, 0.5,  0.0, 0.5,  0.0, 0.5]);
        $after = $this->engine()->attack($this->state($this->arm(rate: 3, rounds: null)), Side::A, $dice, new MapMitigation);

        $this->assertNull($after->a->ranged->rounds);
        $this->assertSame(3, $after->a->ranged->shots());
    }

    // ---- plain data ---------------------------------------------------------------------

    public function test_a_state_survives_being_stored_and_rebuilt(): void
    {
        $state = $this->state($this->arm(rate: 3));
        $state = $this->engine()->attack($state, Side::A, new QueueDice([0.0, 0.5,  0.0, 0.5,  0.0, 0.5]), new MapMitigation);

        $rebuilt = FightState::fromArray(json_decode((string) json_encode($state->toArray()), true));

        $this->assertEquals($state, $rebuilt);
        $this->assertSame($state->toArray(), $rebuilt->toArray());
        $this->assertSame(Range::Ranged, $rebuilt->range);
    }

    public function test_the_engine_does_not_change_the_state_it_was_given(): void
    {
        $state = $this->state($this->arm());
        $before = $state->toArray();

        $this->engine()->attack($state, Side::A, new QueueDice([0.0, 0.5]), new MapMitigation);

        $this->assertSame($before, $state->toArray());
    }
}
