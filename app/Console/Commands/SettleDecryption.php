<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Decryption\DecryptionQueue;
use App\Models\Character;
use App\Models\DecryptionEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keep every decryption queue true between visits.
 *
 * Settling is lazy and catches up whenever somebody looks (`DecryptionQueue`), so this only matters for the
 * inbox message and the next file's clock: a file that opens while nobody is watching has to say so. It rolls
 * exactly what a visit would have, once, under the same lock, so running it twice is harmless.
 */
class SettleDecryption extends Command
{
    protected $signature = 'decryption:settle {--quiet-when-idle : Print nothing unless a file opened}';

    protected $description = 'Roll every elapsed decryption cycle and open the files that broke';

    public function handle(DecryptionQueue $queue): int
    {
        $opened = 0;
        $characters = 0;

        Character::query()
            ->whereIn('id', DecryptionEntry::query()->select('character_id')->distinct())
            ->chunkById(200, function ($chunk) use ($queue, &$opened, &$characters): void {
                foreach ($chunk as $character) {
                    $characters++;
                    $opened += $queue->settle($character);
                }
            });

        if ($opened === 0 && $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $summary = sprintf('%d file(s) decrypted across %d queue(s)', $opened, $characters);
        $opened > 0 && Log::info("decryption:settle — {$summary}");
        $this->info($summary);

        return self::SUCCESS;
    }
}
