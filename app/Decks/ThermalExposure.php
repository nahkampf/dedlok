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
     * @param  list<array{name: string, warning: int, limit: int}>  $thresholds  resolved, per module that carries any
     */
    private function __construct(
        private readonly array $verdicts,
        private readonly array $names,
        private readonly array $thresholds,
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
        $thresholds = [];

        foreach ($fittings as $fitting) {
            $item = $fitting->module?->item;
            $module = $item?->deckModule;

            if ($module === null) {
                continue;
            }

            // Both null is a module with no known limit, which is not the same
            // as a module with a limit of zero — the schema keeps the pair
            // together for exactly this reason.
            if ($module->thermal_warning === null || $module->thermal_limit === null) {
                $verdicts[(int) $fitting->deck_slot_id] = ThermalVerdict::Safe;

                continue;
            }

            $warning = (int) $resolver->resolve(self::STAT_WARNING, (float) $module->thermal_warning, $modifiers)->value;
            $limit = (int) $resolver->resolve(self::STAT_LIMIT, (float) $module->thermal_limit, $modifiers)->value;
            $thresholds[] = ['name' => $item->displayName(), 'warning' => $warning, 'limit' => $limit];

            // The limit first: it is a certainty and the warning is only a
            // threshold, so a module past both is past the one that matters.
            $verdict = match (true) {
                $netThermalLoad >= $limit => ThermalVerdict::OverLimit,
                $netThermalLoad >= $warning => ThermalVerdict::AtRisk,
                default => ThermalVerdict::Safe,
            };

            $verdicts[(int) $fitting->deck_slot_id] = $verdict;

            if (! $verdict->isSafe()) {
                $names[$verdict->value][] = $item->displayName();
            }
        }

        return new self($verdicts, $names, $thresholds);
    }

    /**
     * Every fitted module's two thresholds, resolved — what the thermal meter
     * marks. Modules with no known limit carry none and are left out.
     *
     * @return list<array{name: string, warning: int, limit: int}>
     */
    public function thresholds(): array
    {
        return $this->thresholds;
    }

    /**
     * Where the thermal meter ends: the highest limit anything fitted has, so
     * the last module to break is the right-hand edge. Not a rating — a deck
     * has none — only the scale the marks are drawn against. Null when no
     * module carries a limit, and then there is nothing to draw a meter for.
     */
    public function scale(): ?int
    {
        return $this->thresholds === [] ? null : max(1, ...array_column($this->thresholds, 'limit'));
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
