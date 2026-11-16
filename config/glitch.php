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
];
