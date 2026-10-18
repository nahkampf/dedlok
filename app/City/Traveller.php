<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\Fatigue;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Stats\Resolver;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `characters.district_id` after creation, and of a travel's
 * `city_activities` row. See CITY.md.
 *
 * A travel is one pass in every district on the route EXCEPT the destination,
 * the origin included, and then arrival. `district_id` is the district whose
 * pass is running, so it is never null. Cancelling waits for the pass in
 * progress and leaves the character in the district it was in.
 *
 * SETTLING CATCHES UP: each pass begins when the previous one matured, so one
 * call walks every pass that has run. It is done under a lock on the activity
 * row, re-read inside it, because the five-minute sweep and a player's own
 * request can settle the same trip at once and a refund is real money.
 */
final class Traveller
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly Encounters $encounters,
        private readonly Fatigue $fatigue = new Fatigue,
    ) {}

    /**
     * What a trip to this district would be, or why it cannot start.
     *
     * `begin()` calls this, so the screen's quote and what is charged cannot
     * disagree.
     *
     * @return array{route: list<int>, walk: int, pod: int, fare: int}|CityRefusal seconds in total
     */
    public function quote(Character $character, District $to): array|CityRefusal
    {
        if ($character->isJackedIn()) {
            return CityRefusal::JackedIn;
        }

        if ($character->current_fight_id !== null) {
            return CityRefusal::Fighting;
        }

        if (CityActivity::query()->whereKey($character->getKey())->exists()) {
            return CityRefusal::Busy;
        }

        if ($character->district_id === $to->getKey()) {
            return CityRefusal::AlreadyThere;
        }

        $weights = District::query()->pluck('travel_weight', 'id')->map(fn ($w): float => (float) $w)->all();
        $route = Router::route(District::links(), $weights, (int) $character->district_id, (int) $to->getKey());

        if ($route === null) {
            return CityRefusal::NoRoute;
        }

        $walk = 0;
        $pod = 0;

        foreach (array_slice($route, 0, -1) as $districtId) {
            $seconds = $this->passSeconds($character, $districtId, TravelMode::Walk);
            $walk += $seconds;
            $pod += $this->passSeconds($character, $districtId, TravelMode::Pod);
        }

        return [
            'route' => $route,
            'walk' => $walk,
            'pod' => $pod,
            'fare' => (int) Config::get('city.travel.pod_fare', 25) * count($route),
        ];
    }

    public function begin(Character $character, District $to, TravelMode $mode): CityActivity|CityRefusal
    {
        return DB::transaction(function () use ($character, $to, $mode): CityActivity|CityRefusal {
            // The character row is the lock: two requests cannot both begin.
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            $quote = $this->quote($locked, $to);

            if ($quote instanceof CityRefusal) {
                return $quote;
            }

            $paid = 0;

            if ($mode === TravelMode::Pod) {
                if ((int) $locked->creds < $quote['fare']) {
                    return CityRefusal::CannotAfford;
                }

                Wallet::move($locked, -$quote['fare'], Reason::Pod, Text::get('wallet.notes.pod', ['district' => $to->name]));
                $paid = $quote['fare'];
            }

            $activity = CityActivity::query()->create([
                'character_id' => $locked->getKey(),
                'kind' => ActivityKind::Travel,
                'mode' => $mode,
                'route' => $quote['route'],
                'step' => 0,
                'paid' => $paid,
                'cancelling' => false,
                'pass_ends_at' => CarbonImmutable::now()->addSeconds($this->passSeconds($locked, $quote['route'][0], $mode)),
            ]);

            $character->refresh();

            return $activity;
        });
    }

    /** Ask to stop. Takes effect when the pass in progress matures. */
    public function cancel(Character $character): true|CityRefusal
    {
        $this->settle($character);

        return DB::transaction(function () use ($character): true|CityRefusal {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->where('kind', ActivityKind::Travel->value)->lockForUpdate()->first();

            if ($activity === null) {
                return CityRefusal::NothingToCancel;
            }

            $activity->forceFill(['cancelling' => true])->save();

            return true;
        });
    }

    /**
     * Walk every pass that has matured.
     *
     * @return list<array{0: string, 1: array<string, string>}> message key and replacements
     */
    public function settle(Character $character, ?CarbonInterface $now = null, bool $notify = false): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $messages = [];

        DB::transaction(function () use ($character, $now, &$messages): void {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->where('kind', ActivityKind::Travel->value)->lockForUpdate()->first();

            if ($activity === null) {
                return;
            }

            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $route = array_values((array) $activity->route);
            $count = count($route);

            while ($activity->pass_ends_at <= $now) {
                $step = $activity->step;
                $next = $route[$step + 1] ?? null;

                // A matured pass tires; in a pod it eases a little instead.
                $this->fatigue->charge($locked, ($activity->mode ?? TravelMode::Walk) === TravelMode::Pod ? 'travel_pod' : 'travel_pass');
                // A district an editor has since removed ends the trip where it stands.
                $reachable = $next !== null && District::query()->whereKey($next)->exists();

                if ($activity->cancelling || ! $reachable) {
                    $messages[] = $this->stop($activity, $locked, $route, $step, $count);

                    break;
                }

                $locked->forceFill(['district_id' => $next])->save();

                $name = District::query()->find($next)?->name ?? '';
                // A pass that reaches a district may open an encounter in it. The
                // locked character is the one it opens on.
                $opened = $this->encounters->maybeOpen($locked, EncounterStart::Travel) !== null;

                if ($next === $route[$count - 1]) {
                    $activity->delete();
                    $messages[] = ['travel_arrived', ['district' => $name]];
                    $opened && $messages[] = ['encounter_waiting', ['district' => $name]];

                    break;
                }

                // An encounter stops the trip where it is, refunding what a pod did
                // not carry them through. `step + 1`: the character is already in $next.
                if ($opened) {
                    $messages[] = $this->stop($activity, $locked, $route, $step + 1, $count);
                    $messages[] = ['encounter_waiting', ['district' => $name]];

                    break;
                }

                $activity->forceFill([
                    'step' => $step + 1,
                    // Each pass begins when the previous one matured, not now.
                    'pass_ends_at' => $activity->pass_ends_at->addSeconds($this->passSeconds($locked, $next, $activity->mode ?? TravelMode::Walk)),
                ])->save();
            }
        });

        if ($notify) {
            foreach ($messages as [$key, $replace]) {
                (new Notifier)->send($character, Category::City, $key, $replace);
            }
        }

        return $messages;
    }

    /**
     * End a trip where the character stands, refunding what a pod did not carry them through.
     *
     * @param  list<int>  $route
     * @return array{0: string, 1: array<string, string>}
     */
    private function stop(CityActivity $activity, Character $locked, array $route, int $step, int $count): array
    {
        $refund = $activity->paid > 0 ? intdiv($activity->paid, $count) * ($count - 1 - $step) : 0;

        if ($refund > 0) {
            Wallet::move($locked, $refund, Reason::Pod, Text::get('wallet.notes.pod_refund'));
        }

        $activity->delete();

        return ['travel_stopped', ['district' => District::query()->find($route[$step])?->name ?? '']];
    }

    /**
     * Put somebody in a district for free: no fare, no pass, no encounter roll. For a story that moves a body (a
     * collapse waking them at home). Whatever city activity they were in simply ends; nothing is refunded.
     *
     * A plain write by design: it knows nothing about WHY, and the caller owns the transaction.
     */
    public function relocate(Character $character, District $to): void
    {
        CityActivity::query()->whereKey($character->getKey())->delete();
        $character->forceFill(['district_id' => $to->getKey()])->save();
    }

    /** Seconds one pass in this district takes: the base times its weight, resolved, then halved for a pod. */
    private function passSeconds(Character $character, int $districtId, TravelMode $mode): int
    {
        $weight = (float) (District::query()->whereKey($districtId)->value('travel_weight') ?? 1.0);
        $base = (float) Config::get('city.travel.seconds', 30) * $weight;

        $seconds = $this->resolver->resolve('travel_duration', $base, $character->modifiers())->toInt();

        if ($mode === TravelMode::Pod) {
            $seconds = max(1, (int) ceil($seconds * (float) Config::get('city.travel.pod_time', 0.5)));
        }

        return max(1, $seconds);
    }
}
