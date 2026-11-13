<?php

declare(strict_types=1);

namespace App\Cyberspace\Tree;

/**
 * What a node in a run tree is (docs/RUN_TREE.md). Step 1's six; relays, security hubs, maintenance ports, traps and the
 * rest arrive with later phases.
 */
enum NodeKind: string
{
    /** Where a run arrives, and the one exit until maintenance ports exist. */
    case Entry = 'entry';

    /** Where paths split. Never where they merge. */
    case Switch = 'switch';

    /** An empty node that only passes you through. Later phases turn most of them into real subsystems. */
    case Conduit = 'conduit';

    /** An OS roll to pass, open for the rest of the run once it lands. */
    case Firewall = 'firewall';

    /** Extra loot off the path: about a third of a core's roll, no lock. */
    case Vault = 'vault';

    /** What the run is for. A firewall too, and where the system's ICE stands. */
    case Core = 'core';
}
