<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Tracker;
use App\Models\Character;
use App\Models\TrackerSearch;
use Illuminate\Console\Command;

/**
 * Keep every released Tracker true between visits. Settling is lazy and catches up whenever somebody looks; this makes
 * a find that happens while nobody is watching send its message. Rolls exactly what a visit would have, under the same
 * lock, so running it twice is harmless.
 */
class SettleTrackers extends Command
{
    protected $signature = 'tracker:settle {--quiet-when-idle : Print nothing unless a Tracker found something}';

    protected $description = 'Roll every elapsed Tracker pass and report the finds';

    public function handle(Tracker $tracker): int
    {
        $found = 0;

        Character::query()
            ->whereIn('id', TrackerSearch::query()->whereNotNull('pass_started_at')->select('character_id'))
            ->chunkById(200, function ($chunk) use ($tracker, &$found): void {
                foreach ($chunk as $character) {
                    $found += $tracker->settle($character) ? 1 : 0;
                }
            });

        if ($found > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("{$found} Tracker(s) found something");
        }

        return self::SUCCESS;
    }
}
