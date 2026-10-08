<?php

declare(strict_types=1);

namespace App\Characters;

use App\City\ActivityKind;
use App\Decryption\DecryptionQueue;
use App\Models\Character;
use App\Models\CityActivity;
use App\Models\DecryptionEntry;
use App\Models\Dose;
use App\Models\SystemScan;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
     * @return list<array{kind: string, label: string, detail: string|null, ratio: float|null, seconds: int|null}>
     */
    public function for(Character $character, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());

        return [
            ...$this->scans($character, $now),
            ...$this->activity($character, $now),
            ...$this->decryption($character, $now),
            ...$this->training($character),
            ...$this->doses($character, $now),
        ];
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
