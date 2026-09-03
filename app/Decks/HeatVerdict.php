<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * How one fitted module is faring in the heat the deck is running at.
 *
 * Three states, decided by two thresholds on the MODULE compared against the
 * deck's net heat. The same stick of RAM is Safe in a cold deck and OverLimit
 * in a hot one — which is the point of the mechanic, and why this is a verdict
 * about a pair rather than a property of either half.
 */
enum HeatVerdict: string
{
    /** Below the module's warning level, or it has no limits at all. */
    case Safe = 'safe';

    /**
     * At or above the warning level.
     *
     * Where the risk of destruction begins. The risk itself is not built — the
     * algorithm is undecided — so today this is a state to see rather than a
     * roll to make.
     */
    case AtRisk = 'at_risk';

    /**
     * At or above the hard limit.
     *
     * A certainty rather than a risk: whatever an earlier roll said, this much
     * heat destroys the module. Nothing destroys anything yet, because running
     * a deck is not built, so what this means today is "this loadout would".
     */
    case OverLimit = 'over_limit';

    public function isSafe(): bool
    {
        return $this === self::Safe;
    }

    /** The class the board's socket wears. Safe wears none. */
    public function slotClass(): string
    {
        return match ($this) {
            self::Safe => '',
            self::AtRisk => 'slot--hot',
            self::OverLimit => 'slot--hot slot--doomed',
        };
    }

    /**
     * The token a readout takes its colour from.
     *
     * Bone when nothing is suffering: the heat figure is not a warning in
     * itself, only in relation to what is standing in it.
     */
    public function inkToken(): string
    {
        return match ($this) {
            self::Safe => 'var(--ink-0)',
            self::AtRisk => 'var(--warn)',
            self::OverLimit => 'var(--crit)',
        };
    }

    public function label(): string
    {
        return Text::get("decks.heat.verdict.{$this->value}");
    }
}
