<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\DeckFitting;
use App\Stats\Modifier;
use App\Stats\Resolver;
use Illuminate\Support\Collection;

/**
 * What the deck's heat is doing to each thing fitted in it.
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
final class HeatExposure
{
    public const STAT_WARNING = 'deck_module_heat_warning';

    public const STAT_LIMIT = 'deck_module_heat_limit';

    /**
     * @param  array<int, HeatVerdict>  $verdicts  keyed by deck_slot_id
     * @param  array<string, list<string>>  $names  module names per verdict value
     */
    private function __construct(
        private readonly array $verdicts,
        private readonly array $names,
    ) {}

    /**
     * @param  int  $netHeat  what the deck actually runs at, after cooling
     * @param  Collection<int, DeckFitting>  $fittings
     * @param  iterable<Modifier>  $modifiers
     */
    public static function of(
        int $netHeat,
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

            $verdict = self::verdictFor($netHeat, $module->heat_level_warning, $module->heat_level_limit, $modifiers, $resolver);

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
        int $netHeat,
        ?int $warning,
        ?int $limit,
        array $modifiers,
        Resolver $resolver,
    ): HeatVerdict {
        if ($warning === null || $limit === null) {
            return HeatVerdict::Safe;
        }

        $resolvedLimit = (int) $resolver->resolve(self::STAT_LIMIT, (float) $limit, $modifiers)->value;

        // The limit first: it is a certainty and the warning is only a
        // threshold, so a module past both is past the one that matters.
        if ($netHeat >= $resolvedLimit) {
            return HeatVerdict::OverLimit;
        }

        $resolvedWarning = (int) $resolver->resolve(self::STAT_WARNING, (float) $warning, $modifiers)->value;

        return $netHeat >= $resolvedWarning ? HeatVerdict::AtRisk : HeatVerdict::Safe;
    }

    /** What the socket at this slot is faring. Empty sockets are Safe. */
    public function verdict(?int $slotId): HeatVerdict
    {
        return $this->verdicts[$slotId] ?? HeatVerdict::Safe;
    }

    /** @return list<string> */
    public function atRisk(): array
    {
        return $this->names[HeatVerdict::AtRisk->value] ?? [];
    }

    /** @return list<string> */
    public function overLimit(): array
    {
        return $this->names[HeatVerdict::OverLimit->value] ?? [];
    }

    /**
     * The worst any one module is faring.
     *
     * What the deck's heat MEANS, now that there is no chassis rating for it
     * to mean anything against: a number is only hot relative to the parts
     * standing in it.
     */
    public function worst(): HeatVerdict
    {
        if ($this->overLimit() !== []) {
            return HeatVerdict::OverLimit;
        }

        return $this->atRisk() === [] ? HeatVerdict::Safe : HeatVerdict::AtRisk;
    }

    /** Nothing in this deck is suffering. */
    public function isSafe(): bool
    {
        return $this->names === [];
    }
}
