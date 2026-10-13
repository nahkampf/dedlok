<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Combat\ArmourSlot;
use App\Combat\Burst;
use App\Combat\HitDamage;
use App\Combat\HitQuality;
use Tests\TestCase;

/**
 * The pure pieces of a shot: which tier, which body part, how much worse each burst shot is, and
 * what a hit's damage splits into. No database, no dice but the ones passed in.
 */
final class HitRollTest extends TestCase
{
    // ---- the tier -----------------------------------------------------------------------

    public function test_the_bands_are_five_equal_fifths_of_the_score(): void
    {
        // spread 0 makes the die irrelevant, so v is the chance itself and the edges show.
        $at = fn (float $c): HitQuality => HitQuality::fromRoll(0.5, $c, 0.0);

        $this->assertSame(HitQuality::NearMiss, $at(0.0));
        $this->assertSame(HitQuality::NearMiss, $at(0.19));
        $this->assertSame(HitQuality::Glancing, $at(0.20));
        $this->assertSame(HitQuality::Partial, $at(0.40));
        $this->assertSame(HitQuality::CleanHit, $at(0.60));
        $this->assertSame(HitQuality::DeadOn, $at(0.80));
        $this->assertSame(HitQuality::DeadOn, $at(1.0), 'exactly one belongs to the top band');
    }

    public function test_a_low_die_is_a_good_shot_and_a_high_one_a_bad_one(): void
    {
        $this->assertSame(HitQuality::DeadOn, HitQuality::fromRoll(0.0, 0.75, 0.6));
        $this->assertSame(HitQuality::CleanHit, HitQuality::fromRoll(0.5, 0.75, 0.6));
        $this->assertSame(HitQuality::Partial, HitQuality::fromRoll(0.999, 0.75, 0.6));
    }

    public function test_the_score_is_clamped_so_nothing_leaves_the_ladder(): void
    {
        $this->assertSame(HitQuality::NearMiss, HitQuality::fromRoll(0.999, 0.0, 0.6), 'the worst possible shot');
        $this->assertSame(HitQuality::DeadOn, HitQuality::fromRoll(0.0, 1.0, 0.6), 'the best possible one');
    }

    public function test_the_default_spread_is_the_configured_one(): void
    {
        $this->assertSame(0.6, (float) config('combat.spread'));
        $this->assertSame(HitQuality::fromRoll(0.2, 0.5, 0.6), HitQuality::fromRoll(0.2, 0.5));

        config(['combat.spread' => 0.0]);
        $this->assertSame(HitQuality::fromRoll(0.99, 0.5, 0.0), HitQuality::fromRoll(0.99, 0.5), 'config is what a missing argument reads');
    }

    /** The reason for the formula: a quoted chance should be what a shot is worth, on average. */
    public function test_a_shot_is_worth_about_its_chance_on_average(): void
    {
        foreach ([0.10, 0.25, 0.50, 0.75, 0.90] as $chance) {
            $steps = 10000;
            $total = 0.0;

            for ($i = 0; $i < $steps; $i++) {
                $total += HitQuality::fromRoll(($i + 0.5) / $steps, $chance)->fraction();
            }

            $this->assertEqualsWithDelta($chance, $total / $steps, 0.06, "chance {$chance}");
        }
    }

    public function test_the_tiers_are_ordered_weakest_first_and_never_zero(): void
    {
        $fractions = array_map(fn (HitQuality $q): float => $q->fraction(), HitQuality::cases());

        $sorted = $fractions;
        sort($sorted);

        $this->assertSame($sorted, $fractions);
        $this->assertGreaterThan(0.0, $fractions[0], 'no outright miss');
    }

    // ---- the body part ------------------------------------------------------------------

    public function test_the_body_part_weights_add_to_a_hundred(): void
    {
        $this->assertSame(100, array_sum((array) config('combat.body_parts')));
        $this->assertEqualsCanonicalizing(ArmourSlot::values(), array_keys((array) config('combat.body_parts')), 'every slot has a weight and no weight is for a stranger');
    }

    public function test_the_die_walks_the_weights_in_order(): void
    {
        $this->assertSame(ArmourSlot::Head, ArmourSlot::roll(0.0));
        $this->assertSame(ArmourSlot::Head, ArmourSlot::roll(0.0899));
        $this->assertSame(ArmourSlot::ArmLeft, ArmourSlot::roll(0.09));
        $this->assertSame(ArmourSlot::ArmRight, ArmourSlot::roll(0.18));
        $this->assertSame(ArmourSlot::LegLeft, ArmourSlot::roll(0.27));
        $this->assertSame(ArmourSlot::LegRight, ArmourSlot::roll(0.45));
        $this->assertSame(ArmourSlot::Torso, ArmourSlot::roll(0.63));
        $this->assertSame(ArmourSlot::Torso, ArmourSlot::roll(0.999));
        $this->assertSame(ArmourSlot::Torso, ArmourSlot::roll(1.0), 'a die of exactly one is the rest');
    }

    public function test_a_flat_die_lands_in_the_configured_proportions(): void
    {
        $count = array_fill_keys(ArmourSlot::values(), 0);
        $steps = 10000;

        for ($i = 0; $i < $steps; $i++) {
            $count[ArmourSlot::roll(($i + 0.5) / $steps)->value]++;
        }

        $this->assertEquals(
            ['head' => 900, 'arm_left' => 900, 'arm_right' => 900, 'leg_left' => 1800, 'leg_right' => 1800, 'torso' => 3700],
            $count,
        );
    }

    // ---- burst --------------------------------------------------------------------------

    public function test_each_shot_of_a_burst_is_seven_percent_worse_than_the_one_before(): void
    {
        $this->assertSame(1.0, Burst::multiplier(0), 'the first shot is the full chance');
        $this->assertEqualsWithDelta(0.93, Burst::multiplier(1), 1e-9);
        $this->assertEqualsWithDelta(0.8649, Burst::multiplier(2), 1e-9);
        $this->assertEqualsWithDelta(0.93 ** 9, Burst::multiplier(9), 1e-9, 'compounding');
        $this->assertEqualsWithDelta(0.52, Burst::multiplier(9), 0.01, 'a ten-round burst ends at about half');
    }

    public function test_the_falloff_is_the_configured_one(): void
    {
        config(['combat.burst_falloff' => 0.10]);

        $this->assertEqualsWithDelta(0.81, Burst::multiplier(2), 1e-9);
    }

    public function test_a_burst_never_starts_before_the_first_shot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Burst::multiplier(-1);
    }

    public function test_a_weapon_fires_its_rate_or_what_is_left(): void
    {
        $this->assertSame(3, Burst::shots(3, 10));
        $this->assertSame(2, Burst::shots(3, 2), 'three shots with two rounds left is two');
        $this->assertSame(0, Burst::shots(3, 0), 'at zero it cannot fire');
        $this->assertSame(1, Burst::shots(1, 12));
    }

    // ---- the damage ---------------------------------------------------------------------

    public function test_a_hit_scales_by_its_tier_then_splits_by_its_profile(): void
    {
        $this->assertSame(['slashing' => 10], HitDamage::parts(10.0, HitQuality::DeadOn, ['slashing' => 100]));
        $this->assertSame(['slashing' => 7.5], HitDamage::parts(10.0, HitQuality::CleanHit, ['slashing' => 100]), '75% of 10 is 7.5, kept to two decimals');
        $this->assertSame(['slashing' => 5], HitDamage::parts(10.0, HitQuality::Partial, ['slashing' => 100]));
        $this->assertSame(['slashing' => 2.5], HitDamage::parts(10.0, HitQuality::Glancing, ['slashing' => 100]));
        $this->assertSame(['slashing' => 1], HitDamage::parts(10.0, HitQuality::NearMiss, ['slashing' => 100]));
    }

    public function test_a_combined_profile_is_resolved_part_by_part(): void
    {
        // A stun baton: 70 blunt, 30 electrical, of a hit of 10.
        $this->assertSame(['blunt' => 7, 'electrical' => 3], HitDamage::parts(10.0, HitQuality::DeadOn, ['blunt' => 70, 'electrical' => 30]));
        // Half of 10 is 5, split 3.5 and 1.5, which is exact now.
        $this->assertSame(['blunt' => 3.5, 'electrical' => 1.5], HitDamage::parts(10.0, HitQuality::Partial, ['blunt' => 70, 'electrical' => 30]));
    }

    public function test_a_part_that_comes_to_nothing_is_dropped(): void
    {
        // A near miss of 4 is 0.4; its 70/30 split is 0.28 and 0.12, both real now.
        $this->assertSame(['blunt' => 0.28, 'electrical' => 0.12], HitDamage::parts(4.0, HitQuality::NearMiss, ['blunt' => 70, 'electrical' => 30]));
        // Under half a hundredth is nothing.
        $this->assertSame([], HitDamage::parts(0.01, HitQuality::NearMiss, ['blunt' => 70, 'electrical' => 30]));
    }

    public function test_the_base_is_not_rounded_before_it_is_scaled(): void
    {
        // 9.6 at 25% is 2.4; rounding 9.6 to 10 first would give 2.5.
        $this->assertSame(['slashing' => 2.4], HitDamage::parts(9.6, HitQuality::Glancing, ['slashing' => 100]));
    }

    public function test_no_damage_is_no_parts(): void
    {
        $this->assertSame([], HitDamage::parts(0.0, HitQuality::DeadOn, ['slashing' => 100]));
    }
}
