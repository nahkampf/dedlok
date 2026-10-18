<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Character;
use App\Models\Location;
use App\Models\LocationMeal;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * The only door from a location's menu to a stomach. One transaction on the locked character: the same `Presence`
 * every City service asks, enough creds, room to eat (`Meal`), then the money moves and the meal is eaten.
 */
final class Kitchen
{
    public function eat(Character $character, Location $place, int $mealId): true|CityRefusal
    {
        return DB::transaction(function () use ($character, $place, $mealId): true|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            $dish = LocationMeal::query()->where('location_id', $place->getKey())->find($mealId);

            if ($dish === null) {
                return CityRefusal::NotSold;
            }

            if ($dish->meal()->refusedFor($locked)) {
                return CityRefusal::TooFull;
            }

            if ((int) $locked->creds < $dish->price) {
                return CityRefusal::CannotAfford;
            }

            Wallet::move($locked, -$dish->price, Reason::Meal, Text::get('wallet.notes.meal', ['name' => $dish->name, 'place' => $place->name]));
            $dish->meal()->apply($locked);
            $character->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
