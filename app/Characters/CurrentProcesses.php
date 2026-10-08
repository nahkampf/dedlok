<?php

declare(strict_types=1);

namespace App\Characters;

use App\City\ActivityKind;
use App\Crafting\Crafting;
use App\Decryption\DecryptionQueue;
use App\Drugs\DrugLedger;
use App\Drugs\Family;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\DecryptionEntry;
use App\Models\Dose;
use App\Models\Mission;
use App\Models\SystemScan;
use App\Programs\Lifetime;
use App\Stats\Resolver;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Everything in flight for a character, as a list a modal can draw: a scan under way, the files being decrypted, the
 * skill being trained, the drugs in effect, a trip, an exploration or an operation.
 *
 * READ ONLY, and it writes nothing of its own: it asks the thing that owns each clock. The one exception is that it
 * settles the decryption queue first, so a cycle shown is a cycle that is true. Each row says how far along it is (a
 * ratio, or null when nothing honest can be said) and how many seconds are left (or null), which is what the bar and its
 * hover read. A SOURCE THAT HAS NO END (a decryption cycle repeats until it breaks) shows the current cycle and says
 * so in its detail line.
 *
 * Fetched when the modal opens and never on every page: working it out costs a handful of queries.
 */
final class CurrentProcesses
{
    /**
     * @return list<array{group: string, kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}>
     */
    public function for(Character $character, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());

        return [
            // What the character set going, or has hanging over them...
            ...$this->tagged('process', [
                ...$this->scans($character, $now),
                ...$this->activity($character, $now),
                ...$this->decryption($character, $now),
                ...$this->crafting($character),
                ...$this->training($character),
                ...$this->doses($character, $now),
                ...$this->scripts($character, $now),
                ...$this->withdrawals($character, $now),
                ...$this->missions($character, $now),
            ]),
            // ...and the world's clocks that will move something of theirs.
            ...$this->tagged('timer', $this->timers($character, $now)),
        ];
    }

    /**
     * @param  list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}>  $rows
     * @return list<array{group: string, kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}>
     */
    private function tagged(string $group, array $rows): array
    {
        return array_map(static fn (array $row): array => ['group' => $group] + $row, $rows);
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function scans(Character $character, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (SystemScan::query()->where('character_id', $character->getKey())->live()->whereNotNull('pass_ready_at')->orderBy('pass_ready_at')->get() as $scan) {
            $rows[] = [
                'kind' => 'scan',
                'label' => Text::get('processes.scan', ['address' => $scan->address]),
                'detail' => Text::get('processes.scan_pass', ['pass' => (int) $scan->passes + 1]),
                'ratio' => $this->between($scan->pass_started_at, $scan->pass_ready_at, $now),
                'seconds' => $this->until($scan->pass_ready_at, $now),
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function activity(Character $character, CarbonImmutable $now): array
    {
        $activity = CityActivity::query()->find($character->getKey());

        if ($activity === null) {
            return [];
        }

        $passes = max(1, count((array) $activity->route) - 1);

        return [[
            'kind' => $activity->kind->value,
            'label' => Text::get("processes.{$activity->kind->value}"),
            'detail' => $activity->kind === ActivityKind::Travel
                ? Text::get('processes.travel_pass', ['step' => min($passes, (int) $activity->step + 1), 'of' => $passes])
                : null,
            // A trip is judged by the passes done; a single-pass activity has only its own clock, which began with it.
            'ratio' => $activity->kind === ActivityKind::Travel
                ? max(0.0, min(1.0, (int) $activity->step / $passes))
                : $this->between($activity->created_at, $activity->pass_ends_at, $now),
            'seconds' => $this->until($activity->pass_ends_at, $now),
        ]];
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function decryption(Character $character, CarbonImmutable $now): array
    {
        $queue = DecryptionQueue::make();
        $queue->settle($character, $now);

        $cycle = DecryptionQueue::cycleSeconds($character);
        $rows = [];
        $waiting = 0;

        foreach (DecryptionEntry::query()->with('file.item')->where('character_id', $character->getKey())->orderBy('position')->get() as $entry) {
            if ($entry->worker_id === null || $entry->cycle_started_at === null) {
                $waiting++;

                continue;
            }

            $file = $entry->file === null ? null : DecryptionQueue::fileOf($entry->file);
            $odds = $entry->file === null ? null : $queue->odds($character, $entry->file);
            $end = $entry->cycle_started_at->addSeconds($cycle);

            $rows[] = [
                'kind' => 'decryption',
                'label' => Text::get('processes.decryption', ['label' => $file?->label ?? '']),
                // No end of its own: it repeats until it breaks, so the bar is the CYCLE and the detail says the odds.
                'detail' => $odds === null ? null : Text::get('processes.decryption_cycle', ['percent' => (int) round($odds['chance'] * 100)]),
                'ratio' => $this->between($entry->cycle_started_at, $end, $now),
                'seconds' => $this->until($end, $now),
            ];
        }

        if ($waiting > 0) {
            $rows[] = [
                'kind' => 'decryption',
                'label' => Text::plural('processes.decryption_waiting', $waiting, ['count' => $waiting]),
                'detail' => null,
                'ratio' => null,
                'seconds' => null,
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function training(Character $character): array
    {
        $entry = $character->trainingQueue()->with('skill')->orderBy('position')->first();
        $skill = $entry?->skill;

        if ($entry === null || $skill === null) {
            return [];
        }

        $points = (int) DB::table('character_skills')->where('character_id', $character->getKey())->where('skill_id', $skill->getKey())->value('skill_points');
        $target = (int) $entry->target_level;
        $floor = $target > 1 ? $skill->pointsForLevel($target - 1) : 0;
        $ceiling = $skill->pointsForLevel($target);
        $rate = $character->trainingRateFor($skill);

        return [[
            'kind' => 'training',
            'label' => Text::get('processes.training', ['skill' => $skill->title, 'level' => $target]),
            'detail' => null,
            'ratio' => $ceiling > $floor ? max(0.0, min(1.0, ($points - $floor) / ($ceiling - $floor))) : 0.0,
            'seconds' => $rate > 0 ? (int) ceil(max(0, $ceiling - $points) / $rate * 60) : null,
        ]];
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function doses(Character $character, CarbonImmutable $now): array
    {
        $rows = [];

        // A fight-only dose runs on rounds and is not a process outside a fight.
        foreach (Dose::query()->with('item')->where('character_id', $character->getKey())->inEffect($now)->whereNull('turns_left')->orderBy('expires_at')->get() as $dose) {
            $rows[] = [
                'kind' => 'dose',
                'label' => Text::get('processes.dose', ['item' => $dose->item?->displayName() ?? '']),
                'detail' => null,
                'ratio' => $this->between($dose->started_at, $dose->expires_at, $now),
                'seconds' => $this->until($dose->expires_at, $now),
            ];
        }

        return $rows;
    }

    /** Builds: a bar of working time done, on hold while the deck will not run. */
    private function crafting(Character $character): array
    {
        $crafting = Crafting::make();
        $crafting->settle($character);
        $ready = $crafting->deckReady($character);
        $rows = [];

        foreach ($crafting->crafts($character) as $craft) {
            $rows[] = [
                'kind' => 'craft',
                'label' => Text::get('processes.craft', ['item' => $craft->name]),
                'detail' => $craft->isFinished() ? Text::get('processes.craft_waiting') : ($ready ? null : Text::get('processes.craft_on_hold')),
                'ratio' => $craft->required_seconds > 0 ? min(1.0, $craft->progress_seconds / $craft->required_seconds) : 1.0,
                'seconds' => $craft->isFinished() || ! $ready ? null : $craft->remainingSeconds(),
            ];
        }

        return $rows;
    }

    /** A Script on the deck: a bar from the moment it started to the moment it is spent. */
    private function scripts(Character $character, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($character->runningProcesses() as $process) {
            $end = Lifetime::endsAt($process);

            if ($end === null) {
                continue;
            }

            $rows[] = [
                'kind' => 'script',
                'label' => Text::get('processes.script', ['program' => $process->item?->displayName() ?? '']),
                'detail' => Text::get('processes.script_detail'),
                'ratio' => $this->between($process->started_at, $end, $now),
                'seconds' => $this->until($end, $now),
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function withdrawals(Character $character, CarbonImmutable $now): array
    {
        $ledger = app(DrugLedger::class);
        $line = (float) Config::get('drugs.withdrawal.threshold', 40.0);
        $step = max(0.01, (float) Config::get('drugs.decay.addiction', 1.0));
        $rows = [];

        foreach (Family::cases() as $family) {
            if (! $family->addicts() || ! $ledger->isHooked($character, $family) || $ledger->hasInThem($character, $family)) {
                continue;
            }

            // The books fall a step each night; the withdrawal ends the night one falls under the line. A dose ends it sooner.
            $nights = (int) floor(((float) $ledger->for($character, $family)->addiction - $line) / $step) + 1;
            $first = $this->nextRun('drugs_metabolise', $now);

            $rows[] = [
                'kind' => 'withdrawal',
                'label' => Text::get('processes.withdrawal', ['family' => $family->label()]),
                'detail' => Text::plural('processes.withdrawal_nights', $nights, ['count' => $nights]),
                // No honest start to measure from, so no bar: only the time the nightly step takes to clear it.
                'ratio' => null,
                'seconds' => $first === null ? null : max(0, $first->addDays($nights - 1)->getTimestamp() - $now->getTimestamp()),
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}> */
    private function missions(Character $character, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (Mission::query()->accepted()->with('entity')->where('character_id', $character->getKey())->orderBy('expires_at')->get() as $mission) {
            $rows[] = [
                'kind' => 'mission',
                'label' => Text::get('processes.mission', ['action' => $mission->action->label(), 'entity' => $mission->entity?->name ?? '']),
                'detail' => null,
                'ratio' => $this->between($mission->accepted_at, $mission->expires_at, $now),
                'seconds' => $this->until($mission->expires_at, $now),
            ];
        }

        return $rows;
    }

    /**
     * The clocks that move something of the character's: the market's restock, and their own mending and regeneration. A
     * regeneration has no end, so it is a rate in words; the mend and the restock are counted down to the next one.
     *
     * @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}>
     */
    private function timers(Character $character, CarbonImmutable $now): array
    {
        $rows = [];
        $next = $this->nextRun('market_seed', $now);
        $previous = $this->previousRun('market_seed', $now);

        if ($next !== null) {
            $rows[] = [
                'kind' => 'restock',
                'label' => Text::get('processes.restock'),
                'detail' => null,
                'ratio' => $previous === null ? null : $this->between($previous, $next, $now),
                'seconds' => max(0, $next->getTimestamp() - $now->getTimestamp()),
            ];
        }

        // Healing waits while a fight is open; the clock stops with the player's.
        if ($character->current_fight_id !== null) {
            return $rows;
        }

        $siShort = (int) $character->current_synaptic_integrity < $character->maximumFor('current_synaptic_integrity');
        $hpShort = (int) $character->current_hitpoints < $character->maximumFor('current_hitpoints');

        if ($siShort) {
            // A point lands on the minute whose number divides by the interval: no stored clock, so the next mark is sums.
            $interval = max(1, $character->stat('synaptic_mend_minutes')->toInt()) * 60;
            $mark = intdiv($now->getTimestamp(), $interval) * $interval;

            $rows[] = [
                'kind' => 'mend',
                'label' => Text::get('processes.mend'),
                'detail' => null,
                'ratio' => max(0.0, min(1.0, ($now->getTimestamp() - $mark) / $interval)),
                'seconds' => $mark + $interval - $now->getTimestamp(),
            ];

            $regen = app(Resolver::class)->resolve('synaptic_regen', 0.0, $character->modifiers())->toInt();

            if ($regen > 0) {
                $rows[] = ['kind' => 'regen', 'label' => Text::get('processes.si_regen', ['rate' => $regen]), 'detail' => null, 'ratio' => null, 'seconds' => null];
            }
        }

        if ($hpShort) {
            $regen = app(Resolver::class)->resolve('hitpoints_regen', 0.0, $character->modifiers())->toInt();

            if ($regen > 0) {
                $rows[] = ['kind' => 'regen', 'label' => Text::get('processes.hp_regen', ['rate' => $regen]), 'detail' => null, 'ratio' => null, 'seconds' => null];
            }
        }

        return $rows;
    }

    /** When a scheduled job next runs, from its cron expression in config/cadence.php. */
    private function nextRun(string $key, CarbonImmutable $now): ?CarbonImmutable
    {
        $cron = Config::get("cadence.{$key}");

        return is_string($cron) ? CarbonImmutable::instance(CronExpression::factory($cron)->getNextRunDate($now)) : null;
    }

    private function previousRun(string $key, CarbonImmutable $now): ?CarbonImmutable
    {
        $cron = Config::get("cadence.{$key}");

        return is_string($cron) ? CarbonImmutable::instance(CronExpression::factory($cron)->getPreviousRunDate($now)) : null;
    }

    /** How far through [start, end] the moment is, 0-1; null when either end is unknown. */
    private function between(?\DateTimeInterface $start, ?\DateTimeInterface $end, CarbonImmutable $now): ?float
    {
        if ($start === null || $end === null) {
            return null;
        }

        $span = $end->getTimestamp() - $start->getTimestamp();

        return $span > 0 ? max(0.0, min(1.0, ($now->getTimestamp() - $start->getTimestamp()) / $span)) : 1.0;
    }

    private function until(?\DateTimeInterface $end, CarbonImmutable $now): ?int
    {
        return $end === null ? null : max(0, $end->getTimestamp() - $now->getTimestamp());
    }
}
