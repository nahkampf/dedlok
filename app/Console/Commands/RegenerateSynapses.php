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
 * ## The fast trickle is paid for
 *
 * `synaptic_regen` is zero by default: a point a minute is bought with a rank
 * 16 skill — Neuroplasticity, a point a minute per level — or a dose.
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
 * ## And everybody mends, slowly
 *
 * On top of that, a point every `synaptic_mend_minutes` (two hours) for
 * everybody, trained or not. Still no clock: the point lands on the minute
 * whose number since the epoch divides by the character's interval, so the
 * two-hourly marks are the same for everybody on the same interval, and a
 * minute the scheduler missed is a point missed — this command's stance.
 *
 * ## It heals from zero
 *
 * Deliberately. A character on the floor with Neuroplasticity trained comes
 * back on their own, slowly — which is what the skill is for, and the only
 * route out of zero that exists until healing is designed.
 *
 * ## And hit points, by the hour
 *
 * `hitpoints_regen` is a rate in points per HOUR, zero by default. Each run credits the share of it that belongs to
 * THIS minute: `floor(rate × (m + 1) / 60) − floor(rate × m / 60)` for m the minute since the epoch. Over any sixty
 * consecutive minutes that is exactly `rate` points, spread as evenly as whole points allow, and it needs no stored
 * remainder — the clock carries it, which is this command's whole stance. A minute the scheduler missed is a share
 * missed. Like the SI trickle it heals from zero.
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

    protected $description = 'Credit a minute of Synaptic Integrity and hit point regeneration, and the two-hourly mend everybody gets';

    public function handle(Resolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $healed = 0;
        $points = 0;
        $hpHealed = 0;
        $hpPoints = 0;
        $minute = intdiv(now()->getTimestamp(), 60);

        // Nobody in a fight mends: its clock stops with the player's, or waiting out a fight would be a way to heal in it.
        Character::query()->whereNull('current_fight_id')
            // The clock stops while an encounter is open on the grid (EncounterPause).
            ->where(static fn ($q) => $q->whereNull('current_encounter_id')->orWhereNull('grid_x'))
            ->chunkById(200, function ($characters) use ($resolver, $dryRun, $minute, &$healed, &$points, &$hpHealed, &$hpPoints): void {
                foreach ($characters as $character) {
                    // Hit points: this minute's share of an hourly rate (see the class notes).
                    $perHour = $resolver->resolve('hitpoints_regen', 0.0, $character->modifiers())->toInt();
                    $share = intdiv($perHour * ($minute + 1), 60) - intdiv($perHour * $minute, 60);

                    if ($share > 0) {
                        $gained = $dryRun
                            ? min($share, max(0, $character->maximumFor('current_hitpoints') - (int) $character->current_hitpoints))
                            : $character->recover('current_hitpoints', $share);

                        if ($gained > 0) {
                            $hpHealed++;
                            $hpPoints += $gained;
                        }
                    }

                    $rate = $resolver->resolve('synaptic_regen', 0.0, $character->modifiers())->toInt();

                    // The slow floor: through the Sheet, because its base lives in
                    // `stats.bases`, which only the Sheet walks.
                    if ($minute % max(1, $character->stat('synaptic_mend_minutes')->toInt()) === 0) {
                        $rate++;
                    }

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

        $parts = [];

        if ($healed > 0) {
            $parts[] = sprintf('%d character(s) recovered %d point(s) of Synaptic Integrity', $healed, $points);
        }

        if ($hpHealed > 0) {
            $parts[] = sprintf('%d character(s) recovered %d point(s) of hit points', $hpHealed, $hpPoints);
        }

        // Nothing healed reads as the SI sentence it always did, with its zeroes.
        $summary = $parts === [] ? sprintf('%d character(s) recovered %d point(s) of Synaptic Integrity', 0, 0) : implode('; ', $parts);

        if ($dryRun) {
            $this->info("Dry run: {$summary}. Nothing was written.");

            return self::SUCCESS;
        }

        if ($healed === 0 && $hpHealed === 0) {
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
