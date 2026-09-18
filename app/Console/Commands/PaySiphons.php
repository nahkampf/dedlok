<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\SiphonPayout;
use App\Models\Backdoor;
use Illuminate\Console\Command;

/**
 * A little from every machine somebody is draining.
 *
 * The safe half of a backdoor portfolio: it pays whether or not its holder
 * goes anywhere, and nothing takes it but the ageing roll. Runs BEFORE that
 * roll, so a backdoor discovered tonight still paid tonight — which is the
 * kinder ordering and the one that matches the fiction, since the discovery
 * happens at the end of a night the siphon worked.
 *
 * `market:seed`'s stance, like the rest of housekeeping: no stored clock, a
 * missed night is never made up, `withoutOverlapping()`, and a `--dry-run`
 * that decides everything and writes nothing.
 */
class PaySiphons extends Command
{
    protected $signature = 'cyberspace:siphons
                            {--dry-run : Report what would be paid and write nothing}
                            {--quiet-when-idle : Print nothing unless something was paid}';

    protected $description = 'Pay out every siphon backdoor, once';

    public function handle(SiphonPayout $siphons): int
    {
        if ((bool) $this->option('dry-run')) {
            $rows = [];
            $total = 0;

            foreach (Backdoor::query()->ofKind(BackdoorKind::Siphon)->with('system')->get() as $backdoor) {
                if ($backdoor->system === null) {
                    continue;
                }

                // The BASE only: what a holder's own modifiers do to it is the
                // payout's business, and a dry run that resolved every
                // character's sheet would be doing the pass to describe it.
                $base = $siphons->baseFor((int) $backdoor->system->grade);
                $total += $base;

                $rows[] = [$backdoor->character_id, $backdoor->system->displayName(), $backdoor->system->grade, $base];
            }

            if ($rows === []) {
                $this->info('Nobody is draining anything.');

                return self::SUCCESS;
            }

            $this->table(['character', 'machine', 'grade', 'base'], $rows);
            $this->info("{$total} creds before modifiers. Nothing was written.");

            return self::SUCCESS;
        }

        $result = $siphons->run();

        if ($result['creds'] === 0 && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d siphon(s) across %d character(s) paid %d creds.',
            $result['backdoors'],
            $result['characters'],
            $result['creds'],
        ));

        return self::SUCCESS;
    }
}
