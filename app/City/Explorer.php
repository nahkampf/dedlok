<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\Fatigue;
use App\Checks\CheckRoller;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\District;
use App\Models\Location;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Safehouses\Leads;
use App\Stats\Resolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Exploring: one timed pass in the district the body is in.
 *
 * When it matures it rolls once per still-hidden explorable location in the
 * district, against the difficulty for its concealment, with the resolved
 * `exploration` pool — read through the Sheet, since a pool read anywhere else
 * is zero. A pass finds at most one: where several rolls succeed the HARDEST
 * wins and the rest wait for another pass, which keeps each concealment's
 * quoted chance true however many places are left.
 *
 * IT NEVER SAYS HOW MUCH IS HIDDEN. Exploring is never refused for there being
 * nothing left, and "found nothing" is one sentence whether every roll failed
 * or there was nothing to roll against.
 */
final class Explorer
{
    public function __construct(
        private readonly CheckRoller $roller,
        private readonly Resolver $resolver,
        private readonly Encounters $encounters,
        private readonly Fatigue $fatigue = new Fatigue,
    ) {}

    public function begin(Character $character): CityActivity|CityRefusal
    {
        return DB::transaction(function () use ($character): CityActivity|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isJackedIn()) {
                return CityRefusal::JackedIn;
            }

            if ($locked->current_fight_id !== null) {
                return CityRefusal::Fighting;
            }

            if (CityActivity::query()->whereKey($locked->getKey())->exists()) {
                return CityRefusal::Busy;
            }

            return CityActivity::query()->create([
                'character_id' => $locked->getKey(),
                'kind' => ActivityKind::Explore,
                'step' => 0,
                'paid' => 0,
                'cancelling' => false,
                'pass_ends_at' => CarbonImmutable::now()->addSeconds($this->duration($locked)),
            ]);
        });
    }

    /** Stop exploring now. The roll is lost. */
    public function cancel(Character $character): true|CityRefusal
    {
        $this->settle($character);

        return CityActivity::query()->whereKey($character->getKey())
            ->where('kind', ActivityKind::Explore->value)->delete() > 0
            ? true
            : CityRefusal::NothingToCancel;
    }

    public function duration(Character $character): int
    {
        return $this->resolver
            ->resolve('explore_duration', (float) Config::get('city.explore.seconds', 240), $character->modifiers())
            ->toInt();
    }

    /** @return list<array{0: string, 1: array<string, string>}> message key and replacements */
    public function settle(Character $character, ?CarbonInterface $now = null, bool $notify = false): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $messages = [];

        DB::transaction(function () use ($character, $now, &$messages): void {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->where('kind', ActivityKind::Explore->value)->lockForUpdate()->first();

            if ($activity === null || $activity->pass_ends_at > $now) {
                return;
            }

            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $found = $this->roll($locked);
            $activity->delete();

            // A finished pass tires, and counts towards knowing the district.
            $this->fatigue->charge($locked, 'explore_pass');
            DB::table('character_district_passes')->upsert(
                [['character_id' => $locked->getKey(), 'district_id' => $locked->district_id, 'passes' => 1]],
                ['character_id', 'district_id'],
                ['passes' => DB::raw('passes + 1')],
            );

            // What the pass found is settled FIRST and is never lost; an encounter may follow it.
            // Finding nothing says nothing: silence is the same whether every roll failed or there
            // was nothing left to roll against, so it can never tell a player how much is hidden.
            if ($found !== null) {
                $messages[] = ['explore_found', ['place' => $found->name]];
            }

            // And it may have sensed somebody's home.
            if ($sensed = app(Leads::class)->discover($locked)) {
                $messages[] = [$sensed, []];
            }

            if ($this->encounters->maybeOpen($locked, EncounterStart::Explore) !== null) {
                $messages[] = ['encounter_waiting', ['district' => District::query()->find($locked->district_id)?->name ?? '']];
            }
        });

        if ($notify) {
            foreach ($messages as [$key, $replace]) {
                (new Notifier)->send($character, Category::City, $key, $replace);
            }
        }

        return $messages;
    }

    /** One pass: the hardest success among the still-hidden places here, or null. */
    private function roll(Character $character): ?Location
    {
        $value = $character->stat('exploration')->value;

        $candidates = Location::query()
            ->where('district_id', $character->district_id)
            ->where('discovery', DiscoveryRule::Explorable->value)
            ->whereNotIn('id', DB::table('character_locations')->select('location_id')->where('character_id', $character->getKey()))
            ->orderBy('id')
            ->get();

        $best = null;
        $bestDifficulty = -1.0;

        foreach ($candidates as $location) {
            $difficulty = (float) Config::get("city.explore.difficulty.{$location->concealment?->value}", 0);

            if (! $this->roller->roll($value, $difficulty, 'exploration')->passed) {
                continue;
            }

            // Strictly greater: of two equally hidden places the lower id wins.
            if ($difficulty > $bestDifficulty) {
                $best = $location;
                $bestDifficulty = $difficulty;
            }
        }

        if ($best !== null) {
            Discovery::unlock($character, $best);
        }

        return $best;
    }
}
