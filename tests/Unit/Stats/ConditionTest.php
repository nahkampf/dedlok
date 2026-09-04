<?php

declare(strict_types=1);

namespace Tests\Unit\Stats;

use App\Stats\Condition;
use App\Stats\Context;
use App\Stats\Modifier;
use App\Stats\Predicates;
use App\Stats\Resolver;
use App\Stats\StatRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Conditional modifiers: what they do, and what they deliberately do not.
 *
 * No framework here — the whole point of a Context being a bag of numbers is
 * that "what happens under 30% SI" is a question a unit test can ask without a
 * database.
 */
final class ConditionTest extends TestCase
{
    private function resolver(): Resolver
    {
        return new Resolver(StatRegistry::fromDefinitions([
            'focus' => ['tags' => ['attribute', 'mental'], 'min' => 0, 'max' => 100, 'rounding' => 'round'],
        ]));
    }

    private function underPressure(float $siRatio): Modifier
    {
        return Modifier::fromArray([
            'op' => 'add',
            'amount' => 10,
            'target' => ['id' => 'focus'],
            'source' => 'Adrenal surge',
            'condition' => ['when' => 'si_below_pct', 'args' => ['pct' => $siRatio]],
        ]);
    }

    public function test_a_condition_that_holds_applies_the_modifier(): void
    {
        $resolved = $this->resolver()->resolve(
            'focus', 20.0, [$this->underPressure(30)], Context::of(['si_pct' => 0.2])
        );

        $this->assertSame(30.0, $resolved->value);
    }

    public function test_a_condition_that_does_not_hold_leaves_the_stat_alone(): void
    {
        $resolved = $this->resolver()->resolve(
            'focus', 20.0, [$this->underPressure(30)], Context::of(['si_pct' => 0.9])
        );

        $this->assertSame(20.0, $resolved->value);
        $this->assertSame([], $resolved->steps, 'a modifier that did not apply leaves no step');
    }

    /**
     * The safe direction, and the one the caching story depends on: resolving
     * with NO context is asking for the context-free value, so a conditional
     * modifier is left out rather than assumed to hold.
     */
    public function test_without_a_context_a_conditional_modifier_is_left_out(): void
    {
        $resolved = $this->resolver()->resolve('focus', 20.0, [$this->underPressure(30)]);

        $this->assertSame(20.0, $resolved->value);
    }

    /**
     * The other half of that: an unconditional modifier is unaffected by there
     * being no context, or it would be impossible to cache anything.
     */
    public function test_an_unconditional_modifier_needs_no_context(): void
    {
        $flat = Modifier::fromArray(['op' => 'add', 'amount' => 4, 'target' => ['id' => 'focus']]);

        $this->assertSame(24.0, $this->resolver()->resolve('focus', 20.0, [$flat])->value);
        $this->assertFalse($flat->isConditional());
    }

    /**
     * A predicate whose context key is absent cannot answer, and the answer is
     * then no. A conditional bonus that silently applies everywhere is a
     * balance bug with no symptom; a conditional penalty is worse.
     */
    public function test_a_missing_context_key_means_the_condition_does_not_hold(): void
    {
        $resolved = $this->resolver()->resolve(
            'focus', 20.0, [$this->underPressure(30)], Context::of(['jacked_in' => true])
        );

        $this->assertSame(20.0, $resolved->value);
    }

    public function test_the_boundary_is_strictly_below(): void
    {
        $at = Context::of(['si_pct' => 0.30]);
        $under = Context::of(['si_pct' => 0.2999]);

        $this->assertFalse(Condition::fromArray(['when' => 'si_below_pct', 'args' => ['pct' => 30]])->holds($at));
        $this->assertTrue(Condition::fromArray(['when' => 'si_below_pct', 'args' => ['pct' => 30]])->holds($under));
    }

    public function test_jacked_in_and_out_are_mirrors(): void
    {
        $inside = Context::of(['jacked_in' => true]);

        $this->assertTrue(Predicates::test('jacked_in', $inside));
        $this->assertFalse(Predicates::test('jacked_out', $inside));

        // Absent means jacked out, which is the state everybody starts in.
        $this->assertFalse(Predicates::test('jacked_in', Context::empty()));
        $this->assertTrue(Predicates::test('jacked_out', Context::empty()));
    }

    public function test_heat_is_an_absolute_because_it_has_no_ceiling(): void
    {
        $this->assertTrue(Predicates::test('heat_above', Context::of(['heat' => 40]), ['value' => 25]));
        $this->assertFalse(Predicates::test('heat_above', Context::of(['heat' => 10]), ['value' => 25]));
    }

    public function test_a_condition_missing_its_argument_is_an_error_not_a_false(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing a numeric \[pct\]/');

        Condition::fromArray(['when' => 'si_below_pct'])->holds(Context::of(['si_pct' => 0.1]));
    }

    public function test_unknown_condition_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown condition keys: unless/');

        Condition::fromArray(['when' => 'jacked_in', 'unless' => 'nope']);
    }
}
