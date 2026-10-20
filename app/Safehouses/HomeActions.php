<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Characters\Fatigue;
use App\Characters\Mood;
use App\City\ActivityKind;
use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Dose;
use App\Models\DrugUse;
use App\Models\Safehouse;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Points;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * What a character does in their safehouse. FATIGUE.md, *Safehouses*. The only writer of a rest's activity row and of
 * `character_safehouse_cooldowns`.
 *
 * ## Rest is a City activity
 *
 * So the lock is the one that already exists: nothing else starts while the row is there, and you cannot jack in. It
 * eases fatigue at the safehouse's `rest_rate` a minute and mends SI and HP at `safehouse.rest.synaptic_per_minute` / `hitpoints_per_minute`, credited whenever somebody looks (the row's `updated_at` is how
 * far it has been paid), and ends by itself when fatigue reaches nothing. The player may stop at any time.
 *
 * ## The others are instant, once per cooldown
 *
 * Their figures are `config/safehouse.php`. Nothing here may be used away from the safehouse's district.
 */
final class HomeActions
{
    public function __construct(
        private readonly Fatigue $fatigue = new Fatigue,
        private readonly DrugLedger $drugs = new DrugLedger,
        private readonly Mood $mood = new Mood,
    ) {}

    /** When the action is ready again; null if it is ready now. */
    public function cooldown(Character $character, Action $action): ?CarbonImmutable
    {
        $at = DB::table('character_safehouse_cooldowns')
            ->where('character_id', $character->getKey())->where('action', $action->value)->value('ready_at');

        return $at === null || CarbonImmutable::parse($at)->lte(now()) ? null : CarbonImmutable::parse($at);
    }

    /** Too much of something that keeps them awake, and nothing to override it. */
    public function tooWired(Character $character): bool
    {
        $awake = 0;

        foreach (Dose::query()->where('character_id', $character->getKey())->inEffect()->with('item.drug')->get() as $dose) {
            $drug = $dose->item?->drug;

            if ($drug === null) {
                continue;
            }

            if (in_array($drug->family->value, (array) Config::get('safehouse.rest.overridden_by'), true)) {
                return false;
            }

            $awake += $drug->wakefulness;
        }

        return $awake >= (int) Config::get('safehouse.rest.wakefulness_limit');
    }

    /** Fatigue is full: nothing that adds more can start or go on. */
    private function exhausted(Character $character): bool
    {
        return (float) $character->current_fatigue >= (float) $this->fatigue->maximum($character);
    }

    /** Seconds of simstim still allowed today (UTC); the day's count is zero on any other day. */
    public function simstimLeft(Character $character): int
    {
        $used = $character->simstim_day?->isSameDay(now()) ? (int) $character->simstim_seconds : 0;

        return max(0, (int) config('safehouse.sessions.simstim.daily_seconds') - $used);
    }

    /** Would using it change anything? */
    public function useful(Character $character, Action $action, ?Safehouse $home = null): bool
    {
        $home ??= $character->home();

        return match ($action) {
            Action::Rest => (float) $character->current_fatigue > 0,
            Action::Patch => (float) $character->current_hitpoints < (float) $character->maximumFor('current_hitpoints'),
            Action::Meditate => (float) $character->current_fatigue > 0
                || (float) $character->current_synaptic_integrity < (float) $character->maximumFor('current_synaptic_integrity'),
            Action::Shower => (float) $character->current_fatigue > 0
                || DrugUse::query()->where('character_id', $character->getKey())->where('addiction', '>', 0)->exists(),
            Action::Workout, Action::Study => ! $this->exhausted($character),
            Action::Simstim => ! $this->exhausted($character) && $this->simstimLeft($character) > 0,
            Action::Eat => ! $home->meal()->isEmpty() && ! $home->meal()->refusedFor($character),
        };
    }

    private function refusal(Character $character, Safehouse $home, Action $action): ?Refusal
    {
        if ($character->isJackedIn()) {
            return Refusal::JackedIn;
        }

        if ($character->current_fight_id !== null) {
            return Refusal::Fighting;
        }

        if (CityActivity::query()->whereKey($character->getKey())->exists()) {
            return Refusal::Busy;
        }

        if ($character->district_id !== $home->district_id) {
            return Refusal::NotHere;
        }

        if (! $home->offers($action)) {
            return Refusal::NotOffered;
        }

        if ($this->cooldown($character, $action) !== null) {
            return Refusal::Cooldown;
        }

        if ($action === Action::Eat && $home->meal()->refusedFor($character)) {
            return Refusal::TooFull;
        }

        return $this->useful($character, $action, $home) ? null : Refusal::NothingToDo;
    }

    /** Start working out, studying or simstim. Rest has its own door. */
    public function startSession(Character $character, Action $action): true|Refusal
    {
        return DB::transaction(function () use ($character, $action): true|Refusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $refusal = $this->refusal($locked, $locked->home(), $action);

            if ($refusal !== null) {
                return $refusal;
            }

            $set = (array) config("safehouse.sessions.{$action->value}");

            // Until fatigue would be full at this pace, and for simstim no longer than today's allowance.
            $seconds = (int) ceil(((float) $this->fatigue->maximum($locked) - (float) $locked->current_fatigue) / max(0.01, (float) $set['fatigue']) * 60);

            if ($action === Action::Simstim) {
                $seconds = min($seconds, $this->simstimLeft($locked));
            }

            CityActivity::query()->create([
                'character_id' => $locked->getKey(),
                'kind' => ActivityKind::from($action->value),
                'step' => 0,
                'paid' => 0,
                'cancelling' => false,
                'pass_ends_at' => CarbonImmutable::now()->addSeconds(max(1, $seconds)),
            ]);

            return true;
        });
    }

    /**
     * Credit a Work out, Study or Simstim so far, and end it when it is over. Safe to call for anybody.
     *
     * Every minute adds fatigue and either a meter (Work out, Study: at 100 a random attribute of the three goes up by
     * one, a message says so and the meter resets) or mood (Simstim, with a daily allowance). Credited by whole seconds
     * since the row was last paid, like a rest.
     *
     * ponytail: simstim's day is the day it is credited, so a session that straddles midnight is counted wholly on the
     * new day. Split the segment at midnight if anybody games it.
     */
    public function settleSession(Character $character, ?CarbonInterface $now = null, bool $stop = false): bool
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $ended = false;

        DB::transaction(function () use ($character, $now, $stop, &$ended): void {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->whereIn('kind', [ActivityKind::Workout->value, ActivityKind::Study->value, ActivityKind::Simstim->value])
                ->lockForUpdate()->first();

            if ($activity === null) {
                return;
            }

            $action = Action::from($activity->kind->value);
            $set = (array) config("safehouse.sessions.{$action->value}");
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $until = $activity->pass_ends_at < $now ? CarbonImmutable::instance($activity->pass_ends_at) : $now;
            $from = CarbonImmutable::instance($activity->updated_at ?? $activity->created_at);
            $seconds = max(0, (int) floor($from->diffInSeconds($until, false)));

            if ($action === Action::Simstim) {
                $seconds = min($seconds, $this->simstimLeft($locked));
            }

            if ($seconds > 0) {
                $this->fatigue->add($locked, (float) $set['fatigue'] * $seconds / 60);

                if ($action === Action::Simstim) {
                    $today = $locked->simstim_day?->isSameDay($now);
                    $locked->forceFill(['simstim_seconds' => ($today ? (int) $locked->simstim_seconds : 0) + $seconds, 'simstim_day' => $now->toDateString()])->save();
                    $this->mood->add($locked, (float) $set['mood'] * $seconds / 60);
                } else {
                    $this->gainProgress($locked, $action, (float) $set['progress'] * $seconds / 60);
                }
            }

            $finished = $activity->pass_ends_at <= $now || $this->exhausted($locked)
                || ($action === Action::Simstim && $this->simstimLeft($locked) <= 0);

            if ($stop || $finished) {
                $activity->delete();
                $ended = true;
            } else {
                $activity->forceFill(['updated_at' => $from->addSeconds($seconds)])->save();
            }

            $character->refresh();
        });

        return $ended;
    }

    /** Add to a meter; every time it passes 100 one attribute of the action's three rises by one (any not yet at the cap) and it resets to 0. */
    private function gainProgress(Character $locked, Action $action, float $by): void
    {
        $column = $action->value.'_progress';
        $value = (float) $locked->{$column} + $by;

        if ($value < 100) {
            $locked->forceFill([$column => round($value, 3)])->save();

            return;
        }

        $options = array_values(array_filter(
            (array) config("safehouse.sessions.{$action->value}.attributes"),
            fn (string $a): bool => (int) $locked->{$a} < Character::ATTRIBUTE_MAX,
        ));
        $gained = $options === [] ? null : $options[array_rand($options)];

        $locked->forceFill([$column => 0] + ($gained === null ? [] : [$gained => (int) $locked->{$gained} + 1]))->save();

        if ($gained !== null) {
            (new Notifier)->send($locked, Category::City, $action === Action::Workout ? 'workout_gain' : 'study_gain', [
                'attribute' => Text::get("character.names.{$gained}"),
            ]);
        }
    }

    /** Start resting. */
    public function startRest(Character $character): true|Refusal
    {
        return DB::transaction(function () use ($character): true|Refusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $home = $locked->home();
            $refusal = $this->refusal($locked, $home, Action::Rest);

            if ($refusal !== null) {
                return $refusal;
            }

            if ($this->tooWired($locked)) {
                return Refusal::Wired;
            }

            if ($home->rest_rate <= 0) {
                return Refusal::NothingToDo;
            }

            // The estimate: how long until nothing is left, at this home's rate. The row ends then at the latest.
            $minutes = (int) ceil((float) $locked->current_fatigue / $home->rest_rate);

            CityActivity::query()->create([
                'character_id' => $locked->getKey(),
                'kind' => ActivityKind::Rest,
                'step' => 0,
                'paid' => 0,
                'cancelling' => false,
                'pass_ends_at' => CarbonImmutable::now()->addMinutes($minutes),
            ]);

            return true;
        });
    }

    /**
     * Credit the rest so far, and end it when it is over. Safe to call for anybody.
     *
     * ponytail: the rate is the CURRENT home's, so a safehouse lost mid-rest finishes at the box's. Snapshot it on the
     * row if that ever matters.
     */
    public function settleRest(Character $character, ?CarbonInterface $now = null, bool $stop = false): bool
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $ended = false;

        DB::transaction(function () use ($character, $now, $stop, &$ended): void {
            $activity = CityActivity::query()->whereKey($character->getKey())
                ->where('kind', ActivityKind::Rest->value)->lockForUpdate()->first();

            if ($activity === null) {
                return;
            }

            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $until = $activity->pass_ends_at < $now ? CarbonImmutable::instance($activity->pass_ends_at) : $now;
            $from = CarbonImmutable::instance($activity->updated_at ?? $activity->created_at);
            $seconds = max(0, (int) floor($from->diffInSeconds($until, false)));

            if ($seconds > 0) {
                $this->fatigue->ease($locked, Points::of($locked->home()->rest_rate * $seconds / 60));

                // SI and HP mend while resting: whole hundredths of the running total, so the sum is exact however often somebody looks.
                $before = (int) floor($from->diffInSeconds(CarbonImmutable::instance($activity->created_at), true));

                foreach (['current_synaptic_integrity' => 'synaptic_per_minute', 'current_hitpoints' => 'hitpoints_per_minute'] as $resource => $key) {
                    $mend = (float) config("safehouse.rest.$key") * 100 / 60;
                    $hundredths = (int) floor($mend * ($before + $seconds)) - (int) floor($mend * $before);

                    if ($hundredths > 0) {
                        $locked->recover($resource, Points::of($hundredths / 100));
                    }
                }
            }

            // Wired up part-way through a rest ends it, keeping what it earned.
            $finished = $activity->pass_ends_at <= $now || (float) $locked->current_fatigue <= 0 || $this->tooWired($locked);

            if ($stop || $finished) {
                $activity->delete();
                $ended = true;
            } else {
                // Only the whole seconds credited are marked paid, so nothing is lost to a fraction.
                $activity->forceFill(['updated_at' => $from->addSeconds($seconds)])->save();
            }

            $character->refresh();
        });

        return $ended;
    }

    /** Stop resting, keeping what was earned. */
    public function stopRest(Character $character): true|Refusal
    {
        $kind = CityActivity::query()->whereKey($character->getKey())->value('kind');

        if ($kind === null || ! $kind->isSession()) {
            return Refusal::NotResting;
        }

        $kind === ActivityKind::Rest ? $this->settleRest($character, stop: true) : $this->settleSession($character, stop: true);

        return true;
    }

    /** Patch up, meditate or take a cold shower. */
    public function use(Character $character, Action $action): true|Refusal
    {
        return DB::transaction(function () use ($character, $action): true|Refusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $refusal = $this->refusal($locked, $locked->home(), $action);

            if ($refusal !== null) {
                return $refusal;
            }

            $home = $locked->home();
            $set = (array) Config::get("safehouse.actions.{$action->value}");

            match ($action) {
                Action::Patch => $locked->recover('current_hitpoints', $set['hitpoints']),
                Action::Meditate => $this->meditate($locked, $set),
                Action::Shower => $this->shower($locked, $set),
                Action::Eat => $this->eat($locked, $home),
                default => null,
            };

            DB::table('character_safehouse_cooldowns')->upsert(
                [['character_id' => $locked->getKey(), 'action' => $action->value, 'ready_at' => now()->addSeconds((int) ($set['cooldown'] ?? 0))]],
                ['character_id', 'action'],
                ['ready_at'],
            );

            $character->refresh();

            return true;
        });
    }

    /** A meal here: what this safehouse's table is worth. */
    private function eat(Character $character, Safehouse $home): void
    {
        $home->meal()->apply($character);
    }

    /** @param array<string, int> $set */
    private function meditate(Character $character, array $set): void
    {
        $character->recover('current_synaptic_integrity', $set['synaptic_integrity']);
        $this->fatigue->ease($character, $set['fatigue']);
    }

    /** @param array<string, int> $set */
    private function shower(Character $character, array $set): void
    {
        $this->fatigue->ease($character, $set['fatigue']);

        foreach (Family::cases() as $family) {
            $this->drugs->lowerAddiction($character, $family, $set['addiction']);
        }
    }
}
