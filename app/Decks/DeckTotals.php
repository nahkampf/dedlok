<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\InventoryItem;
use App\Stats\Modifier;
use App\Stats\Resolver;
use Illuminate\Support\Collection;

/**
 * What a deck adds up to: what is fitted in it, summed.
 *
 * ONE function totals a deck's fitted modules, which MECHANICS asks for by
 * name — the stash renders this, and the equipped deck will render the same
 * number after it has been through the resolver. There is no path where a
 * total is computed twice by two different pieces of code, and this class
 * existing is what makes that true.
 *
 * `of()` gives BASES — the plain sum of what is fitted, which is what
 * MECHANICS says an unequipped deck shows. `resolvedWith()` is the other half:
 * every measure is the base of a resolved stat, so a skill or an implant that
 * moves one goes through App\Stats\Resolver in the ordinary layer order
 * rather than as a special case living in deck code. The sum is the resolver's
 * INPUT and never a second implementation of it.
 *
 * Which one a screen shows follows MECHANICS: the EQUIPPED deck resolves,
 * because modifiers belong to a character and only one deck is theirs at a
 * time; every other deck shows its bases, since nothing about a deck in the
 * stash depends on who owns it.
 *
 * Nothing here is enforced. A deck that draws more than its supplies deliver
 * is a deliberate design tension, not a row to correct, and the readiness
 * check reports it rather than refusing the fitting that caused it.
 */
final class DeckTotals
{
    /**
     * The stat id behind each measure.
     *
     * Ids are permanent — they end up in stored modifier definitions — so
     * these are the names a skill will target forever. Keyed by the property
     * they resolve, which is what keeps resolvedWith() one loop.
     *
     * @var array<string, string>
     */
    public const STATS = [
        'qflops' => 'deck_qflops',
        'quantabits' => 'deck_quantabits',
        'bandwidth' => 'deck_bandwidth',
        'draw' => 'deck_power_draw',
        'supply' => 'deck_power_supply',
        'thermalLoad' => 'deck_thermal_load',
        'cooling' => 'deck_cooling',
        'storage' => 'deck_storage',
    ];

    /**
     * @param  array<string, int>  $counts  fitted modules per Hardware value
     */
    private function __construct(
        public readonly int $qflops,
        public readonly int $quantabits,
        public readonly int $bandwidth,
        public readonly int $draw,
        public readonly int $supply,
        /** What the fitted modules MAKE, before anything takes it away. */
        public readonly int $thermalLoad,
        /** What the thermal modules take away. */
        public readonly int $cooling,
        /** Where files and unloaded programs live. Not memory — see Hardware. */
        public readonly int $storage,
        private readonly array $counts,
    ) {}

    /**
     * A deck's totals as they apply to the character holding it.
     *
     * THE ONE PLACE that rule lives. The equipped deck resolves and every
     * other deck shows its bases, and both the deck screen and the jack-in
     * gate have to agree about which — a readiness check that read bases
     * while the screen showed resolved numbers would refuse a deck the player
     * is looking at being told is fine.
     *
     * It is here rather than on the screen that first needed it because there
     * are two callers now. Readiness therefore reads the resolved numbers,
     * which is what makes a skill that cuts draw able to bring an
     * over-committed deck into spec — MECHANICS names that as how
     * over-commitment is meant to be solved.
     */
    public static function forCharacter(Character $character, InventoryItem $deck, Resolver $resolver): self
    {
        $totals = self::of($deck->fittings);

        return $character->hasEquipped($deck)
            ? $totals->resolvedWith($character->modifiers(), $resolver)
            : $totals;
    }

    /** @param  Collection<int, DeckFitting>  $fittings */
    public static function of(Collection $fittings): self
    {
        $sums = [
            'qflops' => 0, 'quantabits' => 0, 'bandwidth' => 0,
            'watts' => 0, 'power_draw' => 0, 'thermal_load' => 0, 'cooling' => 0,
            'storage' => 0,
        ];
        $counts = [];

        foreach ($fittings as $fitting) {
            $item = $fitting->module?->item;
            $module = $item?->deckModule;

            if ($item?->hardware === null || $module === null) {
                continue;
            }

            $counts[$item->hardware->value] = ($counts[$item->hardware->value] ?? 0) + 1;

            foreach (array_keys($sums) as $column) {
                $sums[$column] += (int) $module->{$column};
            }
        }

        return new self(
            qflops: $sums['qflops'],
            quantabits: $sums['quantabits'],
            bandwidth: $sums['bandwidth'],
            draw: $sums['power_draw'],
            // A supply gives power and draws none; chk_deck_modules_supply is
            // what makes that true of every row rather than most of them.
            supply: $sums['watts'],
            thermalLoad: $sums['thermal_load'],
            // And the mirror: a thermal module cools and heats none.
            cooling: $sums['cooling'],
            storage: $sums['storage'],
            counts: $counts,
        );
    }

    /**
     * The same deck, with the character's modifiers applied.
     *
     * Takes the modifiers rather than the character, so this class stays pure
     * and a test can hand it two of them without a fixture. The counts are
     * carried through untouched: how many sticks are in a deck is a fact about
     * the deck and not a number anything modifies.
     *
     * @param  iterable<Modifier>  $modifiers
     */
    public function resolvedWith(iterable $modifiers, Resolver $resolver): self
    {
        // Materialised once: resolve() iterates, and a generator would be
        // spent after the first measure.
        $modifiers = is_array($modifiers) ? $modifiers : iterator_to_array($modifiers);

        $resolved = [];

        foreach (self::STATS as $measure => $statId) {
            $resolved[$measure] = (int) $resolver->resolve($statId, (float) $this->{$measure}, $modifiers)->value;
        }

        return new self(
            qflops: $resolved['qflops'],
            quantabits: $resolved['quantabits'],
            bandwidth: $resolved['bandwidth'],
            draw: $resolved['draw'],
            supply: $resolved['supply'],
            thermalLoad: $resolved['thermalLoad'],
            cooling: $resolved['cooling'],
            storage: $resolved['storage'],
            counts: $this->counts,
        );
    }

    /** How many modules of a kind are fitted. */
    public function countOf(Hardware $hardware): int
    {
        return $this->counts[$hardware->value] ?? 0;
    }

    /** Over-committed. True is allowed, and says so rather than refusing. */
    public function isOver(): bool
    {
        return $this->draw > $this->supply;
    }

    /**
     * What a deck runs at per turn: what it makes, less what is bled off.
     *
     * Floored at zero. A deck cooled harder than it heats is a cold deck, not
     * a deck with negative load — there is nothing for the surplus to do.
     *
     * This is a RATE, in thermal units per turn. What the thresholds test is
     * the accumulated total over a run, which the run simulator owns; a deck
     * that is not running makes nothing at all.
     *
     * There is nothing to compare this against here, and that is deliberate: a
     * deck has no thermal rating and thermal load does not happen to it. What
     * number means is decided per module, against thresholds each one carries
     * — see App\Decks\ThermalExposure.
     */
    public function netThermalLoad(): int
    {
        return max(0, $this->thermalLoad - $this->cooling);
    }

    /** How full the power budget is, 0-1, for a meter's --v. */
    public function ratio(): float
    {
        if ($this->supply <= 0) {
            return $this->draw > 0 ? 1.0 : 0.0;
        }

        return round(min(1.0, $this->draw / $this->supply), 3);
    }

    /**
     * What this deck still needs before it will run.
     *
     * @return list<DeckRequirement>
     */
    public function unmet(): array
    {
        return DeckRequirement::unmetBy($this);
    }

    public function isReady(): bool
    {
        return $this->unmet() === [];
    }
}
