<?php

declare(strict_types=1);

namespace App\Items;

use App\Characters\Meal;
use App\Models\Character;
use App\Models\Consumable;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Uses one copy of a consumable. The only thing that applies a `consumables` row to a body.
 *
 * ONE TRANSACTION, the character locked: the copy is spent and the effects land together or not at all, and two
 * presses cannot both heal from the last medkit. A use that would change nothing is REFUSED and the copy kept —
 * `LoyaltyExchange` makes the same call about a service that would do nothing — so a medkit is never thrown away on
 * somebody at full health.
 *
 * Healing goes through `Character::recover()`, which is capped at the maximum as it stands: an injury that lowered
 * the maximum is not healed past by a medkit, and mending that is a clinic's job.
 */
final class Consumer
{
    /** Why using this on this body would be refused for what it does (not for who owns it), or null. Asked by `use()` and, before a round is spent, by a fight. */
    public function refusalFor(Character $body, Consumable $consumable, int $bleeding = 0): ?UseRefusal
    {
        $effects = $consumable->kinds();

        // Food cannot be forced down: a full stomach refuses it, whatever else it would do.
        foreach ($effects as [$kind, $amount]) {
            if ($kind === UseEffect::Fullness && (new Meal(fullness: $amount))->refusedFor($body)) {
                return UseRefusal::TooFull;
            }
        }

        foreach ($effects as [$kind, $amount]) {
            if ($kind->would($body, $amount, $bleeding) > 0) {
                return null;
            }
        }

        return UseRefusal::NothingToDo;
    }

    /**
     * `$inFight` is `Fights`' to pass and nobody else's: a consumable the author allowed in a fight is used from the belt
     * as that round's action, and the fight (not this class) has rolled the check that got it out.
     *
     * @return array<string, int>|UseRefusal what each effect changed, by its kind's value
     */
    public function use(Character $character, InventoryItem $row, bool $inFight = false, int $bleeding = 0): array|UseRefusal
    {
        if ($row->character_id !== $character->id) {
            return UseRefusal::NotOwned;
        }

        $consumable = $row->item?->consumable;

        // A drug that also restores something is TAKEN (`DrugTaker`), which does both halves in one go; using it here
        // would apply the instant half and skip the dose.
        if ($consumable === null || $row->item->drug !== null) {
            return UseRefusal::NotUsable;
        }

        if (! InventoryItem::query()->loose()->whereKey($row->getKey())->exists()) {
            return UseRefusal::NotFree;
        }

        return DB::transaction(function () use ($character, $row, $consumable, $inFight, $bleeding): array|UseRefusal {
            $body = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($body->current_fight_id !== null && ! ($inFight && $consumable->usable_in_fight)) {
                return UseRefusal::InAFight;
            }

            if ($refusal = $this->refusalFor($body, $consumable, $bleeding)) {
                return $refusal;
            }

            $effects = $consumable->kinds();

            // Spend one copy: the pile shrinks, and the last one takes its row with it.
            $copy = InventoryItem::query()->whereKey($row->getKey())->lockForUpdate()->first();

            if ($copy === null || $copy->quantity < 1) {
                return UseRefusal::NotUsable;
            }

            if ($copy->quantity > 1) {
                $copy->quantity -= 1;
                $copy->save();
            } else {
                $copy->delete();
            }

            $done = [];

            foreach ($effects as [$kind, $amount]) {
                $done[$kind->value] = ($done[$kind->value] ?? 0) + $kind->apply($body, $amount);
            }

            // The caller holds a model of this character: hand back what changed on the locked one.
            $character->setRawAttributes($body->getAttributes(), true);

            return $done;
        });
    }
}
