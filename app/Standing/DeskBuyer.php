<?php

declare(strict_types=1);

namespace App\Standing;

use App\City\CityRefusal;
use App\Models\Character;
use App\Models\EntityBuy;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Support\Text;
use App\Trade\Buying;
use Illuminate\Support\Facades\DB;

/**
 * What an exchange desk buys from the person standing at it.
 *
 * The same rules as a City place (`App\Trade\Buying`), with the desk in place of `Presence`: the caller has already
 * established that the character is standing on THIS desk, and the desk's own welcome gate is asked here, since an
 * agent who will not deal with somebody does not buy from them either. The listings are the entity's, for the
 * range of desk grades that contains this desk.
 */
final class DeskBuyer
{
    /**
     * @return list<array{row: InventoryItem, unit: int, left: int|null}>
     */
    public function sellable(Character $character, Exchange $desk, float $standing): array
    {
        $entity = $desk->entity();

        if ($entity === null || ! $desk->willDealWith($standing)) {
            return [];
        }

        return Buying::sellable($character, EntityBuy::query()->with('item')->where('entity_id', $entity->getKey())->atGrade((int) $desk->grade)->get());
    }

    /** @return int|CityRefusal creds received */
    public function sell(Character $character, Exchange $desk, int $rowId, int $quantity): int|CityRefusal
    {
        return DB::transaction(function () use ($character, $desk, $rowId, $quantity): int|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $entity = $desk->entity();

            if ($entity === null || ! $desk->willDealWith((new StandingLedger)->standingFor($locked, $entity))) {
                return CityRefusal::CannotSell;
            }

            $row = InventoryItem::query()->with('item')->where('character_id', $locked->getKey())->whereKey($rowId)->first();
            $buy = $row === null ? null : EntityBuy::query()->where('entity_id', $entity->getKey())->atGrade((int) $desk->grade)->where('item_id', $row->item_id)->first();

            if ($buy === null) {
                return CityRefusal::CannotSell;
            }

            $result = Buying::sell($locked, $buy, $rowId, $quantity, Text::get('exchange.sale_note', [
                'item' => $row->item->displayName(),
                'quantity' => $quantity,
                'place' => $entity->name,
            ]));

            $character->refresh();

            return $result;
        });
    }
}
