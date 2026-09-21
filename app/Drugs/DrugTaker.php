<?php

declare(strict_types=1);

namespace App\Drugs;

use App\Models\Character;
use App\Models\Dose;
use App\Models\InventoryItem;
use App\Stats\InjuryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Taking one. The only door between an item and somebody's blood.
 *
 * ## One dose, one row, and the potency frozen onto it
 *
 * Tolerance is read HERE and written onto the dose, which is the whole reason
 * this mechanic needs nothing from `App\Stats\Resolver`. A dose is worth what
 * it was worth when it was swallowed; a band that climbs an hour later does
 * not reach back into somebody's blood, and the row stays honest about what it
 * is doing.
 *
 * ## Everything in one transaction
 *
 * The copy is spent, the dose begins, the books move and the injury lands
 * together or not at all. A half-resolved dose would be a drug somebody paid
 * for and did not take, or worse, one they took twice.
 *
 * ## Taking one clears the withdrawal and does not cure it
 *
 * The row goes for as long as something of that family is in them. The
 * addiction figure is untouched — feeding a need is not curing it, and only
 * time brings the number down.
 *
 * ## Overdosing is allowed, and it is deterministic
 *
 * A body holds so many of one family at once, and tolerance widens that. Going
 * past it is not refused: it is the mechanic. What it costs is nerve tissue,
 * through the same `neural_damage` injury a freeze and an ICE flatline deepen,
 * so a clinic heals one number rather than three. Nothing rolls, because a
 * drug is a known quantity somebody puts into their own body on purpose — the
 * third one hurting when you knew two was the limit is a mechanic, while the
 * third one hurting sometimes is a slot machine.
 */
final class DrugTaker
{
    public function __construct(
        private readonly DrugLedger $ledger = new DrugLedger,
        private readonly InjuryLedger $injuries = new InjuryLedger,
    ) {}

    public function take(Character $character, InventoryItem $row): DoseResult
    {
        $item = $row->item;
        $drug = $item?->drug;

        if ($drug === null) {
            return DoseResult::refused(DoseRefusal::NotADrug);
        }

        if ((int) $row->character_id !== (int) $character->getKey() || (int) $row->quantity < 1) {
            return DoseResult::refused(DoseRefusal::NotOwned);
        }

        return DB::transaction(function () use ($character, $row, $item, $drug): DoseResult {
            $now = CarbonImmutable::now();

            // What tolerance has left of it, decided before the books move:
            // the dose being taken is not made weaker by itself.
            $potency = $this->ledger->potencyFor($character, $drug->family);

            // And how far past the body's limit this one is, counted the same
            // way — before this dose is written, because capacity is about
            // what was already in them.
            $overdose = $this->overdoseFor($character, $drug->family);

            $this->spend($row);

            $dose = Dose::query()->create([
                'character_id' => $character->getKey(),
                'item_id' => $item->getKey(),
                'potency' => $potency,
                'started_at' => $now,
                'expires_at' => $now->addMinutes(max(1, (int) $drug->duration_minutes)),
            ]);

            $this->ledger->record($character, $drug);

            if ($overdose > 0.0) {
                $this->injuries->inflict($character, 'neural_damage', $overdose);
            }

            // There is something of the family in them now, so whatever they
            // were carrying for going without has stopped being true.
            $this->ledger->reconcile($character->fresh() ?? $character, $drug->family);

            return DoseResult::taken($dose, $potency, $overdose);
        });
    }

    /**
     * What this dose costs for being one too many, in points of nerve tissue.
     *
     * Zero is the ordinary answer. The count is what is ALREADY working: the
     * dose about to be taken is the one that breaks the capacity, not one of
     * the things filling it.
     */
    public function overdoseFor(Character $character, Family $family): float
    {
        $active = Dose::query()
            ->where('character_id', $character->getKey())
            ->inEffect()
            ->whereHas('item.drug', static fn ($query) => $query->where('family', $family->value))
            ->count();

        $excess = ($active + 1) - $this->ledger->capacityFor($character, $family);

        return $excess > 0
            ? round($excess * (float) Config::get('drugs.overdose.damage_per_excess', 2.0), 2)
            : 0.0;
    }

    /**
     * One copy off the pile.
     *
     * The last one takes the row with it rather than leaving a zero, which is
     * the same call every other thing that spends an item makes.
     */
    private function spend(InventoryItem $row): void
    {
        if ((int) $row->quantity > 1) {
            $row->forceFill(['quantity' => (int) $row->quantity - 1])->save();

            return;
        }

        $row->delete();
    }
}
