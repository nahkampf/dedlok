<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\DeckFitting;
use App\Stats\Modifier;
use App\Stats\Resolver;
use Illuminate\Support\Collection;

/**
 * What the deck's thermal load is doing to each thing fitted in it.
 *
 * One verdict per occupied socket, from two numbers on the module compared
 * against one number for the deck. Kept apart from App\Decks\DeckTotals
 * because they answer different questions: totals are about the deck as a
 * whole and sum, while these are per module and do not — a deck's tolerance is
 * its weakest part, not the sum of its parts.
 *
 * The thresholds are RESOLVED, like every other number a module carries. A
 * perk that makes chrome run cooler moves them through the ordinary layer
 * order rather than as a special case living here.
 */
final class ThermalExposure
{
    public const STAT_WARNING = 'deck_module_thermal_warning';

    public const STAT_LIMIT = 'deck_module_thermal_limit';

    /**
     * @param  array<int, ThermalVerdict>  $verdicts  keyed by deck_slot_id
     * @param  array<string, list<string>>  $names  module names per verdict value
     */
    private function __construct(
        private readonly array $verdicts,
        private readonly array $names,
    ) {}

    /**
     * @param  int  $netThermalLoad  what the deck actually runs at, after cooling
     * @param  Collection<int, DeckFitting>  $fittings
     * @param  iterable<Modifier>  $modifiers
     */
    public static function of(
        int $netThermalLoad,
        Collection $fittings,
        iterable $modifiers,
        Resolver $resolver,
    ): self {
        $modifiers = is_array($modifiers) ? $modifiers : iterator_to_array($modifiers);

        $verdicts = [];
        $names = [];

        foreach ($fittings as $fitting) {
            $item = $fitting->module?->item;
            $module = $item?->deckModule;

            if ($module === null) {
                continue;
            }

            $verdict = self::verdictFor($netThermalLoad, $module->thermal_warning, $module->thermal_limit, $modifiers, $resolver);

            $verdicts[(int) $fitting->deck_slot_id] = $verdict;

            if (! $verdict->isSafe()) {
                $names[$verdict->value][] = $item->displayName();
            }
        }

        return new self($verdicts, $names);
    }

    /**
     * The verdict for one module, given the two thresholds it carries.
     *
     * Both null is a module with no known limit, which is not the same as a
     * module with a limit of zero — the schema keeps the pair together for
     * exactly this reason.
     *
     * @param  list<Modifier>  $modifiers
     */
    private static function verdictFor(
        int $netThermalLoad,
        ?int $warning,
        ?int $limit,
        array $modifiers,
        Resolver $resolver,
    ): ThermalVerdict {
        if ($warning === null || $limit === null) {
            return ThermalVerdict::Safe;
        }

        $resolvedLimit = (int) $resolver->resolve(self::STAT_LIMIT, (float) $limit, $modifiers)->value;

        // The limit first: it is a certainty and the warning is only a
        // threshold, so a module past both is past the one that matters.
        if ($netThermalLoad >= $resolvedLimit) {
            return ThermalVerdict::OverLimit;
        }

        $resolvedWarning = (int) $resolver->resolve(self::STAT_WARNING, (float) $warning, $modifiers)->value;

        return $netThermalLoad >= $resolvedWarning ? ThermalVerdict::AtRisk : ThermalVerdict::Safe;
    }

    /** What the socket at this slot is faring. Empty sockets are Safe. */
    public function verdict(?int $slotId): ThermalVerdict
    {
        return $this->verdicts[$slotId] ?? ThermalVerdict::Safe;
    }

    /** @return list<string> */
    public function atRisk(): array
    {
        return $this->names[ThermalVerdict::AtRisk->value] ?? [];
    }

    /** @return list<string> */
    public function overLimit(): array
    {
        return $this->names[ThermalVerdict::OverLimit->value] ?? [];
    }

    /**
     * The worst any one module is faring.
     *
     * What the deck's thermal load MEANS, now that there is no chassis rating
     * to mean anything against: a number is only hot relative to the parts
     * standing in it.
     */
    public function worst(): ThermalVerdict
    {
        if ($this->overLimit() !== []) {
            return ThermalVerdict::OverLimit;
        }

        return $this->atRisk() === [] ? ThermalVerdict::Safe : ThermalVerdict::AtRisk;
    }

    /** Nothing in this deck is suffering. */
    public function isSafe(): bool
    {
        return $this->names === [];
    }
}
