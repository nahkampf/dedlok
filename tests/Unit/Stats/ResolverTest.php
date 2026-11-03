<?php

namespace Tests\Unit\Stats;

use App\Stats\Modifier;
use App\Stats\Op;
use App\Stats\Resolver;
use App\Stats\StatRegistry;
use App\Stats\Target;
use PHPUnit\Framework\TestCase;

/**
 * The resolver is pure, so these are plain PHPUnit tests with no framework
 * boot, no database and a hand-built registry.
 */
class ResolverTest extends TestCase
{
    private function resolver(): Resolver
    {
        return new Resolver(StatRegistry::fromDefinitions([
            'focus' => ['tags' => ['attribute', 'mental'], 'min' => 0, 'max' => 100, 'rounding' => 'round'],
            'muscle' => ['tags' => ['attribute', 'physical'], 'min' => 0, 'max' => 100, 'rounding' => 'round'],
            'unbounded' => ['tags' => ['test'], 'rounding' => 'none'],
        ]));
    }

    public function test_a_stat_with_no_modifiers_is_its_base(): void
    {
        $r = $this->resolver()->resolve('focus', 15);

        $this->assertSame(15.0, $r->value);
        $this->assertSame([], $r->steps);
    }

    public function test_flat_modifiers_sum(): void
    {
        $r = $this->resolver()->resolve('unbounded', 10, [
            new Modifier(Op::Add, 5, Target::id('unbounded')),
            new Modifier(Op::Add, -2, Target::id('unbounded')),
        ]);

        $this->assertSame(13.0, $r->value);
    }

    /** The rule the whole balance model rests on. */
    public function test_percentages_are_summed_not_compounded(): void
    {
        $r = $this->resolver()->resolve('unbounded', 100, [
            new Modifier(Op::PercentageOfBase, 50, Target::id('unbounded')),
            new Modifier(Op::PercentageOfBase, 50, Target::id('unbounded')),
            new Modifier(Op::PercentageOfBase, 50, Target::id('unbounded')),
        ]);

        $this->assertSame(250.0, $r->value, 'summed gives x2.5; compounding would give x3.375');
    }

    public function test_percentage_applies_to_base_plus_flat(): void
    {
        // 15 base, +2 flat = 17, then -20% = 13.6
        $r = $this->resolver()->resolve('unbounded', 15, [
            new Modifier(Op::Add, 2, Target::id('unbounded')),
            new Modifier(Op::PercentageOfBase, -20, Target::id('unbounded')),
        ]);

        $this->assertEqualsWithDelta(13.6, $r->value, 0.0001);
        $this->assertNotEqualsWithDelta(14.0, $r->value, 0.0001, 'must not apply the percentage to the raw base');
    }

    public function test_flat_is_untouched_by_percentages_but_not_by_immunity(): void
    {
        $flat = new Modifier(Op::Flat, -2, Target::id('unbounded'));
        $resist = new Modifier(Op::PercentageOfBase, -15, Target::id('unbounded'));

        $this->assertSame(6.5, $this->resolver()->resolve('unbounded', 10, [$flat, $resist])->value, '10 x 0.85 - 2');
        $this->assertSame(0.0, $this->resolver()->resolve('unbounded', 10, [$flat, new Modifier(Op::Multiplier, 0, Target::id('unbounded'))])->value);
    }

    public function test_multipliers_compound(): void
    {
        $r = $this->resolver()->resolve('unbounded', 100, [
            new Modifier(Op::Multiplier, 0.5, Target::id('unbounded')),
            new Modifier(Op::Multiplier, 0.5, Target::id('unbounded')),
        ]);

        $this->assertSame(25.0, $r->value);
    }

    public function test_layers_run_in_order(): void
    {
        // (10 + 10) * 1.5 * 2 = 60
        $r = $this->resolver()->resolve('unbounded', 10, [
            new Modifier(Op::Multiplier, 2, Target::id('unbounded')),
            new Modifier(Op::PercentageOfBase, 50, Target::id('unbounded')),
            new Modifier(Op::Add, 10, Target::id('unbounded')),
        ]);

        $this->assertSame(60.0, $r->value);
    }

    /**
     * The property everything else depends on. If this ever fails, some layer
     * has stopped being commutative and the arithmetic is order-dependent.
     */
    public function test_the_result_does_not_depend_on_modifier_order(): void
    {
        $modifiers = [
            new Modifier(Op::Add, 7, Target::id('focus')),
            new Modifier(Op::Add, -3, Target::id('focus')),
            new Modifier(Op::PercentageOfBase, 25, Target::allOf(['attribute'])),
            new Modifier(Op::PercentageOfBase, -40, Target::allOf(['mental'])),
            new Modifier(Op::Multiplier, 1.5, Target::id('focus')),
            new Modifier(Op::Multiplier, 0.8, Target::id('focus')),
        ];

        $expected = $this->resolver()->resolve('focus', 20, $modifiers)->value;

        for ($i = 0; $i < 50; $i++) {
            $shuffled = $modifiers;
            shuffle($shuffled);

            $this->assertSame(
                $expected,
                $this->resolver()->resolve('focus', 20, $shuffled)->value,
                'resolution became order-dependent'
            );
        }
    }

    // ---- clamping -------------------------------------------------------

    public function test_values_go_negative_then_clamp(): void
    {
        $r = $this->resolver()->resolve('focus', 5, [
            new Modifier(Op::Add, -20, Target::id('focus')),
        ]);

        $this->assertSame(-15.0, $r->raw, 'raw must keep the true value');
        $this->assertSame(0.0, $r->value, 'value must be clamped to the floor');
    }

    /**
     * Clamping early would make this differ: clamp-at-zero then +5 gives 5,
     * where clamping at the end gives 0.
     */
    public function test_a_buff_cannot_rescue_a_value_from_below_the_floor(): void
    {
        $r = $this->resolver()->resolve('focus', 5, [
            new Modifier(Op::Add, -20, Target::id('focus')),
            new Modifier(Op::Add, 5, Target::id('focus')),
        ]);

        $this->assertSame(-10.0, $r->raw);
        $this->assertSame(0.0, $r->value);
    }

    public function test_values_go_over_the_ceiling_then_clamp(): void
    {
        $r = $this->resolver()->resolve('focus', 90, [
            new Modifier(Op::Add, 40, Target::id('focus')),
        ]);

        $this->assertSame(130.0, $r->raw);
        $this->assertSame(100.0, $r->value);
    }

    public function test_an_unbounded_stat_is_not_clamped(): void
    {
        $r = $this->resolver()->resolve('unbounded', 0, [
            new Modifier(Op::Add, -500, Target::id('unbounded')),
        ]);

        $this->assertSame(-500.0, $r->value);
    }

    // ---- targeting ------------------------------------------------------

    public function test_a_tag_target_hits_every_matching_stat(): void
    {
        $all = new Modifier(Op::Add, 5, Target::allOf(['attribute']));

        $this->assertSame(20.0, $this->resolver()->resolve('focus', 15, [$all])->value);
        $this->assertSame(15.0, $this->resolver()->resolve('muscle', 10, [$all])->value);
    }

    public function test_a_tag_target_requires_every_tag(): void
    {
        $mental = new Modifier(Op::Add, 5, Target::allOf(['attribute', 'mental']));

        $this->assertSame(20.0, $this->resolver()->resolve('focus', 15, [$mental])->value);
        $this->assertSame(10.0, $this->resolver()->resolve('muscle', 10, [$mental])->value, 'muscle is physical');
    }

    public function test_modifiers_for_other_stats_are_ignored(): void
    {
        $r = $this->resolver()->resolve('focus', 15, [
            new Modifier(Op::Add, 99, Target::id('muscle')),
        ]);

        $this->assertSame(15.0, $r->value);
    }

    // ---- override -------------------------------------------------------

    public function test_override_replaces_everything_before_it(): void
    {
        $r = $this->resolver()->resolve('focus', 15, [
            new Modifier(Op::Add, 50, Target::id('focus')),
            new Modifier(Op::Override, 0, Target::id('focus'), 'Unconscious'),
        ]);

        $this->assertSame(0.0, $r->value);
    }

    public function test_the_highest_priority_override_wins(): void
    {
        $r = $this->resolver()->resolve('focus', 15, [
            new Modifier(Op::Override, 5, Target::id('focus'), 'Dazed', priority: 1),
            new Modifier(Op::Override, 0, Target::id('focus'), 'Unconscious', priority: 10),
        ]);

        $this->assertSame(0.0, $r->value);
    }

    public function test_tied_overrides_resolve_deterministically_not_by_order(): void
    {
        $a = new Modifier(Op::Override, 7, Target::id('focus'), 'A', priority: 5);
        $b = new Modifier(Op::Override, 3, Target::id('focus'), 'B', priority: 5);

        $this->assertSame(3.0, $this->resolver()->resolve('focus', 15, [$a, $b])->value);
        $this->assertSame(3.0, $this->resolver()->resolve('focus', 15, [$b, $a])->value);
    }

    // ---- breakdown ------------------------------------------------------

    public function test_the_breakdown_explains_the_number(): void
    {
        $r = $this->resolver()->resolve('focus', 15, [
            new Modifier(Op::Add, 2, Target::id('focus'), 'Focus III'),
            new Modifier(Op::PercentageOfBase, -10, Target::id('focus'), 'Sandevistan'),
            new Modifier(Op::PercentageOfBase, -10, Target::allOf(['attribute', 'mental']), 'Neural damper'),
        ]);

        $ops = array_map(fn ($s) => $s->op, $r->steps);
        $this->assertSame(['add', 'percentage_of_base', 'round'], $ops);

        $this->assertSame(['Focus III'], $r->steps[0]->sources);
        $this->assertSame(['Neural damper', 'Sandevistan'], $r->steps[1]->sources);
        $this->assertEqualsWithDelta(13.6, $r->raw, 0.0001);
        $this->assertSame(14.0, $r->value);
    }

    public function test_clamp_and_round_are_reported_separately(): void
    {
        $r = $this->resolver()->resolve('focus', 5, [
            new Modifier(Op::Add, -20, Target::id('focus')),
        ]);

        $this->assertSame(['add', 'clamp'], array_map(fn ($s) => $s->op, $r->steps));
    }

    public function test_each_step_starts_where_the_last_one_ended(): void
    {
        $r = $this->resolver()->resolve('focus', 20, [
            new Modifier(Op::Add, 10, Target::id('focus')),
            new Modifier(Op::PercentageOfBase, 25, Target::id('focus')),
            new Modifier(Op::Multiplier, 3, Target::id('focus')),
        ]);

        $previous = $r->base;

        foreach ($r->steps as $step) {
            $this->assertEqualsWithDelta($previous, $step->from, 0.0001, 'breakdown is not continuous');
            $previous = $step->to;
        }

        $this->assertEqualsWithDelta($r->value, $previous, 0.0001);
    }

    // ---- misc ------------------------------------------------------------

    public function test_resolve_all_handles_a_whole_sheet(): void
    {
        $resolved = $this->resolver()->resolveAll(
            ['focus' => 15, 'muscle' => 10],
            [new Modifier(Op::Add, 5, Target::allOf(['attribute']))],
        );

        $this->assertSame(20.0, $resolved['focus']->value);
        $this->assertSame(15.0, $resolved['muscle']->value);
    }

    public function test_an_unknown_stat_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver()->resolve('nonexistent', 1);
    }
}
