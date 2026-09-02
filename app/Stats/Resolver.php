<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * The one place arithmetic happens.
 *
 * Pure: no database, no Eloquent, no randomness, no clock. Given the same
 * inputs it returns the same output, which is what makes the game's balance
 * testable without a fixture.
 *
 * Layer order is fixed and documented in MECHANICS.md:
 *
 *   base
 *     -> + sum(Add)
 *     -> x (1 + sum(PercentageOfBase)/100)      summed, applied once
 *     -> x product(Multiplier)
 *     -> Override, highest priority wins
 *     -> clamp
 *     -> round
 *
 * Clamping and rounding happen once, at the end. Doing either mid-pipeline
 * would make the result depend on the order modifiers arrived in.
 */
final class Resolver
{
    public function __construct(private readonly StatRegistry $stats) {}

    /**
     * @param  iterable<Modifier>  $modifiers
     */
    public function resolve(string $statId, float $base, iterable $modifiers = []): ResolvedStat
    {
        $stat = $this->stats->get($statId);

        /** @var array<int, list<Modifier>> $layers */
        $layers = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ($modifiers as $modifier) {
            if ($modifier->appliesTo($stat)) {
                $layers[$modifier->op->layer()][] = $modifier;
            }
        }

        $steps = [];
        $value = $base;

        // --- 1. flat -----------------------------------------------------
        if ($layers[1] !== []) {
            $sum = array_sum(array_map(fn (Modifier $m): float => $m->amount, $layers[1]));
            $steps[] = new BreakdownStep(Op::Add->value, $sum, $value, $value + $sum, self::sources($layers[1]));
            $value += $sum;
        }

        // --- 2. percentage of (base + flat) ------------------------------
        // Summed, never compounded: three +50% give x2.5, not x3.375.
        if ($layers[2] !== []) {
            $sum = array_sum(array_map(fn (Modifier $m): float => $m->amount, $layers[2]));
            $after = $value * (1 + ($sum / 100));
            $steps[] = new BreakdownStep(Op::PercentageOfBase->value, $sum, $value, $after, self::sources($layers[2]));
            $value = $after;
        }

        // --- 3. multiplicative -------------------------------------------
        if ($layers[3] !== []) {
            $product = 1.0;

            foreach ($layers[3] as $modifier) {
                $product *= $modifier->amount;
            }

            $after = $value * $product;
            $steps[] = new BreakdownStep(Op::Multiplier->value, $product, $value, $after, self::sources($layers[3]));
            $value = $after;
        }

        // --- 4. override --------------------------------------------------
        if ($layers[4] !== []) {
            $winner = self::winningOverride($layers[4]);
            $steps[] = new BreakdownStep(
                Op::Override->value, $winner->amount, $value, $winner->amount,
                $winner->source !== null ? [$winner->source] : []
            );
            $value = $winner->amount;
        }

        $raw = $value;

        // Clamp and round are separate steps in the breakdown: "clamped to 0"
        // and "rounded to 14" are different facts about the number.
        $clamped = $stat->clamp($raw);

        if (abs($clamped - $raw) > 0.000001) {
            $steps[] = new BreakdownStep('clamp', $clamped, $raw, $clamped);
        }

        $final = $stat->round($clamped);

        if (abs($final - $clamped) > 0.000001) {
            $steps[] = new BreakdownStep('round', $final, $clamped, $final);
        }

        return new ResolvedStat($statId, $base, $raw, $final, $steps);
    }

    /**
     * Resolve several stats at once.
     *
     * @param  array<string, float>  $bases
     * @param  iterable<Modifier>  $modifiers
     * @return array<string, ResolvedStat>
     */
    public function resolveAll(array $bases, iterable $modifiers = []): array
    {
        $modifiers = is_array($modifiers) ? $modifiers : iterator_to_array($modifiers);
        $resolved = [];

        foreach ($bases as $statId => $base) {
            $resolved[$statId] = $this->resolve($statId, (float) $base, $modifiers);
        }

        return $resolved;
    }

    /**
     * Highest priority wins.
     *
     * Ties resolve to the LOWEST amount rather than to whichever arrived first,
     * so an override tie can never be order-dependent. Give competing overrides
     * distinct priorities if that is not the outcome you want.
     *
     * @param  list<Modifier>  $overrides
     */
    private static function winningOverride(array $overrides): Modifier
    {
        $winner = $overrides[0];

        foreach ($overrides as $candidate) {
            if ($candidate->priority > $winner->priority) {
                $winner = $candidate;

                continue;
            }

            if ($candidate->priority === $winner->priority && $candidate->amount < $winner->amount) {
                $winner = $candidate;
            }
        }

        return $winner;
    }

    /**
     * @param  list<Modifier>  $modifiers
     * @return list<string>
     */
    private static function sources(array $modifiers): array
    {
        $sources = [];

        foreach ($modifiers as $modifier) {
            if ($modifier->source !== null) {
                $sources[] = $modifier->source;
            }
        }

        sort($sources);

        return array_values(array_unique($sources));
    }
}
