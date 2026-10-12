<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\Flags;
use App\Characters\WaitingRoom;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\CityActivity;
use App\Models\Encounter;
use App\Models\EncounterCooldown;
use App\Models\Location;
use App\Models\Run;
use App\Models\System;
use App\Stats\Resolver;
use Carbon\CarbonImmutable;
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
    public function chanceFor(Character $character, EncounterStart $start, ?float $own = null): float
    {
        $key = $start->isGrid() ? "cyberspace.encounter.{$start->value}_chance" : "city.encounter.{$start->value}_chance";
        // An encounter that sets its own chance puts it in the door's place, as the base the stat then adjusts.
        $base = $own ?? (float) Config::get($key, 0.0);

        return max(0.0, min(1.0, $this->resolver->resolve('encounter_chance', $base, $character->modifiers())->value));
    }

    /**
     * Is the character in a state anything can happen TO?
     *
     * The realm is the door's. A City encounter needs somebody who is NOT jacked in; a grid one needs somebody who is,
     * standing on the map (not in a run). Everything else is the same for both.
     */
    public function canHappenTo(Character $character, ?EncounterStart $start = null): bool
    {
        $grid = $start?->isGrid() ?? false;
        // A run door needs a live run; every other door needs the character NOT to be in one (they open on the map).
        $inRun = $start === EncounterStart::Run;

        return $character->isJackedIn() === $grid
            && ($character->current_run_id !== null) === $inRun
            && $character->current_hunt_id === null
            && $character->current_encounter_id === null
            // A fight is its own state: the sweep must not open an encounter under one.
            && $character->current_fight_id === null
            && ! (new WaitingRoom($this->resolver))->isDown($character);
    }

    /**
     * A move arrived at a system, or a run finished a turn: maybe open one.
     *
     * Called on the LOCKED character. `$system` is what stands here (the system door) or what the run is in (the run
     * door), and is what the encounter's filters are read against.
     */
    public function maybeOpenFor(Character $character, EncounterStart $start, System $system, ?Run $run = null): ?CharacterEncounter
    {
        if (! $this->canHappenTo($character, $start)) {
            return null;
        }

        // The encounters that set their own chance, each rolled on its own, first.
        if (($own = $this->rollOwn($character, $start, null, null, $system, $run)) !== null) {
            return $this->open($character, $own, $run);
        }

        $chance = $this->chanceFor($character, $start);

        if ($chance <= 0.0 || ($this->roll)(1, 10_000) > (int) round($chance * 10_000)) {
            return null;
        }

        $pick = $this->draw($this->eligible($character, $start, null, null, $system, $run)->whereNull('chance')->values());

        return $pick === null ? null : $this->open($character, $pick, $run);
    }

    /**
     * A matured pass in the character's district: maybe open one.
     *
     * Call this inside the settle transaction, on the LOCKED character.
     */
    public function maybeOpen(Character $character, EncounterStart $start): ?CharacterEncounter
    {
        if (! $this->canHappenTo($character, $start)) {
            return null;
        }

        $districtId = $start->isGrid() ? null : $character->district_id;

        // The encounters that set their own chance, each rolled on its own, first.
        if (($own = $this->rollOwn($character, $start, $districtId)) !== null) {
            return $this->open($character, $own);
        }

        $chance = $this->chanceFor($character, $start);

        // Ten thousandths and inside the bound, like Hunters: a chance of nothing never fires.
        if ($chance <= 0.0 || ($this->roll)(1, 10_000) > (int) round($chance * 10_000)) {
            return null;
        }

        $pick = $this->draw($this->eligible($character, $start, $districtId)->whereNull('chance')->values());

        return $pick === null ? null : $this->open($character, $pick);
    }

    /**
     * Each encounter of this door that sets its own chance rolls it, in id order; the first that lands and is still
     * eligible for this character is the one that opens.
     *
     * The die is rolled BEFORE eligibility is looked into, so a move that rolls nothing (almost all of them) costs
     * one small query and no more. The resolved `encounter_chance` stat adjusts each chance like the door's.
     */
    private function rollOwn(Character $character, EncounterStart $start, ?int $districtId = null, ?int $locationId = null, ?System $system = null, ?Run $run = null): ?Encounter
    {
        $own = Encounter::query()
            ->where('starts_on', $start->value)
            ->where('retired', false)
            ->whereNotNull('chance')
            ->orderBy('id')
            ->get();

        foreach ($own as $encounter) {
            $chance = $this->chanceFor($character, $start, (float) $encounter->chance / 100);

            if ($chance <= 0.0 || ($this->roll)(1, 10_000) > (int) round($chance * 10_000)) {
                continue;
            }

            if ($this->eligible($character, $start, $districtId, $locationId, $system, $run)->contains('id', $encounter->getKey())) {
                return $encounter;
            }
        }

        return null;
    }

    /**
     * What could start here, for this character, right now.
     *
     * @return Collection<int, Encounter>
     */
    public function eligible(Character $character, EncounterStart $start, ?int $districtId = null, ?int $locationId = null, ?System $system = null, ?Run $run = null): Collection
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

        $judge = new PrerequisiteJudge;

        return $query->with('prerequisites')->orderBy('id')->get()->filter(
            static fn (Encounter $e): bool => ($e->required_flag === null || Flags::has($character, $e->required_flag))
                && ! ($e->once && Flags::has($character, $e->seenFlag()))
                // The door's own filters: what stands here, or what the run is in.
                && self::matches($e, $system, $run)
                // Every prerequisite holds; an empty list is no condition at all.
                && $judge->allHold($character, $e->prerequisites),
        )->values();
    }

    /**
     * The spots on offer at this system for this character: pinned here, their conditions held, and off cooldown.
     * A read, for the Interact box; `openSpot()` asks it again when the button is pressed.
     *
     * @return Collection<int, Encounter>
     */
    public function spotsAt(Character $character, System $system): Collection
    {
        if (! $this->canHappenTo($character, EncounterStart::Spot)) {
            return collect();
        }

        $cooling = EncounterCooldown::query()
            ->where('character_id', $character->getKey())
            ->pluck('last_opened_at', 'encounter_id');

        return $this->eligible($character, EncounterStart::Spot, null, null, $system)
            ->filter(static fn (Encounter $e): bool => (int) $e->spot_system_id === (int) $system->getKey()
                && ($e->cooldown_hours === null
                    || ! $cooling->has($e->getKey())
                    || $cooling->get($e->getKey())->addHours($e->cooldown_hours)->isPast()))
            ->values();
    }

    /** The button: open a spot at the system the character stands on. Not rolled. */
    public function openSpot(Character $character, int $encounterId): CharacterEncounter|CityRefusal
    {
        return DB::transaction(function () use ($character, $encounterId): CharacterEncounter|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $position = $locked->gridPosition();
            $system = $position === null ? null : System::query()->at($position)->first();

            if ($system === null) {
                return CityRefusal::NothingHappening;
            }

            $encounter = $this->spotsAt($locked, $system)->firstWhere('id', $encounterId);

            if ($encounter === null) {
                return CityRefusal::NothingHappening;
            }

            EncounterCooldown::query()->updateOrCreate(
                ['character_id' => $locked->getKey(), 'encounter_id' => $encounter->getKey()],
                ['last_opened_at' => CarbonImmutable::now()],
            );

            return $this->open($locked, $encounter);
        });
    }

    /** Do an encounter's system and run filters let this system (and run) through? A filter left blank passes. */
    private static function matches(Encounter $e, ?System $system, ?Run $run): bool
    {
        if ($system === null) {
            return true;
        }

        if ($e->system_type !== null && $e->system_type !== $system->type->value) {
            return false;
        }

        $grade = (int) $system->grade;

        if (($e->grade_min !== null && $grade < $e->grade_min) || ($e->grade_max !== null && $grade > $e->grade_max)) {
            return false;
        }

        if ($e->owner_entity_id !== null && (int) $system->owner_id !== (int) $e->owner_entity_id) {
            return false;
        }

        return $e->run_purpose === null || $run === null || $e->run_purpose === $run->purpose->value;
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
    public function open(Character $character, Encounter $encounter, ?Run $run = null): CharacterEncounter
    {
        return DB::transaction(function () use ($character, $encounter, $run): CharacterEncounter {
            $entry = $encounter->nodes()->where('entry', true)->firstOrFail();

            $live = CharacterEncounter::create([
                'character_id' => $character->getKey(),
                'encounter_id' => $encounter->getKey(),
                // The run it interrupts, so closing knows which one to end or leave.
                'run_id' => $run?->getKey(),
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
