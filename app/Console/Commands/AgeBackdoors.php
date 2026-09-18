<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Backdoors;
use App\Models\Backdoor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Somebody finds the way in, and the machine does not stay where it was.
 *
 * ## The only thing that takes a backdoor from somebody who did nothing wrong
 *
 * A backdoor keeps a system open — no lockdown, no relocation, a permanent
 * address in a world where nothing else has one. CYBERSPACE.md is plain that
 * this is powerful enough not to be permanent, and the ageing roll is what
 * makes it temporary: one roll per backdoor per night, odds rising with AGE
 * and with nothing else. Not the machine's grade, not what has been done
 * through it, so a backdoor cannot be held carefully enough to keep.
 *
 * Everything else that takes one is somebody's fault — a stranger's run that
 * shuts the machine, a trace that walks the chain home, a burn. This is the
 * one that is nobody's.
 *
 * ## A hit moves the machine
 *
 * Because what happened is that somebody found the way in. That takes every
 * OTHER door into the same machine with it, which is correct: they were all
 * doors into a box that has moved.
 *
 * ## Nightly, and the usual stance
 *
 * `market:seed`'s: no stored clock, a missed night is never made up,
 * `withoutOverlapping()`, and a `--dry-run` that decides nothing.
 *
 * ## What is missing, and it is named in TODO.md
 *
 * The player is not TOLD. CYBERSPACE.md wants a notification and the
 * notification centre does not exist — and unlike a cancelled run, there is no
 * screen they are about to land on that could carry it. So a backdoor simply
 * stops being on the map, which is worse than the design asks for and is
 * logged here until there is somewhere to send it.
 */
class AgeBackdoors extends Command
{
    protected $signature = 'cyberspace:age-backdoors
                            {--dry-run : Report what would be rolled and write nothing}
                            {--quiet-when-idle : Print nothing unless something was found}';

    protected $description = 'Roll every backdoor against its own age, and move the machines that were found';

    public function handle(Backdoors $backdoors): int
    {
        $held = Backdoor::query()->count();

        if ((bool) $this->option('dry-run')) {
            $this->info($held === 0
                ? 'Nobody holds a backdoor.'
                : "{$held} backdoor(s) would be rolled. Nothing was written.");

            return self::SUCCESS;
        }

        $lost = $backdoors->age();

        if ($lost === [] && (bool) $this->option('quiet-when-idle')) {
            return self::SUCCESS;
        }

        foreach ($lost as $entry) {
            // Logged because there is nowhere else to send it yet. When the
            // notification centre lands, this is the line that becomes one.
            Log::info('a backdoor was discovered', [
                'character_id' => $entry['backdoor']->character_id,
                'system_id' => $entry['backdoor']->system_id,
                'kind' => $entry['backdoor']->kind->value,
                'machine_moved' => $entry['moved'],
            ]);
        }

        $this->info(sprintf('%d backdoor(s) rolled, %d found.', $held, count($lost)));

        return self::SUCCESS;
    }
}
