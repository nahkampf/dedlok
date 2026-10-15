<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\FortressHost;
use App\Cyberspace\FortressRelocation;
use App\Cyberspace\SystemType;
use App\Models\InventoryItem;
use App\Models\Leech;
use App\Models\System;
use Illuminate\Console\Command;

/** Keeps every Data Fortress's running programs inside its live totals, and deletes one offline past the grace period. */
class SettleFortresses extends Command
{
    protected $signature = 'fortress:settle {--quiet-when-idle : Print nothing unless something was deleted}';

    protected $description = 'Settle every Data Fortress against its Leechers, and delete the abandoned';

    public function handle(FortressHost $host): int
    {
        $deleted = 0;
        $hours = (int) config('cyberspace.fortress.abandon_hours', 48);

        foreach (System::query()->where('type', SystemType::Fortress->value)->get() as $fortress) {
            $host->settle($fortress);
            $fortress->refresh();
            (new FortressRelocation)->due($fortress);
            $fortress->refresh();

            $frozenOut = $fortress->frozen_until !== null && $fortress->frozen_until->isPast();
            $abandonedOut = $fortress->frozen_until === null && $fortress->abandoned_at !== null && $fortress->abandoned_at->lte(now()->subHours($hours));

            if ($frozenOut || $abandonedOut) {
                InventoryItem::query()->where('fortress_system_id', $fortress->getKey())->delete();
                Leech::query()->where('fortress_system_id', $fortress->getKey())->delete();
                $fortress->delete();
                $deleted++;
            }
        }

        if ($deleted > 0 || ! $this->option('quiet-when-idle')) {
            $this->info("Fortresses deleted: {$deleted}");
        }

        return self::SUCCESS;
    }
}
