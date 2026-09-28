<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Characters\Bounties;
use App\Models\Character;
use Illuminate\Console\Command;

/**
 * Word goes out about somebody.
 *
 * THE FIRST THING HEAT DOES. Every character carrying any is rolled once a
 * night against the chance that somebody decides they are worth money — the
 * uncertainty floor under five points, climbing to certain at a hundred. A
 * character with none is not rolled: the floor is what an attempt keeps, not
 * something the world owes a person who has done nothing.
 *
 * ## Five in the morning, and the usual stance
 *
 * After the four-o'clock housekeeping, so a trace that completed during the
 * night is already on the character being rolled. `market:seed`'s stance
 * otherwise: no stored clock, a missed night is never made up, and
 * `withoutOverlapping()` because two copies would be two decisions about the
 * same person.
 *
 * ## What lands is a notification and nothing else
 *
 * There is no bounty row, no hunter and no price — those are heat's unfinished
 * half. The message says the word is out and stops there, which is as far as
 * the mechanic goes.
 */
class RollBounties extends Command
{
    protected $signature = 'characters:bounties
                            {--dry-run : Report the odds and decide nothing}
                            {--quiet-when-idle : Print nothing unless word went out}';

    protected $description = 'Roll every character carrying heat against the chance somebody puts a price on them';

    public function handle(Bounties $bounties): int
    {
        if ((bool) $this->option('dry-run')) {
            return $this->report($bounties);
        }

        $marked = $bounties->tonight();

        if ($marked === [] && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        foreach ($marked as $character) {
            $this->line(sprintf('  word is out on %s (heat %.1f)', $character->handle, (float) $character->heat));
        }

        $this->info(sprintf('%d character(s) had word put out.', count($marked)));

        return self::SUCCESS;
    }

    /** What would be rolled, and at what odds. Decides nothing and writes nothing. */
    private function report(Bounties $bounties): int
    {
        $rows = Character::query()
            ->where('heat', '>', 0)
            ->orderByDesc('heat')
            ->get(['handle', 'heat', 'bounty_word_at']);

        if ($rows->isEmpty()) {
            $this->info('Nobody is carrying any heat.');

            return self::SUCCESS;
        }

        $rolled = 0;

        foreach ($rows as $character) {
            if ($bounties->resting($character)) {
                $this->line(sprintf('  %-24s heat %5.1f   word already out', $character->handle, (float) $character->heat));

                continue;
            }

            $rolled++;

            $this->line(sprintf(
                '  %-24s heat %5.1f   %5.2f%%',
                $character->handle,
                (float) $character->heat,
                $bounties->chanceFor((float) $character->heat) * 100,
            ));
        }

        $this->info(sprintf('%d character(s) would be rolled. Nothing was written.', $rolled));

        return self::SUCCESS;
    }
}
