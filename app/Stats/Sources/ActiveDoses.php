<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Characters\EncounterPause;
use App\Models\Character;
use App\Models\Dose;
use App\Models\Fight;
use App\Stats\Modifier;

/**
 * What somebody has in them right now.
 *
 * THE SIXTH MODIFIER SOURCE, and the resolver learned nothing new for it — a
 * dose is a list of modifiers with a clock on it, which is what every other
 * source already hands over.
 *
 * It reads the effects off the ITEM rather than off the dose, like installed
 * implants and running programs do, so retuning a drug retunes what is already
 * in everybody. What the dose carries is its POTENCY: tolerance is applied
 * when it is taken and frozen there, because what somebody swallowed is what
 * they swallowed and a band that climbs an hour later does not reach back into
 * their blood.
 *
 * ONLY THE POSITIVE AMOUNTS ARE SCALED. Tolerance means a drug does less FOR
 * you, never that it does less TO you — softening the cost as well would make
 * tolerance a pure good and the whole mechanic a reward for using.
 *
 * TWO DOSES ARE TWO ROWS AND THEY ADD UP, which is `Stacking::Sum` by
 * construction rather than by a rule anybody wrote: each row is its own source
 * entry, so nothing collapses them. That is the answer MECHANICS already
 * wanted for drugs.
 *
 * Expiry is FILTERED, never swept. An expired dose is simply not counted, and
 * the rows are tidied by the nightly metabolism for housekeeping rather than
 * for correctness.
 */
final readonly class ActiveDoses implements ModifierSource
{
    public function __construct(
        private Character $character,
        /** The moment to resolve as of; null is now. */
        private ?\DateTimeInterface $asAt = null,
    ) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        $at = $this->asAt ?? now();

        // In a fight the clock stops: an ordinary dose is judged as at the moment the fight opened, so waiting out a
        // drug's cost (or letting its benefit lapse) cannot be done by leaving a fight open. `Fights` pushes the expiry
        // forward by the fight's length when it ends, so the pause is real afterwards too.
        $opened = $this->asAt === null && $this->character->current_fight_id !== null
            ? Fight::query()->find($this->character->current_fight_id)?->created_at
            : null;
        // And on the grid, while an encounter is open (EncounterPause).
        $opened ??= $this->asAt === null ? EncounterPause::openedAt($this->character) : null;
        $clock = $opened ?? $at;

        $doses = Dose::query()
            ->where('character_id', $this->character->getKey())
            ->where(static fn ($any) => $any
                // A fight-only dose counts down in rounds and is in effect while any are left.
                ->where(static fn ($fight) => $fight->where('turns_left', '>', 0)->where('expires_at', '>', $at)->where('started_at', '<=', $at))
                // A dose taken after the moment being asked about was not in them then. Unlike a running program, a
                // dose knows exactly when it began and when it ends, so looking back is honest here.
                ->orWhere(static fn ($timed) => $timed->whereNull('turns_left')->where('expires_at', '>', $clock)->where('started_at', '<=', $clock)))
            ->with('item')
            ->get();

        $modifiers = [];

        foreach ($doses as $dose) {
            $item = $dose->item;

            if ($item === null || ! is_array($item->modifiers)) {
                continue;
            }

            foreach ($item->modifiers as $shape) {
                if (! is_array($shape)) {
                    continue;
                }

                $modifiers[] = Modifier::fromArray(
                    $this->scaled($shape, $dose->potency) + ['source' => $item->displayName()]
                );
            }
        }

        return $modifiers;
    }

    /**
     * The same modifier, worth what tolerance left of it.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private function scaled(array $shape, float $potency): array
    {
        $amount = $shape['amount'] ?? null;

        if (! is_numeric($amount) || $amount <= 0 || $potency >= 1.0) {
            return $shape;
        }

        $shape['amount'] = round((float) $amount * $potency, 2);

        return $shape;
    }
}
