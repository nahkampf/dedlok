<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The training heartbeat.
 *
 * withoutOverlapping because a slow run must not have a second one start on
 * top of it — the deposit is transactional, but two overlapping passes would
 * still fight over the same rows.
 *
 * A missed minute is harmless: each character advances from its own stored
 * clock, so the next run credits whatever the last one did not.
 *
 * There is deliberately no output redirection here, and none is possible: a
 * scheduled command's stdout is a pipe Symfony opens to capture it, so both
 * /dev/stdout and /proc/1/fd/1 lead somewhere other than the container log —
 * the first to the capture pipe, the second to a permission error, because the
 * command runs as www-data and PID 1 is cron as root. The deposit therefore
 * reports through Log:: instead, and what reaches `docker compose logs
 * scheduler` is the scheduler's own framing and any failure it raises.
 *
 * runInBackground() is also deliberately absent. It detaches the command, which
 * costs the exit status without buying anything: it exists to parallelise
 * several commands that come due together, and there is only ever this one.
 */
Schedule::command('training:deposit --quiet-when-idle')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * The market's delivery run, every eight hours.
 *
 * A cron expression rather than a frequency helper because the framework does
 * not have an everyEightHours(): its helpers stop at six. The expression is
 * the same schedule the helper would have built — midnight, 08:00 and 16:00.
 *
 * withoutOverlapping() matters more here than on the heartbeat. The training
 * deposit is idempotent inside a minute; a restock is not idempotent at all,
 * because each pass rolls once per item against its rarity. Two overlapping
 * passes are two deliveries, and the rare things are exactly the ones that
 * would get them.
 *
 * A missed pass is not made up, and nothing tries to: there is no stored clock
 * to catch up from, and a shelf that goes unstocked for another eight hours is
 * indistinguishable from a run of failed rolls.
 */
/*
 * The world's slow metabolism, nightly.
 *
 * Emptying a node is temporary, and this is what makes it so. Same shape as the
 * market's pass and for the same reasons: no stored clock, a missed night is
 * never made up, and withoutOverlapping() because a second copy of a pass that
 * writes thousands of rows is not something to find out about later.
 *
 * CYBERSPACE.md gives housekeeping three more nightly jobs — backdoor
 * discovery, siphon payouts, heat decay — and a frequent one for lockdown
 * expiry. None of those exist yet; this is the first of them.
 */
Schedule::command('cyberspace:restock')
    ->dailyAt('04:10')
    ->withoutOverlapping();

Schedule::command('market:seed')
    ->cron('0 */8 * * *')
    ->withoutOverlapping();
