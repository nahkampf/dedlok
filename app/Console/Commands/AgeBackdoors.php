<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Backdoors;
use App\Models\Backdoor;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Support\Text;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Somebody finds the way in, and the machine does not stay where it was.
 *
 * ## The only thing that takes a backdoor from somebody who did nothing wrong
 *
 * A backdoor keeps a system open — no lockdown, no relocation, a permanent
 * address in a world where nothing else has one. docs/CYBERSPACE.md is plain that
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
 * ## The player is told, which is the whole reason this is not silent
 *
 * There is no screen they are about to land on — unlike a cancelled run, this
 * happens in the night — so the news goes to the inbox, which is what the
 * inbox is for. The log line stays beside it: one is for the player and one is
 * for whoever is reading the nightly job's output, and they are not the same
 * audience.
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
            $backdoor = $entry['backdoor'];
            $character = $backdoor->character;

            // A machine that was already gone leaves nothing to name, and a
            // backdoor with no character is a fixture rather than a state the
            // game reaches. Neither is an error: the roll still happened.
            if ($character !== null && $backdoor->system !== null) {
                // The owner now knows somebody had been living in it. Cheaper
                // than a raid and dearer than being seen once: it is evidence
                // that you were inside and stayed.
                $owner = $backdoor->system->owner;
                $landed = (new StandingLedger)->record($character, $owner, StandingReason::BackdoorFound);

                // The message says what it cost, when it cost anything.
                (new Notifier)->send($character, Category::Cyberspace, 'backdoor_found', [
                    'system' => $backdoor->system->displayName(),
                    'standing' => $landed === null || $landed >= 0.0 ? '' : ' '.Text::get('notifications.standing_lost', [
                        'amount' => rtrim(rtrim(number_format(abs($landed), 3), '0'), '.'),
                        'entity' => $owner->name,
                    ]),
                ]);
            }

            Log::info('a backdoor was discovered', [
                'character_id' => $backdoor->character_id,
                'system_id' => $backdoor->system_id,
                'kind' => $backdoor->kind->value,
                'system_moved' => $entry['moved'],
            ]);
        }

        $this->info(sprintf('%d backdoor(s) rolled, %d found.', $held, count($lost)));

        return self::SUCCESS;
    }
}
