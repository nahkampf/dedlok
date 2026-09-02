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
