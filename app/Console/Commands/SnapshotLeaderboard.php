<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Leaderboard\Snapshot;
use Illuminate\Console\Command;

/** Rewrite the leaderboard (docs/LEADERBOARD.md). Scheduled hourly at minute 12. */
class SnapshotLeaderboard extends Command
{
    protected $signature = 'leaderboard:snapshot';

    protected $description = 'Rank every character on every leaderboard and store the result';

    public function handle(Snapshot $snapshot): int
    {
        $this->info($snapshot->write().' ranked row(s) written.');

        return self::SUCCESS;
    }
}
