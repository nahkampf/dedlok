<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use App\Stats\Resolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Credit a minute of Synaptic Integrity to anybody who has trained for it.
 *
 * ## Nobody gets this for free
 *
 * `synaptic_regen` is zero by default and that is the rule rather than a
 * starting value: nothing in this game heals by waiting. The one exception is
 * bought with a rank 16 skill — Neuroplasticity, a point a minute per level —
 * so on a world of untrained characters this command resolves a handful of
 * stats and writes nothing at all.
 *
 * ## No stored clock, and a missed minute is never made up
 *
 * `market:seed`'s stance rather than `training:deposit`'s, and the difference
 * is what each is FOR. A training deposit is credited from a clock the player
 * is watching, so it has to catch up exactly. This is a trickle nobody is
 * counting, and a minute lost to a scheduler restart is a point — so the
 * command credits one minute's worth per run and keeps no state at all.
 *
 * That also means **running it by hand credits a minute**, the way running
 * `market:seed` by hand makes a delivery. `withoutOverlapping()` keeps two
 * scheduled passes from landing together; nothing defends against somebody
 * choosing to run it twice, and nothing should.
 *
 * ## It heals from zero
 *
 * Deliberately. A character on the floor with Neuroplasticity trained comes
 * back on their own, slowly — which is what the skill is for, and the only
 * route out of zero that exists until healing is designed.
 *
 * ponytail: resolves a sheet per character per minute, and skips on a zero.
 * Nothing at this scale. If it ever matters the fix is to narrow the query to
 * characters who could possibly regenerate — which cannot be asked in SQL
 * today, because a maximum is derived and a modifier can come from anywhere.
 */
class RegenerateSynapses extends Command
{
    protected $signature = 'characters:regenerate
                            {--dry-run : Work out what would be credited and write nothing}
                            {--quiet-when-idle : Print nothing unless something healed}';

    protected $description = 'Credit a minute of Synaptic Integrity regeneration to every character who has trained it';

    public function handle(Resolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $healed = 0;
        $points = 0;

        Character::query()->chunkById(200, function ($characters) use ($resolver, $dryRun, &$healed, &$points): void {
            foreach ($characters as $character) {
                $rate = $resolver->resolve('synaptic_regen', 0.0, $character->modifiers())->toInt();

                if ($rate <= 0) {
                    continue;
                }

                // Asked before writing so a character already at their ceiling
                // costs nothing — which is most of them, most of the time.
                $restored = $dryRun
                    ? min($rate, max(0, $character->maximumFor('current_synaptic_integrity') - (int) $character->current_synaptic_integrity))
                    : $character->recover('current_synaptic_integrity', $rate);

                if ($restored > 0) {
                    $healed++;
                    $points += $restored;
                }
            }
        });

        $summary = sprintf('%d character(s) recovered %d point(s) of Synaptic Integrity', $healed, $points);

        if ($dryRun) {
            $this->info("Dry run: {$summary}. Nothing was written.");

            return self::SUCCESS;
        }

        if ($healed === 0) {
            if (! $this->option('quiet-when-idle')) {
                $this->info('Nobody had anything to recover.');
            }

            return self::SUCCESS;
        }

        // Logged like every other pass that runs while nobody is watching.
        Log::info("characters:regenerate — {$summary}");
        $this->info($summary);

        return self::SUCCESS;
    }
}
