<?php

declare(strict_types=1);

namespace App\Standing;

use App\Models\Character;
use App\Models\Entity;
use App\Models\EntityBook;
use App\Models\EntityLedgerEntry;
use App\Support\Points;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `entity_books` and `entity_ledger`.
 *
 * ONE INVARIANT, AND EVERYTHING HERE EXISTS TO KEEP IT: the books are always
 * exactly the sum of the ledger, per kind. It survives clamping and the
 * nightly drift because every entry records WHAT LANDED — an act against an
 * entity already at the floor writes a delta of zero, which says honestly that
 * the act changed nothing rather than pretending it did.
 *
 * **Flavour entities keep no books at all.** `interactable` is the whole test,
 * and a write against scenery is a no-op rather than a refusal: the callers
 * are endings and sweeps, and a run against an unimportant company is an
 * ordinary thing to do, not an error to report.
 *
 * Standing is clamped into [-100, 100] and drifts toward zero; loyalty floors
 * at zero and never decays, because it is a currency and the sink is the
 * store. Both live on one row for one reason: they are two numbers about one
 * relationship, and a screen that draws the relationship wants one read.
 */
final class StandingLedger
{
    /**
     * Record something that happened, and return what it actually moved.
     *
     * Null means nothing was written: no entity, or scenery, or an amount that
     * rounded to nothing. Zero means an entry WAS written and it moved
     * nothing, which is a different fact and worth being able to read back.
     */
    public function record(
        Character $character,
        ?Entity $entity,
        StandingReason $reason,
        ?float $amount = null,
        LedgerKind $kind = LedgerKind::Standing,
    ): ?float {
        if ($entity === null || ! $entity->interactable) {
            return null;
        }

        $amount ??= $reason->amount();

        // Rounded to the column before anything else, so "what landed" and
        // what is stored cannot differ by a hundredth nobody can see.
        $amount = round($amount, 3);

        if ($amount === 0.0 && $reason !== StandingReason::Decay) {
            return null;
        }

        return DB::transaction(function () use ($character, $entity, $reason, $amount, $kind): float {
            $books = EntityBook::query()
                ->where('character_id', $character->getKey())
                ->where('entity_id', $entity->getKey())
                ->lockForUpdate()
                ->first()
                ?? new EntityBook([
                    'character_id' => $character->getKey(),
                    'entity_id' => $entity->getKey(),
                    'standing' => 0.0,
                    'loyalty' => 0,
                ]);

            $landed = $kind === LedgerKind::Standing
                ? $this->applyStanding($books, $amount)
                : $this->applyLoyalty($books, $amount);

            $books->save();

            if ($landed === 0.0 && $reason !== StandingReason::Decay) {
                // Still an entry: "this raid changed nothing" is a fact the
                // history should carry, and the sum stays right either way.
                $landed = 0.0;
            }

            EntityLedgerEntry::query()->create([
                'character_id' => $character->getKey(),
                'entity_id' => $entity->getKey(),
                'kind' => $kind,
                'delta' => $landed,
                'reason' => $reason,
                'happened_at' => now(),
            ]);

            return $landed;
        });
    }

    /** What this entity thinks of them right now. Absent reads as neutral. */
    public function standingFor(Character $character, Entity $entity): float
    {
        return (float) ($this->booksFor($character, $entity)->standing ?? 0.0);
    }

    /** What they are owed. Absent reads as broke. */
    public function loyaltyFor(Character $character, Entity $entity): int|float
    {
        return Points::of($this->booksFor($character, $entity)->loyalty ?? 0, 3);
    }

    /** The row, made rather than found when there is none. Never saved here. */
    public function booksFor(Character $character, Entity $entity): EntityBook
    {
        return EntityBook::query()->firstOrNew(
            ['character_id' => $character->getKey(), 'entity_id' => $entity->getKey()],
            ['standing' => 0.0, 'loyalty' => 0],
        );
    }

    /**
     * The nightly drift: a percentage of what is carried, toward zero, from
     * both directions.
     *
     * PROPORTIONAL AND NOT FLAT. At the size of a mission's reward a flat rate
     * would be backwards — half a point a night is twenty-five grade 1 jobs
     * and nothing at all to somebody sitting at sixty. A percentage costs
     * little at the bottom and real work at the top, can never overshoot zero,
     * and gives every rung of the ladder a soft ceiling for free: standing
     * settles where a day's earnings equal the drift.
     *
     * Every move writes an entry, or the invariant stops being checkable.
     *
     * @return int how many books moved
     */
    public function drift(): int
    {
        $rate = (float) Config::get('standing.drift.nightly', 0.0025);

        if ($rate <= 0.0) {
            return 0;
        }

        $moved = 0;

        EntityBook::query()
            ->where('standing', '!=', 0)
            ->with('character', 'entity')
            ->chunkById(200, function ($books) use ($rate, &$moved): void {
                foreach ($books as $row) {
                    $character = $row->character;
                    $entity = $row->entity;

                    if ($character === null || $entity === null) {
                        continue;
                    }

                    // Toward zero, and never past it.
                    $step = round(abs((float) $row->standing) * $rate, 3);

                    if ($step === 0.0) {
                        continue;
                    }

                    $delta = (float) $row->standing > 0 ? -$step : $step;

                    if ($this->record($character, $entity, StandingReason::Decay, $delta) !== null) {
                        $moved++;
                    }
                }
            });

        return $moved;
    }

    /**
     * Every pair whose books disagree with their ledger.
     *
     * Three lines because of how the entries are written, and the reason the
     * whole design can carry a running total beside a log without the two
     * becoming two answers.
     *
     * @return list<array{character_id: int, entity_id: int, kind: string, books: float, ledger: float}>
     */
    public function verify(): array
    {
        $sums = EntityLedgerEntry::query()
            ->selectRaw('character_id, entity_id, kind, SUM(delta) as total')
            ->groupBy('character_id', 'entity_id', 'kind')
            ->get()
            ->keyBy(fn (EntityLedgerEntry $row): string => "{$row->character_id}:{$row->entity_id}:{$row->kind->value}");

        $wrong = [];

        foreach (EntityBook::query()->cursor() as $books) {
            foreach (LedgerKind::cases() as $kind) {
                $held = $kind === LedgerKind::Standing ? (float) $books->standing : (float) $books->loyalty;
                $key = "{$books->character_id}:{$books->entity_id}:{$kind->value}";
                $summed = round((float) ($sums[$key]->total ?? 0.0), 3);

                if (abs($held - $summed) > 0.0005) {
                    $wrong[] = [
                        'character_id' => (int) $books->character_id,
                        'entity_id' => (int) $books->entity_id,
                        'kind' => $kind->value,
                        'books' => $held,
                        'ledger' => $summed,
                    ];
                }
            }
        }

        return $wrong;
    }

    /** Clamped into the range, and what lands is what is written down. */
    private function applyStanding(EntityBook $books, float $amount): float
    {
        $floor = (float) Config::get('standing.range.min', -100);
        $ceiling = (float) Config::get('standing.range.max', 100);

        $before = (float) $books->standing;
        $after = round(max($floor, min($ceiling, $before + $amount)), 3);

        $books->standing = $after;

        return round($after - $before, 3);
    }

    /** Floors at zero, has no ceiling, and is carried to three decimals. */
    private function applyLoyalty(EntityBook $books, float $amount): float
    {
        $before = round((float) $books->loyalty, 3);
        $after = round(max(0, $before + $amount), 3);

        $books->loyalty = $after;

        return round($after - $before, 3);
    }
}
