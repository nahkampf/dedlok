<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Console\Command;

/**
 * Telling somebody the intel they are holding stopped being worth anything.
 *
 * ## Why this is a sweep and not lazy
 *
 * A scan file's 48 hours ride along in the row's own JSON and are read when
 * something asks — the card, a hand-in, a buyer deciding. That is deliberate,
 * and nothing about it needed a clock. But the MESSAGE does: a file dies at
 * some arbitrary minute with nobody looking at it, and its owner finds out by
 * opening a card they had no reason to open. `drugs:withdraw` answers the
 * same shape of problem the same way.
 *
 * HOURLY, not every five minutes, because the clock being announced is two
 * days long. A file that died forty minutes ago is news; one announced forty
 * minutes late is the same news.
 *
 * ## A stale file is still yours
 *
 * Nothing here deletes anything. The design has always been that a stale file
 * is a worthless row somebody destroys — the player's call, from the card,
 * with the same confirmation every other destroy has. This only says so.
 *
 * ## Said once
 *
 * The row that was announced is marked, in the file's own JSON, because a
 * stale file sits in a stash indefinitely and an hourly sweep with no memory
 * would say the same sentence every hour for ever. A window on `expires_at`
 * would have been one query less and would lose the message entirely whenever
 * a run was missed — which is the one thing this exists to prevent.
 *
 * ponytail: it reads every row that carries a file and filters in PHP. At the
 * volume one player's scanning produces that is nothing; push the expiry and
 * the flag into the WHERE with a JSON path if the stash ever gets big.
 */
class AnnounceStaleFiles extends Command
{
    protected $signature = 'scans:stale
                            {--dry-run : Say what would be announced and write nothing}
                            {--quiet-when-idle : Print nothing unless something went stale}';

    protected $description = 'Tell each holder of a scan file that has gone stale that it has';

    public function handle(Notifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');
        $announced = 0;

        $rows = InventoryItem::query()
            ->whereNotNull('scan_file')
            ->with('item')
            ->orderBy('id');

        foreach ($rows->cursor() as $row) {
            $file = $row->scanFile();

            if ($file === null || $file->staleAnnounced || ! $file->hasExpired()) {
                continue;
            }

            $announced++;

            if ($dry) {
                $this->line(sprintf('  %s — %s', $file->filename(), $file->displayName()));

                continue;
            }

            // Marked first. A message written against a row that then failed
            // to save would be said again on the next pass; the other order
            // costs at worst one file nobody was told about, and the card
            // says it is stale either way.
            $row->forceFill(['scan_file' => $file->announced()->toArray()])->save();

            $character = $row->character;

            if ($character === null) {
                continue;
            }

            $notifier->send($character, Category::Cyberspace, 'file_stale', [
                'file' => $file->filename(),
                'system' => $file->displayName(),
            ]);
        }

        if ($announced === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d scan file(s) went stale%s.',
            $announced,
            $dry ? ' (dry run, nothing written)' : '',
        ));

        return self::SUCCESS;
    }
}
