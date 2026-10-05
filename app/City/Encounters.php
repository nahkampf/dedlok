<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\Flags;
use App\Characters\WaitingRoom;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\CityActivity;
use App\Models\Encounter;
use App\Models\Location;
use App\Stats\Resolver;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Whether an encounter starts, which one, and the row that says a character is in it.
 *
 * THE SPAWN ROLL IS NOT A CHECK, which is why it is a flat percentile with an
 * injected die: it is the world deciding about somebody, with nothing to be
 * good at (`Bounties`, `Hunters::rolls()`). Its chance is the RESOLVED
 * `encounter_chance`, so a skill that quiets the street is a modifier and this
 * class learns nothing.
 *
 * WHICH ONE is a weighted draw out of max(eligible weights, `city.encounter.pool`).
 * The pool is what makes a rare encounter rare: a lone encounter of weight 2 is a
 * 2% pick, not a certainty because nothing else qualified.
 *
 * `open()` is the only writer of `character_encounters` and of
 * `characters.current_encounter_id`'s setting — `EncounterRunner` clears it.
 *
 * A TRAVEL OR EXPLORE PASS CAN MATURE WHILE SOMEBODY IS JACKED IN (jacking in
 * only refuses surgery), so nothing opens for a character who is in cyberspace,
 * in a run or a hunt, already in an encounter, or on the floor.
 */
final class Encounters
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(Resolver $resolver, ?Closure $roll = null): self
    {
        return new self($resolver, $roll ?? static fn (int $min, int $max): int => random_int($min, $max));
    }

    /** The odds a matured pass of this kind opens one, resolved. Public so a screen and the roll cannot disagree. */
    public function chanceFor(Character $character, EncounterStart $start): float
    {
        $base = (float) Config::get("city.encounter.{$start->value}_chance", 0.0);

        return max(0.0, min(1.0, $this->resolver->resolve('encounter_chance', $base, $character->modifiers())->value));
    }

    /** Is the character in a state anything can happen TO? */
    public function canHappenTo(Character $character): bool
    {
        return ! $character->isJackedIn()
            && $character->current_run_id === null
            && $character->current_hunt_id === null
            && $character->current_encounter_id === null
            // A fight is its own state: the sweep must not open an encounter under one.
            && $character->current_fight_id === null
            && ! (new WaitingRoom($this->resolver))->isDown($character);
    }

    /**
     * A matured pass in the character's district: maybe open one.
     *
     * Call this inside the settle transaction, on the LOCKED character.
     */
    public function maybeOpen(Character $character, EncounterStart $start): ?CharacterEncounter
    {
        if (! $this->canHappenTo($character)) {
            return null;
        }

        $chance = $this->chanceFor($character, $start);

        // Ten thousandths and inside the bound, like Hunters: a chance of nothing never fires.
        if ($chance <= 0.0 || ($this->roll)(1, 10_000) > (int) round($chance * 10_000)) {
            return null;
        }

        $pick = $this->draw($this->eligible($character, $start, $character->district_id));

        return $pick === null ? null : $this->open($character, $pick);
    }

    /**
     * What could start here, for this character, right now.
     *
     * @return Collection<int, Encounter>
     */
    public function eligible(Character $character, EncounterStart $start, ?int $districtId = null, ?int $locationId = null): Collection
    {
        $query = Encounter::query()
            ->where('retired', false)
            ->where('starts_on', $start->value)
            ->whereHas('nodes', static fn ($nodes) => $nodes->where('entry', true));

        if ($start === EncounterStart::Place) {
            $query->where('location_id', $locationId);
        } else {
            $query->whereNull('location_id')
                ->where(static fn ($q) => $q->whereNull('district_id')->orWhere('district_id', $districtId));
        }

        return $query->orderBy('id')->get()->filter(
            static fn (Encounter $e): bool => ($e->required_flag === null || Flags::has($character, $e->required_flag))
                && ! ($e->once && Flags::has($character, $e->seenFlag())),
        )->values();
    }

    /**
     * One weighted pick, or null when the draw lands past every share.
     *
     * @param  Collection<int, Encounter>  $candidates
     */
    public function draw(Collection $candidates): ?Encounter
    {
        $sum = (int) $candidates->sum('weight');
        $total = max($sum, (int) Config::get('city.encounter.pool', 100));

        if ($sum < 1) {
            return null;
        }

        $pick = ($this->roll)(1, $total);
        $running = 0;

        foreach ($candidates as $encounter) {
            $running += $encounter->weight;

            if ($pick <= $running) {
                return $encounter;
            }
        }

        return null;
    }

    /** Put a character in an encounter, on its entry node. The only door in. */
    public function open(Character $character, Encounter $encounter): CharacterEncounter
    {
        return DB::transaction(function () use ($character, $encounter): CharacterEncounter {
            $entry = $encounter->nodes()->where('entry', true)->firstOrFail();

            $live = CharacterEncounter::create([
                'character_id' => $character->getKey(),
                'encounter_id' => $encounter->getKey(),
                'node_key' => $entry->node_key,
                'log' => [],
                'started_at' => now(),
            ]);

            $character->forceFill(['current_encounter_id' => $live->getKey()])->save();

            // The opening screen may do something on arrival (an ambush), and says so in the log.
            app(EncounterRunner::class)->begin($character, $live, $entry);

            if ($encounter->once) {
                Flags::set($character, $encounter->seenFlag());
            }

            return $live;
        });
    }

    /**
     * The button on a place's page. Same refusals the other place doors make.
     */
    public function openAtPlace(Character $character, Location $place, int $encounterId): CharacterEncounter|CityRefusal
    {
        return DB::transaction(function () use ($character, $place, $encounterId): CharacterEncounter|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($refusal = Presence::refusal($locked, $place)) {
                return $refusal;
            }

            if (CityActivity::query()->whereKey($locked->getKey())->exists() || ! $this->canHappenTo($locked)) {
                return CityRefusal::Busy;
            }

            $encounter = $this->eligible($locked, EncounterStart::Place, null, $place->getKey())->firstWhere('id', $encounterId);

            return $encounter === null ? CityRefusal::NothingHappening : $this->open($locked, $encounter);
        });
    }
}
