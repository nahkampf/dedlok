<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * The operations a modifier can perform, in resolution order.
 *
 * Add, PercentageOfBase and Multiplier are commutative within their own layer,
 * which is what makes the result independent of the order modifiers arrive in.
 * Override is the only one that is not, which is why it alone carries a
 * priority. Any new op must either be commutative or resolve in the override
 * layer — ResolverTest asserts this property.
 */
enum Op: string
{
    /** Flat addition. Summed across all sources. */
    case Add = 'add';

    /**
     * A percentage of (base + all Add modifiers).
     *
     * "Base" here means the base of the percentage calculation, which is the
     * value AFTER flat bonuses — not the stored base. All percentages are
     * summed and applied once: three +50% give x2.5, never x3.375.
     */
    case PercentageOfBase = 'percentage_of_base';

    /** A compounding factor. Multiplied together. Use sparingly. */
    case Multiplier = 'multiplier';

    /** Replaces everything before it. Highest priority wins. */
    case Override = 'override';

    /** The layer this op resolves in. Lower runs first. */
    public function layer(): int
    {
        return match ($this) {
            self::Add => 1,
            self::PercentageOfBase => 2,
            self::Multiplier => 3,
            self::Override => 4,
        };
    }
}
