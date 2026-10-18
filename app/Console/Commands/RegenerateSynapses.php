<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Characters\Fatigue;
use App\Characters\Fullness;
use App\Characters\Mood;
use App\Models\Character;
use App\Stats\Resolver;
use App\Support\Points;
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
 * `hitpoints_regen` is a rate in points per HOUR (to two decimals), zero by default. Each run credits the share of it that belongs to
 * THIS minute: `floor(rate × (m + 1) / 60) − floor(rate × m / 60)` for m the minute since the epoch. Over any sixty
 * consecutive minutes that is exactly `rate` points, spread as evenly as whole points allow, and it needs no stored
 * remainder — the clock carries it, which is this command's whole stance. A minute the scheduler missed is a share
 * missed. Like the SI trickle it heals from zero.
 *
 * ## And fatigue comes off
 *
 * `fatigue_recovery` points a MINUTE (half a point by default), the same stance: no stored clock, a missed minute missed.
 * Skipped in a fight with everything else here. A character already rested costs nothing.
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

    protected $description = 'Credit a minute of Synaptic Integrity and hit point regeneration, the two-hourly mend everybody gets, and take a minute of fatigue off the tired';

    /**
     * This minute's share of an hourly rate, in hundredths of a point so a fraction of a point an hour is exact:
     * `floor(c × (m + 1) / 60) − floor(c × m / 60)` for c the rate in hundredths. Over any sixty minutes it is exactly
     * `rate`, with no stored remainder.
     */
    private static function share(float|int $perHour, int $minute): float|int
    {
        $hundredths = (int) round($perHour * 100);

        return Points::of((intdiv($hundredths * ($minute + 1), 60) - intdiv($hundredths * $minute, 60)) / 100);
    }

    public function handle(Resolver $resolver, Fatigue $fatigue, Fullness $fullness, Mood $mood): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $healed = 0;
        $points = 0;
        $hpHealed = 0;
        $hpPoints = 0;
        $rested = 0;
        $restedPoints = 0;
        $minute = intdiv(now()->getTimestamp(), 60);

        // Nobody in a fight mends: its clock stops with the player's, or waiting out a fight would be a way to heal in it.
        Character::query()->whereNull('current_fight_id')
            // The clock stops while an encounter is open on the grid (EncounterPause).
            ->where(static fn ($q) => $q->whereNull('current_encounter_id')->orWhereNull('grid_x'))
            ->chunkById(200, function ($characters) use ($resolver, $fatigue, $fullness, $mood, $dryRun, $minute, &$healed, &$points, &$hpHealed, &$hpPoints, &$rested, &$restedPoints): void {
                foreach ($characters as $character) {
                    // Hit points: this minute's share of an hourly rate (see the class notes).
                    $perHour = $resolver->resolve('hitpoints_regen', 0.0, $character->modifiers())->value;
                    $share = self::share($perHour, $minute);

                    if ($share > 0) {
                        $gained = $dryRun
                            ? Points::of(min($share, max(0, $character->maximumFor('current_hitpoints') - $character->current_hitpoints)))
                            : $character->recover('current_hitpoints', $share);

                        if ($gained > 0) {
                            $hpHealed++;
                            $hpPoints = Points::of($hpPoints + $gained);
                        }
                    }

                    // Fatigue comes DOWN on its own, `fatigue_recovery` a minute (already in hundredths). Asked only of the
                    // tired, so a rested character costs no sheet.
                    if ($character->current_fatigue > 0) {
                        $recovery = $character->stat('fatigue_recovery')->value;
                        $eased = $dryRun
                            ? Points::of(min($recovery, $character->current_fatigue))
                            : $fatigue->ease($character, $recovery);

                        // Even with nothing eased: a maximum that moved can have changed what they are.
                        if (! $dryRun) {
                            $fatigue->sync($character);
                        }

                        if ($eased > 0) {
                            $rested++;
                            $restedPoints = Points::of($restedPoints + $eased);
                        }
                    }

                    // Hunger comes back on its own: fullness drains a little each minute.
                    if (! $dryRun && $character->current_fullness > 0) {
                        $fullness->drain($character, (float) config('fatigue.fullness.drain_per_minute'));
                    }

                    if (! $dryRun && (float) $character->mood !== 0.0) {
                        $mood->drift($character);
                    }

                    $rate = $resolver->resolve('synaptic_regen', 0.0, $character->modifiers())->value;

                    // The slow floor: through the Sheet, because its base lives in
                    // `stats.bases`, which only the Sheet walks.
                    if ($minute % max(1, $character->stat('synaptic_mend_minutes')->toInt()) === 0) {
                        $rate += 1;
                    }

                    if ($rate <= 0) {
                        continue;
                    }

                    // Asked before writing so a character already at their ceiling
                    // costs nothing — which is most of them, most of the time.
                    $restored = $dryRun
                        ? Points::of(min($rate, max(0, $character->maximumFor('current_synaptic_integrity') - $character->current_synaptic_integrity)))
                        : $character->recover('current_synaptic_integrity', $rate);

                    if ($restored > 0) {
                        $healed++;
                        $points = Points::of($points + $restored);
                    }
                }
            });

        $parts = [];

        if ($healed > 0) {
            $parts[] = sprintf('%d character(s) recovered %s point(s) of Synaptic Integrity', $healed, Points::format($points));
        }

        if ($hpHealed > 0) {
            $parts[] = sprintf('%d character(s) recovered %s point(s) of hit points', $hpHealed, Points::format($hpPoints));
        }

        if ($rested > 0) {
            $parts[] = sprintf('%d character(s) shed %s point(s) of fatigue', $rested, Points::format($restedPoints));
        }

        // Nothing healed reads as the SI sentence it always did, with its zeroes.
        $summary = $parts === [] ? sprintf('%d character(s) recovered %d point(s) of Synaptic Integrity', 0, 0) : implode('; ', $parts);

        if ($dryRun) {
            $this->info("Dry run: {$summary}. Nothing was written.");

            return self::SUCCESS;
        }

        if ($healed === 0 && $hpHealed === 0 && $rested === 0) {
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
