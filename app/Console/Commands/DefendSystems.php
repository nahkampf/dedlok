<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Defences;
use App\Cyberspace\SystemType;
use App\Models\System;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give the machines that were already standing the defences they were built
 * without.
 *
 * ## Why this exists rather than a migration
 *
 * The defence columns arrived defaulting to 0 — *has none* — which is the right
 * default for a hand-inserted row and the wrong state for a world. `GridSeeder`
 * rolls defences when a machine is CREATED, and a machine that already exists is
 * never created again: relocation keeps the row, and only a burn followed by a
 * seeding pass makes a new one. So without this, a world generated before
 * defences existed stays defenceless for ever, and the grid quietly disagrees
 * with every rule in CYBERSPACE.md.
 *
 * It is not in the migration because it ROLLS. A migration that carries dice is
 * a migration whose result depends on when it ran and cannot be reasoned about
 * from the file — and this one wants looking at before it writes, which is what
 * --dry-run is for.
 *
 * ## What it does not touch
 *
 * A machine that already carries something. This is a back-fill and not a
 * re-roll: a mission node authored with a grade 9 watchdog on a grade 2 box is
 * exactly the case the stored-not-derived design exists to allow, and a pass
 * that overwrote it would destroy the thing it was written to protect. Use
 * --force to re-roll anyway, which is for a world nobody has authored yet.
 *
 * The portal is skipped by `Defences::roll()` itself, so nothing here has to
 * know about it.
 */
class DefendSystems extends Command
{
    protected $signature = 'cyberspace:defend
                            {--dry-run : Roll for every machine and write nothing}
                            {--force : Re-roll machines that already carry defences}
                            {--sample=12 : How many rows to print in the decision table}';

    protected $description = 'Roll defences for machines that were generated before defences existed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $roll = static fn (int $min, int $max): int => random_int($min, $max);

        $query = System::query()->where('type', '!=', SystemType::Portal->value);

        if (! $this->option('force')) {
            // Untouched only: a machine that was told what to carry keeps it.
            $query->where('ids_detection', 0)
                ->where('tracer_detection', 0)
                ->where('tracer_rate', 0);
        }

        $rows = [];
        $touched = 0;
        $withIds = 0;
        $withTracer = 0;

        /*
         * ROLLED PER MACHINE, WRITTEN PER OUTCOME.
         *
         * Every machine gets its own roll — that is the whole point — but the
         * ANSWERS collapse into a handful of distinct triples, because there
         * are nine grades and four shapes a defence set can take. So the ids
         * are gathered by outcome and written with one statement each.
         *
         * A row-at-a-time save is 2 866 round trips to a host on the far side
         * of the internet, which took minutes and is the exact cost GridSeeder
         * was rewritten to avoid. This is about thirty statements.
         */
        $byOutcome = [];

        $query->orderBy('id')->chunkById(500, function ($systems) use (
            $roll, &$byOutcome, &$rows, &$touched, &$withIds, &$withTracer
        ): void {
            foreach ($systems as $system) {
                $defences = Defences::roll($system->type, $system->grade, $roll);
                $columns = $defences->columns();

                $touched++;
                $withIds += $defences->idsDetection > 0 ? 1 : 0;
                $withTracer += $defences->tracerDetection > 0 ? 1 : 0;

                $byOutcome[implode(':', $columns)][] = $system->getKey();

                $rows[] = [
                    $system->address,
                    $system->type->value,
                    $system->grade,
                    $defences->idsDetection ?: '—',
                    $defences->tracerDetection ?: '—',
                    $defences->tracerRate ?: '—',
                ];
            }
        });

        if (! $dryRun) {
            foreach ($byOutcome as $key => $ids) {
                [$idsDetection, $tracerDetection, $tracerRate] = array_map('intval', explode(':', $key));

                // Capped so the IN list stays a query rather than a novel.
                foreach (array_chunk($ids, 500) as $batch) {
                    System::query()->whereIn('id', $batch)->update([
                        'ids_detection' => $idsDetection,
                        'tracer_detection' => $tracerDetection,
                        'tracer_rate' => $tracerRate,
                    ]);
                }
            }
        }

        $summary = sprintf(
            '%d machine(s): %d with an IDS, %d with a tracer',
            $touched,
            $withIds,
            $withTracer,
        );

        if ($dryRun) {
            $this->table(
                ['address', 'type', 'grade', 'ids', 'tracer', 'rate'],
                array_slice($rows, 0, max(0, (int) $this->option('sample'))),
            );

            $this->info("Dry run: {$summary}. Nothing was written.");

            return self::SUCCESS;
        }

        Log::info("cyberspace:defend — {$summary}");
        $this->info($summary);

        return self::SUCCESS;
    }
}
