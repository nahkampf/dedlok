<?php

declare(strict_types=1);

namespace App\City;

use App\Models\Character;
use App\Models\ClinicOffer;
use App\Models\Location;
use App\Stats\InjuryLedger;
use App\Support\Points;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Treatment, instant and priced per point.
 *
 * Restoring a resource fills it to its CURRENT resolved maximum, so what is
 * owed is `maximum - current`, worked out under the lock. Mending an injury
 * raises a maximum and does not touch the current value, which is why it is a
 * separate treatment and why the screen says which to buy first. Hitpoints and
 * Synaptic Integrity go through `Character::recover()` and an injury through
 * `InjuryLedger::heal()`; neither can overshoot.
 *
 * `quote()` and `treat()` share `owed()`, so what the screen shows and what is
 * charged cannot disagree. Recovery and the wallet move are done on the same
 * locked instance: the `saving` hook re-clamps resources, and a second, stale
 * copy of the character would write an old value back.
 */
final class Clinic
{
    /**
     * What each treatment on offer would cost this character right now.
     *
     * @return list<array{service: ClinicService, points: int, per_point: int, total: int}>
     */
    public function quote(Character $character, Location $place): array
    {
        $rows = [];

        foreach (ClinicOffer::query()->where('location_id', $place->getKey())->orderBy('id')->get() as $offer) {
            $points = $this->owed($character, $offer->service);
            $rows[] = ['service' => $offer->service, 'points' => $points, 'per_point' => $offer->price, 'total' => (int) ceil($points * $offer->price)];
        }

        return $rows;
    }

    /** @return int|CityRefusal creds spent */
    public function treat(Character $character, Location $place, ClinicService $service): int|CityRefusal
    {
        return DB::transaction(function () use ($character, $place, $service): int|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            $offer = ClinicOffer::query()->where('location_id', $place->getKey())->where('service', $service->value)->first();

            if ($offer === null) {
                return CityRefusal::NotSold;
            }

            $points = $this->owed($locked, $service);

            if ($points <= 0) {
                return CityRefusal::NothingToTreat;
            }

            // A fraction of a point is billed whole, rounded up: creds are whole.
            $total = (int) ceil($points * $offer->price);

            if ((int) $locked->creds < $total) {
                return CityRefusal::CannotAfford;
            }

            Wallet::move($locked, -$total, Reason::Clinic, Text::get('city.clinic.note', ['service' => $service->label(), 'place' => $place->name]));

            match ($service) {
                ClinicService::Hitpoints => $locked->recover('current_hitpoints', $points),
                ClinicService::SynapticIntegrity => $locked->recover('current_synaptic_integrity', $points),
                ClinicService::NeuralDamage => (new InjuryLedger)->heal($locked, InjuryLedger::NEURAL, $points),
                ClinicService::PhysicalTrauma => (new InjuryLedger)->heal($locked, InjuryLedger::TRAUMA, $points),
            };

            $character->refresh();

            return $total;
        });
    }

    /** Points of this treatment the character is owed. THE one place it is worked out. */
    private function owed(Character $character, ClinicService $service): int|float
    {
        return match ($service) {
            ClinicService::Hitpoints => Points::of(max(0, $character->maximumFor('current_hitpoints') - $character->current_hitpoints)),
            ClinicService::SynapticIntegrity => Points::of(max(0, $character->maximumFor('current_synaptic_integrity') - $character->current_synaptic_integrity)),
            ClinicService::NeuralDamage => (int) ceil(abs((new InjuryLedger)->carried($character, InjuryLedger::NEURAL))),
            ClinicService::PhysicalTrauma => (int) ceil(abs((new InjuryLedger)->carried($character, InjuryLedger::TRAUMA))),
        };
    }
}
