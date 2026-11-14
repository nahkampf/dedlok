<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

/**
 * What a node in a run tree is (docs/RUN_TREE.md). Step 1's six and phase 2's maintenance port, security hub and internal
 * relay; phase 3's trap.
 */
enum NodeKind: string
{
    /** Where a run arrives, and an exit. */
    case Entry = 'entry';

    /** Where paths split. Never where they merge. */
    case Switch = 'switch';

    /** An empty node that only passes you through. Later phases turn most of them into real subsystems. */
    case Conduit = 'conduit';

    /** An OS roll to pass, open for the rest of the run once it lands. */
    case Firewall = 'firewall';

    /** Extra loot off the path: about a third of a core's roll, no lock. */
    case Vault = 'vault';

    /** A maintenance port: a second exit, a dead end off the rich path. Egress here rolls against the grade below's difficulty. */
    case Port = 'port';

    /**
     * A security hub, where the quiet and rich paths meet. Stepping on it is loud on a failed stealth roll; subverted (the
     * OS roll), no alert can go off for the party's run. On a system with no IDS it is a conduit.
     */
    case Hub = 'hub';

    /** An internal relay, on the quiet path: a Loopback held on it stalls the party's traces. */
    case Relay = 'relay';

    /** A trap (its effect on the node, `trap`): it reads as a conduit until found, and goes off when stepped on. */
    case Trap = 'trap';

    /** What the run is for. A firewall too, and where the system's ICE stands. */
    case Core = 'core';
}
