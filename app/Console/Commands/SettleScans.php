<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cyberspace\Probe;
use App\Models\Character;
use App\Models\SystemScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Resolve every read that has matured, without waiting for its owner to look.
 *
 * ## What this is NOT for
 *
 * It is not what makes a read finish. `Probe::settle()` walks the clock forward
 * on its own, so a player who scans a machine, closes the browser for a day and
 * comes back gets the WHOLE read resolved on arrival, each pass timed from when
 * the one before it matured. That was a real bug once — a read advanced exactly
 * one pass per visit however long its owner had been away — and it is fixed
 * where it belongs, in the settling. **Do not delete the catch-up because this
 * command exists; it is what makes this command's absence survivable.**
 *
 * ## What it IS for
 *
 * Making the row true between visits. Lazy settling computes the answer when
 * somebody asks, which is fine while the only thing that ever asks is the
 * player who owns the file — and stops being fine the moment anything else
 * reads a scan. Two things on the roadmap do: a notification saying a read
 * finished can only be raised by something that NOTICED it finish, and lazy
 * settling never notices; and a scan is meant to be sellable, which makes one
 * player's unsettled read another player's goods.
 *
 * Five minutes, because nothing here is urgent: the owner sees the same answer
 * whenever they look, and the only reader who benefits from it being sooner is
 * one that does not exist yet. The training heartbeat runs every minute because
 * a deposit is credited FROM a clock; this resolves a thing that already
 * happened.
 *
 * No stored clock and a missed pass is never made up — `market:seed`'s
 * precedent — because there is nothing to make up: a read that matured while
 * this was not running is settled by the next sweep, or by its owner, whichever
 * arrives first. `withoutOverlapping()` all the same, since two sweeps landing
 * on one read would both try to begin its next pass.
 *
 * ponytail: one character load and one resolver pass per character with a
 * matured read, because `passesFor()` and the reading value are per-character
 * stats. Nothing at this scale. If it ever matters, the fix is to settle in
 * chunks keyed by character rather than to make the stats cheaper.
 */
class SettleScans extends Command
{
    protected $signature = 'cyberspace:settle
                            {--dry-run : Report what has matured and settle nothing}
                            {--quiet-when-idle : Print nothing unless something happened}';

    protected $description = 'Resolve every scan pass that has matured, for every character';

    public function handle(Probe $probe): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // The characters with something to settle, rather than every character
        // and a question each. A read points at its owner, so this is one query
        // however many of them are standing still.
        $characters = Character::query()
            ->whereIn('id', SystemScan::query()
                ->whereNotNull('pass_ready_at')
                ->where('pass_ready_at', '<=', now())
                ->select('character_id'))
            ->get();

        if ($dryRun) {
            $this->info($characters->isEmpty()
                ? 'Nothing has matured.'
                : "{$characters->count()} character(s) have a matured read. Nothing was settled.");

            return self::SUCCESS;
        }

        $passes = 0;
        $found = 0;
        $lost = 0;

        foreach ($characters as $character) {
            foreach ($probe->settleAll($character) as $result) {
                $passes++;
                $found += count($result->found);
                $lost += $result->voided ? 1 : 0;
            }
        }

        if ($passes === 0) {
            if (! $this->option('quiet-when-idle')) {
                $this->info('Nothing had matured.');
            }

            return self::SUCCESS;
        }

        $summary = sprintf(
            '%d pass(es) settled for %d character(s): %d fact(s) found, %d read(s) lost',
            $passes,
            $characters->count(),
            $found,
            $lost,
        );

        // Logged like the market's pass and the restock, and for the reason
        // they are: this runs while nobody is watching, and a sweep that
        // quietly stopped looks exactly like a world where nobody is scanning.
        Log::info("cyberspace:settle — {$summary}");
        $this->info($summary);

        return self::SUCCESS;
    }
}
