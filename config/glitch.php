<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Which lines of a run's log shake the page
|--------------------------------------------------------------------------
|
| The key a log line was written with => `tick` (a failure of yours), `hit` (ICE got through) or `crash`. A new line carrying one
| plays it when the run screen shows it (`data-fx`, read by `UI.glitch`'s caller in ui-kit.js). Anything unlisted is quiet.
| Everything outside a run's log (a fight, a hunt, jacking in and out, going down) calls `App\Support\Glitch::flash()` where it happens.
|
*/
$run = 'cyberspace.run.log.';

return [
    'log' => [
        // ICE getting through.
        $run.'ice_hits' => 'hit',
        $run.'virus_infects' => 'hit',
        $run.'virus_hits' => 'hit',
        // The way out torn: Crash Jackout (also flashed by the Simulator, for the page that ends the run).
        $run.'tree.jackout' => 'crash',
        // Your own failures.
        $run.'attack_fails' => 'tick',
        $run.'egress_held' => 'tick',
        $run.'extract_fails' => 'tick',
        $run.'install_fails' => 'tick',
        $run.'leech_fails' => 'tick',
        $run.'burn_fails' => 'tick',
        $run.'tree.noise' => 'tick',
        $run.'tree.firewall_holds' => 'tick',
        $run.'tree.hub_holds' => 'tick',
        $run.'tree.shutdown_fails' => 'tick',
        $run.'tree.wipe_fails' => 'tick',
        $run.'tree.loopback_fails' => 'tick',
        $run.'tree.trap.disarm_fails' => 'tick',
    ],

    // The failures that also play failure.mp3 when their line is new on the run screen (`data-sound`, ui-kit.js): a firewall
    // breach, an extract, an egress, a disarm, a loopback or an install that did not take.
    'failure_sound' => [
        $run.'tree.firewall_holds',
        $run.'extract_fails',
        $run.'egress_held',
        $run.'tree.trap.disarm_fails',
        $run.'tree.loopback_fails',
        $run.'install_fails',
    ],

    // And the successes that play success.mp3: a firewall breached, a trap disarmed, a file pulled out, a backdoor installed or
    // planted (`install.<kind>` lines too, see Glitch::playsSuccess()), a system burned, an egress, a loopback wired.
    'success_sound' => [
        $run.'tree.firewall_opens',
        $run.'tree.trap.disarmed',
        $run.'extract',
        $run.'planted',
        $run.'burn',
        $run.'egress',
        $run.'tree.loopback',
    ],

    // The lines that play noise_made.mp3: the player made noise (a failed stealth roll, a noisy action, a trap's alarm).
    'noise_sound' => [
        $run.'tree.noise',
        $run.'tree.trap.alarm',
    ],
];
