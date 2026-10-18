<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Characters\Fatigue;
use App\City\ActivityKind;
use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\Dose;
use App\Models\DrugUse;
use App\Models\Safehouse;
use App\Support\Points;
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
 * eases fatigue at the safehouse's `rest_rate` a minute, credited whenever somebody looks (the row's `updated_at` is how
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
        $resting = CityActivity::query()->whereKey($character->getKey())->where('kind', ActivityKind::Rest->value)->exists();

        if (! $resting) {
            return Refusal::NotResting;
        }

        $this->settleRest($character, stop: true);

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
