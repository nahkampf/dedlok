<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Illuminate\Console\Command;

/**
 * Telling somebody the intel they are holding stopped being worth anything, and clearing it out.
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
 * ## A stale file is deleted once it has been announced
 *
 * Nothing can use one (a hand-in, an import, a Swarm read all refuse it), so
 * keeping it only clutters the stash. The owner is told in this same pass,
 * then the row goes. A stale file this sweep never announced (the message
 * predates it) is deleted without a second message.
 *
 * ## Said once
 *
 * The row is marked, in the file's own JSON, before it is deleted: a message
 * written against a row that then failed to delete would otherwise be said
 * again on the next pass. A window on `expires_at` would have been one query
 * less and would lose the message entirely whenever a run was missed — which
 * is the one thing this exists to prevent.
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

    protected $description = 'Tell each holder of a scan file that has gone stale that it has, and delete it';

    public function handle(Notifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');
        $announced = 0;
        $deleted = 0;

        $rows = InventoryItem::query()
            ->whereNotNull('scan_file')
            ->with('item')
            ->orderBy('id');

        foreach ($rows->cursor() as $row) {
            $file = $row->scanFile();

            if ($file === null || ! $file->hasExpired()) {
                continue;
            }

            $deleted++;
            $announced += $file->staleAnnounced ? 0 : 1;

            if ($dry) {
                $this->line(sprintf('  %s — %s', $file->filename(), $file->displayName()));

                continue;
            }

            // Marked first. A message written against a row that then failed
            // to save would be said again on the next pass; the other order
            // costs at worst one file nobody was told about, and the card
            // says it is stale either way.
            if (! $file->staleAnnounced) {
                $row->forceFill(['scan_file' => $file->announced()->toArray()])->save();

                if ($row->character !== null) {
                    $notifier->send($row->character, Category::Cyberspace, 'file_stale', [
                        'file' => $file->filename(),
                        'system' => $file->displayName(),
                    ]);
                }
            }

            $row->delete();
        }

        if ($deleted === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d scan file(s) went stale, %d deleted%s.',
            $announced,
            $deleted,
            $dry ? ' (dry run, nothing written)' : '',
        ));

        return self::SUCCESS;
    }
}
