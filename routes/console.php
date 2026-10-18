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
 * discovery, siphon payouts, heat decay. The first of those is below; the
 * other two are still unbuilt, and lockdown expiry has its own cadence
 * further down because a window measured in hours cannot wait for 04:00.
 */
Schedule::command('cyberspace:restock')
    ->dailyAt('04:10')
    ->withoutOverlapping();

/*
 * Siphons pay out. The safe half of a backdoor portfolio: it arrives whether
 * or not its holder went anywhere, and nothing takes it but the ageing roll
 * below — which runs AFTER this, so a backdoor discovered tonight still paid
 * tonight.
 */
/*
 * Skills are sold by shops and not by the market, so the shop that sells them all is kept in step with the
 * skills that are for sale: new ones are listed and every price follows the imprint's list price.
 */
Schedule::command('city:stock-skills --quiet-when-idle')
    ->dailyAt('04:20')
    ->withoutOverlapping();

Schedule::command('cyberspace:siphons --quiet-when-idle')
    ->dailyAt('04:15')
    ->withoutOverlapping();

/*
 * Backdoor discovery, and the only thing that takes one from somebody who did
 * nothing wrong. Odds rise with age and with nothing else, so a backdoor is a
 * wasting asset rather than something that can be held carefully enough to
 * keep. Ten past four, after the nodes restock, because a machine that moves
 * tonight should have been stocked as itself first.
 */
Schedule::command('cyberspace:age-backdoors --quiet-when-idle')
    ->dailyAt('04:25')
    ->withoutOverlapping();

// A fortress is kept inside its Leechers' totals every five minutes; one offline past the grace period is deleted.
Schedule::command('fortress:settle --quiet-when-idle')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Leechers are found on the same nightly cadence as backdoors.
Schedule::command('cyberspace:find-leeches --quiet-when-idle')
    ->dailyAt('04:30')
    ->withoutOverlapping();

/*
 * Word goes out about somebody, which is the first thing heat has ever done.
 *
 * FIVE, after the four-o'clock housekeeping rather than inside it: a trace
 * that completed in the night has already put its point on by then, so the
 * number being rolled is the number the night ended with. Every character
 * carrying any heat is rolled once — the uncertainty floor under five points,
 * certain at a hundred — and one that carries none is not rolled at all.
 *
 * No stored clock and a missed night is never made up, like every other
 * housekeeping job here. `withoutOverlapping()` because two copies would be
 * two decisions about the same person on the same night.
 */
Schedule::command('characters:bounties --quiet-when-idle')
    ->dailyAt('05:00')
    ->withoutOverlapping();

/*
 * And then it comes off again, slowly.
 *
 * AFTER THE BOUNTY ROLL, ten minutes behind it, and the order is the point: a
 * character is rolled on the heat the night ended with — what they did is what
 * they answer for — and the cooling is the morning after rather than a
 * discount applied before anybody looked. The siphons and the ageing roll are
 * ordered against each other for the same reason.
 *
 * Half a point a night by default, and the rate is a stat, so the first skill
 * that speeds it up changes nothing here.
 */
Schedule::command('characters:cool --quiet-when-idle')
    ->cron((string) config('cadence.heat_cool'))
    ->withoutOverlapping();

/*
 * And what the organisations think of you fades the same way.
 *
 * TWENTY PAST FIVE, after the heat has come off, because the two are the same
 * kind of thing — a night passing — and reading the log in order should read
 * as one morning rather than two. Half a percent of what is carried, toward
 * neutral from both directions, so a grudge and a friendship both cool at the
 * rate they are worth.
 *
 * Proportional and not flat: at the size of what earns standing a flat rate
 * would erase a day's work at the bottom of the ladder and cost nothing at the
 * top. Every move writes a ledger entry, so `standing:verify` still adds up.
 */
Schedule::command('standing:drift --quiet-when-idle')
    ->dailyAt('05:20')
    ->withoutOverlapping();

/*
 * A day off both drug figures, and a sweep of what has worn off.
 *
 * Tolerance falls faster than addiction, so a break gives the drug its
 * strength back long before it gives the need up. Twenty past five, after the
 * heat has been settled, because nothing here depends on that order and the
 * housekeeping hour may as well stay one hour.
 */
Schedule::command('drugs:metabolise --quiet-when-idle')
    ->cron((string) config('cadence.drugs_metabolise'))
    ->withoutOverlapping();

/*
 * And the withdrawal rows, kept true between visits.
 *
 * NOT LAZY, and it is the same reason the lockdown windows are swept: taking a
 * dose clears withdrawal on the spot, but a dose WEARING OFF has nobody
 * pressing anything. The row that says somebody is going without has to appear
 * at the minute it becomes true rather than the next time they happen to look.
 */
Schedule::command('drugs:withdraw --quiet-when-idle')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('quests:run')
    ->cron((string) config('cadence.quest_runner'))
    ->withoutOverlapping();

Schedule::command('market:seed')
    ->cron((string) config('cadence.market_seed'))
    ->withoutOverlapping();

/*
 * Resolving reads that have matured, for people who are not looking.
 *
 * NOT WHAT MAKES A READ FINISH. `Probe::settle()` catches up with the clock by
 * itself, so a player who walks away for a day gets the whole read resolved the
 * moment they come back. This makes the row true in between, which matters to
 * every reader that is not the owner — a notification saying a read finished
 * can only come from something that NOTICED, and a sellable scan is one
 * player's file in another player's hands.
 *
 * Five minutes rather than the heartbeat's minute: a deposit is credited FROM a
 * clock and wants to be current, and this resolves something that has already
 * happened. Nobody is waiting on it.
 */
/*
 * Decryption cycles. Lazy settling does the real work whenever somebody looks; this makes a file that opens while
 * nobody is watching say so, and starts the next file's clock. Cheap: it only visits characters with a queue.
 */
Schedule::command('decryption:settle --quiet-when-idle')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Scripts are spent by the clock (`programs.lifetime_seconds`). Whoever looks at their running programs
 * sees an expired one gone already; this keeps the table true for the character nobody is looking at.
 */
Schedule::command('programs:expire')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Builds (CRAFTING.md) advance a minute at a time while the equipped deck is ready; this is that minute, and delivers the finished.
 */
Schedule::command('crafting:settle --quiet-when-idle')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Healing by waiting: the two-hourly mend everybody gets, and the per-minute
 * trickle Neuroplasticity and doses grant.
 *
 * Every minute, because the trickle IS per minute, and the mend lands on the
 * minute its interval divides.
 *
 * No stored clock and a missed minute is never made up: market:seed's stance
 * rather than the training heartbeat's, because a training deposit is credited
 * from a clock the player is watching and this is a trickle nobody is counting.
 */
Schedule::command('characters:regenerate --quiet-when-idle')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('cyberspace:settle --quiet-when-idle')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Lockdown expiry, and the one piece of cyberspace that CANNOT be lazy.
 *
 * Everything else settles when somebody looks — a matured scan pass, a training
 * deposit, getting up off the floor. This cannot: two machines relocating into
 * the same free position is a race, and the sweep is what serialises it. A
 * lockdown is also 2-6 hours long, so the machine has to open on its own clock
 * rather than on the next page load of a player who has gone to bed.
 *
 * Five minutes, the same cadence as the scan sweep: a window measured in hours
 * does not need a finer one, and a machine that opens four minutes late is a
 * machine nobody was standing next to.
 */
Schedule::command('cyberspace:lockdowns --quiet-when-idle')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * A job's target that a lockdown just relocated, replaced straight away.
 *
 * Retargeting is otherwise lazy (the Tasks and exchange screens), so a runner who
 * stays away learns nothing until the deadline fails the job. Same cadence as the
 * lockdown sweep, declared after it so the new address exists when this looks.
 */
Schedule::command('missions:retarget')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Scan files that have gone stale, and the one message they are owed.
 *
 * A file's 48 hours are read when something asks — the card, a buyer, a
 * hand-in — and that stays lazy. What cannot be lazy is TELLING its owner: a
 * file dies at an arbitrary minute with nobody looking at it, and the
 * alternative is finding out by opening a card there was no reason to open.
 *
 * Hourly rather than every five minutes, because the clock being announced is
 * two days long: news forty minutes late is the same news. Nothing is deleted
 * — destroying a worthless file is the player's call, from the card.
 */
Schedule::command('scans:stale --quiet-when-idle')
    ->hourly()
    ->withoutOverlapping();

/*
 * Work whose clock ran out.
 *
 * The penalty has to land whether or not the player ever comes back: a job
 * settled on the next visit would let somebody take work, never return, and
 * keep the standing they were about to lose. The same reason the lockdown
 * sweep cannot be lazy, and the same cadence — a window measured in hours does
 * not need a finer one.
 *
 * A job whose target has gone VOIDS instead of failing, even here. The clock
 * running out on something that had become impossible is still not the
 * runner's fault.
 */
Schedule::command('missions:expire --quiet-when-idle')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * The City's rows, every five minutes: a travel or exploring pass that matured
 * while nobody was looking. Lazy settling is what makes an activity finish when
 * somebody IS looking; this makes the rows true in between and tells the player
 * what they missed — the same arrangement as `cyberspace:settle`.
 */
Schedule::command('city:settle')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// A supporter whose month has run out stops being one. Reads already know; this keeps the stored flag true.
Schedule::command('supporters:expire')->hourly();
