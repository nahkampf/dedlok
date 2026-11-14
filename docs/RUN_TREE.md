# The run tree (R0)

Decisions for building R0 · *The run as a flowchart* from the *Deeper Into the Grid* proposal. Where this file and the
proposal disagree, this file wins. Decided with the user, 2026-11-13.

## Step 1: scope

- **Phase 1 plus firewalls on the path.** A tree generated from a seed on the system, moving along edges, fog of war and
  Nmap, the node types entry, switch, conduit, firewall, data vault and core, the system's ICE standing on the core, and the scan revealing
  the tree. Relays, security hubs, maintenance ports, alarm states, traps, sentries and Cordon wait for later steps.
- **Behind a feature flag**, off by default: off, runs play exactly as today.
- **Entity systems on the main grid only.** Subnet systems, gateways (infiltrate runs) and Data Fortresses keep today's
  single-room run.
- **Conduit**: a plain empty node that only passes you through, filling paths until later phases bring real subsystems.
  (The name is a placeholder.)

## Leaving

- **Egress only at an exit**: the entry, a maintenance port or an Egress Tunnel. In step 1 that means only the entry, so
  a runner deep in the tree walks back. There is no "harder per node of distance" rule.
- **At the entry, egress is today's roll**: Evasion against the grade's difficulty (3–19), plus the awake ICE's level
  only if that ICE can reach you.
- **Crash Jackout arrives in step 1**, as designed: from any node, out for certain, 25–50% of maximum SI as damage
  nothing modifies, two-click confirmation in the kit's modal.
- **Thresholds only warn, everywhere** (with the flag on). They no longer force an egress attempt, at the entry or
  anywhere else.

## ICE, noise, firewalls

- **One ICE per tree: the system's own, standing on the core.** It hits only a runner on its node; walking away ends
  the fight, but it stays awake and still guards the core.
- **Sleeping ICE lets you pass**, as today: detection is what wakes it.
- **Firewalls by grade, none below 3**: about one per path at grades 3–6, up to two on the fast path at 7+. Each is an OS
  roll with the core's table and stays open for the rest of the run once passed.

## Loot

- **The core keeps today's loot** (`system_contents`) unchanged. **Data vaults hold extra loot on top.**

## Parties

- **Each runner moves alone**: their own node, their own move each round.

## Fog, Nmap, scanning

- **Stepping onto a node** shows what it is and the edges leaving it; neighbours show as "?".
- **Nmap** (an action: costs a turn, act-tier noise) reveals the neighbours' types and their edges.
- **Two new scan facts, in order**: *Layout* (the shape: depth, paths, edges, nodes as "?") and then *Nodes* (what
  every node is). **Layout waits for the Grade** (the shape gives the grade away), and Nodes for the Layout, each from
  the pass after: reading a whole tree takes at least three passes.
- **Only on systems that walk a tree.** Anywhere else, and everywhere while the flag is off, neither fact is listed or
  rolled, and a file is complete without them. `system_scans.tree` says how far the read got (0, 1 Layout, 2 Nodes);
  NULL is "no tree", decided when the file is made, so files from before trees read as complete.
- **They read as counts**: Layout as paths and moves to the core, Nodes as firewalls and data vaults. The tree itself is
  drawn from the address, so a file whose system has moved on reads them as unknown.
- **A run starts with what the live scan knows**: no scan, only the entry and its edges; Layout, the whole shape;
  Nodes, everything typed. **In a party, the best scan is what everybody sees** (each run keeps its own, read across
  the party like Nmap reveals).
- **The tree only changes when the system relocates.** Raids, lockdowns and restocks leave it alone.

## Generation

- **Path kinds: fast, quiet, rich.** Safe waits until relays and maintenance ports exist. Grades 1–2: one path; 3–4: two;
  5+: up to three.
- **Paths cross over and meet again**, as in the sample trees: crossovers from grade 3, about 0.5 at grades 3–4 rising
  to about 2.5 at grade 9.
- **Depth as in the samples**: grade 1 is entry → core (one move); the quiet path is about 5 moves at grade 5 and 7 at
  grade 9, the fast path two shorter.
- **Type lean: silos get an extra vault**; compute and relay trees use the base mix for now.
- **The seed is the system's address** (`crc32`), not a column: the address is random, unique and replaced on relocation,
  which is exactly when the tree should change. `App\Cyberspace\Tree\RunTree` generates it on demand and stores nothing.
- **Grade 2's one node is a vault or a conduit**, about half each; always a vault on a silo.
- **Paths by grade**: 1–2 one; 3–4 two (fast and quiet or rich: rich on a silo, quiet on a relay); 5–6 two or three;
  7–9 three.
- **Quiet and rich join at a conduit** before the core when both exist (a security hub in phase 2).
- **A crossover may open a route with no firewall** (about a quarter of trees at grades 5–9; the core is still a
  firewall). Kept on purpose: such a tree is a find, and its scan is worth more.

## Data vaults

- **A vault holds about a third of a core's roll** of the same loot table (creds, items, files), stocked by NodeStocker.
- **Pullable on any purpose**: a pull is an action (act noise; files cost turns as today).
- **Not a raid**: only the core moves the raid counter.
- **Restocked with the core**, in the same pass.

## Backdoors and firewalls

- **A backdoor run starts at the core** (backdoors are core-only until phase 2). The approach roll stays skipped.
- **A firewall opens with Breach** (`RunAction::Breach`, a button standing on it): the OS roll. Landed, it opens (act
  noise) and moving on is the next turn; failed, it holds and it is loud. Until then the nodes past it are not move
  targets (`RunRefusal::FirewallClosed`); back the way you came is always open. (Changed from opening by moving on,
  which offered no action on the firewall itself.)

## Crash Jackout

- **Afterwards the runner stands on the grid**, as after an egress (not ejected).
- **It is silent**: the system locks down only if something had already noticed the runner, as today.
- **Reaching 0 SI through it** puts the runner on the waiting screen as usual, plus **neural damage of 5–10% of maximum
  SI** (an injury through InjuryLedger, as Flatline). No defeat tags.
- **Per runner in a party**: only the one who pulls the plug leaves; their share works as if they had egressed.

## Screens

- **The tree is drawn left to right**, entry on the left and core on the right, as in the samples; it scrolls sideways
  on a phone.
- **Moving is tapping a reachable node on the tree.** Nmap is a button in the action row.
- **The tree also appears** on the run briefing, in the scanner window, in the map's Interact box, and as a *View* modal
  when inspecting a scan file in the stash.
- **Allies are marked on the tree**, and reveals are shared: one runner's Nmap clears the fog for the party.
- **The tree's view in the Interact box is a *View tree* button**, opening the same modal as the stash's scan file.
- **Everywhere outside a run it is a *View tree* button and one modal** (`#tree-view`, fetched from `cyberspace.tree` or
  `inventory.tree`): in the scanner window, the Interact box, the briefing and on a scan file. It appears once the scan
  has read the Layout (nodes as "?"), names them once it has the Nodes. The briefing's is the party's best scan, what
  the run starts with. A scan file whose system has moved on still offers it, and the modal says the tree is gone.

## More decided

- **Owner flavour waits** for later phases (traps, extra ICE).
- **A backdoor holder chooses where to start, the core or the entry**, on the briefing, solo or in a party; fixed once
  the run starts. Their approach roll is skipped either way. Everyone else starts at the entry.
- **Holding is silent everywhere**, and drains the noise bar (below).
- **An idle party runner** (the round timer ran out) follows today's rule where it fits: on the core, attack awake ICE
  (with an icebreaker running), continue a pull, or do the purpose action; anywhere else, Hold. Never an automatic
  egress, since egress needs the entry.
- **Inactive runners are jacked out, with no damage**: a party runner left alone (the others gone) after 5 minutes
  without a turn, and a solo runner after 10. Completed if they had extracted or backdoored, otherwise an ordinary
  end on the grid. (A lockdown or relocation already cancels every run inside, as today.)
- **Exported scan files carry Layout and Nodes**, like every other fact.
- **The flag is `intrusion_runs_v2`** (`FEATURE_INTRUSION_RUNS_V2`).
- **Layout and Nodes roll like the other facts**: same check, same pass length.
- **A data vault has no lock**: standing on it is enough to pull.

## nmap, the Nmapper

- **Nothing ships before the whole run tree is done**: `feature/run-tree` merges only when the flag is ready to turn on,
  because migrations (the nmap grant and shelves) run on production at every deploy.
- **Nmap without an nmapper running is refused**, with no turn spent (the button is greyed anyway).
- **The four shelves sell it at exchanges of grades 1–5.**

- **Nmap needs a running Nmapper**, a utility program. Running it is certain: the cost is the turn and act-tier noise.
- **Variants differ in noise, weight and reach**: an `nmap_reach` column on `programs` (the default is 1). Quieter means
  **less noise added when the Nmap's stealth roll fails**: a modifier on a new Nmap-noise stat in `programs.modifiers`
  (the default nmap adds the full act-tier 6–10). Deck cost as for any program.
- **The default one is called `nmap`.** A background program, 1 / 1 / 1 compute, memory and storage.
- **Given to Deck Jockeys**: by migration into the stash of every existing Deck Jockey; for new ones, the user adds it to
  the Deck Jockey loadout in the archetype editor when the feature ships (a loadout is authored content, not a migration's).
- **Sold at Chrome Palace for 300 creds**, and on the shelves of Lockjaw, Anansi Data Weaving, Ninth Circle Security and
  Joint Signals Command for 2 loyalty at standing 0.

## The noise bar (replaces every IDS roll)

Decided 2026-11-13, replacing the per-action detection chances.

- **Noise accumulates on a bar** that holds up to the system's IDS value (`ids_detection`) in points, drawn as 0–100%.
  Full is the **alert**.
- **Every move or action rolls the acting runner's stealth against the IDS** through CheckRoller. A success adds
  nothing. A failure adds a rolled amount for the tier, the same at every grade:
  - **Move** (along an edge): 3–6.
  - **Act** (Nmap, landing an OS roll on a firewall or the core, each turn of a pull, install, burn, leech, starting a
    script): 6–10.
  - **Loud** (attacking ICE, failing an OS roll on a firewall or the core): 20–30.
- **Noise is fractional, to one decimal**: every amount is rolled as a decimal in its range (a move adds 3.0–6.0,
  for example 4.3), and the bar holds decimals.
- **A Hold drains 2–5**, and is silent. **Once the alert has gone off it stays** to the end of the run.
- **Arrival: each watcher has its own effect.** A failed stealth roll against the IDS adds act-tier noise (6–10). A failed
  roll against the tracer (`tracer_detection`) starts that runner's trace. The ICE is not rolled at arrival. A backdoor
  skips the arrival rolls.
- **The sleeping ICE on the core rolls on each action taken on the core** (stealth against its detection); a failure
  wakes it. A full bar wakes it regardless.
- **The alert** wakes the ICE (and, later, triggers Node Disconnect traps), and starts the trace if it hadn't started.
  **Only the alert costs standing with the owner and locks the system down after the run.** A trace started at arrival
  costs only what a trace costs.
- **Grade 1 is kept as it is**: an IDS of about 7, so one failed arrival nearly fills it, much as today.
- **No IDS, no bar.** The tracer can still start a trace at arrival, the ICE can still wake on a failed roll on the core,
  and a failed core OS roll sets off the alert directly.
- **Without a scan that read the IDS**, the runner sees raw noise points and no bar: how loud they've been, not how close
  the alarm is.

### In a party

- **One bar for the whole party.** Every runner's failed rolls add to it, and each runner's Hold drains it.
- **The alert is the whole party's**: the ICE wakes, and every runner's trace starts if it hadn't.
- **Traces stay per runner**: a failed tracer roll at arrival starts only that runner's trace.

## Step 2 rules

- **Denial floods from the entry**: no walking. Each Flood is a loud noise event (20–30 on a failed stealth roll).
- **A failed egress roll at the entry makes move-tier noise** (3–6).
- **Core actions (Extract, Install, Burn, Leech, Attack) away from the core are refused**, with no turn spent.
- **The ICE's stealth roll is made on core-touching actions only**: Extract (starting or continuing a pull), Install,
  Burn, Leech. Nmap and Hold on the core don't wake it.
- **A tree run is marked on the run row** (`runs.node`, set when it starts), so turning the flag does not change a run
  already under way.
- **`detected` means the runner's trace is running** (per runner); **`alerted`** is the alert: the ICE wakes, standing
  is lost, the system locks down after the run. Shared by the party; it also sets `detected` on everyone.

## Step 3 rules

- **A firewall one runner opens stays open for the whole party.**
- **A backdoor holder chooses their start on their own screen**: solo on the briefing, a party member beside their
  loadout before launch. Shown only to a runner holding a backdoor on that system. **The core is the default.**
- **Leaving the core mid-fight costs one parting hit** from the awake ICE; after that they are out of its reach.
- **The ICE keeps its damage**: coming back to the core finds it as damaged as it was left, for the rest of the run.
- **Built (step 3)**: a firewall guards the edges leading on from it, never the way back; a system that speaks no OS has
  nothing for its firewalls to ask. The run screen shows the chance to pass a closed firewall you stand on, and the
  Egress button is disabled away from the entry.

## Step 4

- **Built**: Crash Jackout behind the kit's modal (the button opens it, the modal's button is the turn), and the idle
  sweep `cyberspace:idle-runs` every minute. A lone party runner's five minutes run from their last turn or the moment
  the last ally's run ended, whichever is later.
- **In a party a Crash Jackout is chosen like any action** and happens when the round resolves, as Egress does.
- **A Crash Jackout's neural damage claims nothing from injury insurance** (unlike a Flatline's): it is the price of
  pulling the plug.

## Step 5

- **The run tree stays a whole-game switch** (`FEATURE_INTRUSION_RUNS_V2`, per server), not per player.
- **Vault loot is `system_contents.node`** (NULL is the core). The restock fills the core when the core is empty and each
  vault when that vault is empty, in the same pass, and vaults only for systems whose runs walk a tree.
- **About a third of a core's roll**: a third of the creds, and each file or item kept one time in three.
- **A vault's haul does not finish the run**: no `extracted`, no raid, no standing lost, no mission credit. Only the core.
- **One pull at a time, belonging to its node** (`runs.pull_node`): Extract at another node drops the paused pull, its
  files staying where they were, and starts there.

## Phase 2

Decided 2026-11-14. **Phase 2 is the maintenance port, the security hub, the internal relay (with its Loopback script)
and backdoors at any node**, each designed and built on its own, in that order. The noise bar already does the alarm
states' job.

### Maintenance port

- **A dead end off the rich path, one per tree from grade 3** wherever a rich path exists (`NodeKind::Port`). It hangs
  off a conduit (which becomes a switch) or a crossover switch, otherwise off the rich path's vault; never off a
  firewall. Placed last in the generator, so the trees it joins keep every other node. The safe path, when it arrives
  with the relay, gets one too.
- **An exit**: Egress and Flood work there as at the entry (`RunTree::isExit`).
- **Egress there asks the grade below's difficulty** (a grade 5 port rolls against grade 4's 9), plus a reaching ICE as
  usual. The working on the run screen names the port.
- **The scan's Nodes reading counts ports** alongside firewalls and vaults.
- **Drawn in a lane of its own** below the rich path.

### Security hub

- **Where quiet and rich meet** (the conduit that joined them before), so only trees with both (some at grades 5–6,
  all at 7–9). The fast path never passes one. `NodeKind::Hub`.
- **Stepping onto it is loud** on a failed stealth roll (20–30 instead of a move's 3–6), until it is subverted.
- **Subvert** (`RunAction::Subvert`, a button on the hub): the OS roll, as a firewall's. Landed, an act's noise;
  failed, loud, and it can be tried again.
- **Subverted, no alert can go off for the party's run**: the bar still fills, and nothing happens when it is full.
  Kept in `runs.opened`, read across the party as opened firewalls are. If the alert has already gone off, it stays,
  and Subvert is not offered.
- **The scan's Nodes reading counts hubs.**
- **No IDS, no hub**: on such a system the node is a conduit, labelled and played as one (`RunTree::of`).

### Internal relay and Loopback

- **On the quiet path from grade 3**: one of its conduits; a relay system gets a second where there is room
  (`NodeKind::Relay`).
- **Loopback** (`RunAction::Loopback`, a button on the relay) takes one copy of the strongest Loopback script in the
  stash (`programs.loopback_strength`, a Script) and starts it on the deck, holding its memory and compute
  (`runs.loopback`, the running row). An act's noise.
- **While it holds, the whole party's traces stall** (`Run::loopbackHolds`).
- **Each turn after the one it started on it rolls to keep holding**: strength plus the `loopback_routing` skill level
  (added by migration, placeholder text, off the market) against the egress table's figure for the grade (3–19). Held,
  the IDS gets a move's look (3–6 on a failed stealth roll). Missed, the copy is spent and the trace moves that turn.
- **Moving off the relay, or the run ending, spends it.** A Script's lifetime running out ends it too.

### Backdoors

- **A backdoor takes its holder to the maintenance port** (`RunTree::backdoorStart`), the core on a tree without one
  (grades 1–2, and trees with no rich path). Installing stays at the core, as before; backdoors at any node are dropped.
- **The briefing's choice is the port (or core) or the entry**, the port by default; the arrival rolls are skipped
  either way. (Replaces "starts at the core" above.)

## Phase 3

Decided 2026-11-14. **Phase 3 is traps (with honeypots), log servers, sentries (Slow and Fast) with ICE controllers, and
the Cordon tag**, each designed and built on its own, traps first.

### Traps

- **From grade 4, on the quiet and the rich path** (never the fast one): each may carry one, about a third of the time
  at grades 4–6 and a little over half from 7; a silo keeps one more. A free conduit becomes the trap; with none, a new
  node is put into the path (one move longer). Measured: about 28–45% of trees at grades 4–6, 73–83% from 7, every silo.
  `NodeKind::Trap`, its effect in `nodes[n]['trap']` (`TrapEffect`), one per trap from the seed.
- **It reads as a conduit until found**, to Nmap and to the scan's Nodes. **A fourth scan fact, Traps**, read after the
  Nodes (`system_scans.tree` 3), names every trap and its effect; a run started with it knows them all. Stepping on one
  finds it the hard way. (Deep Nmap, later, will find them too.)
- **Effects**: **Alarm**, loud noise (20–30) with no stealth roll (nothing on a system with no IDS); **Trace jump**, the
  trace starts, or moves on a turn's worth of the tracer's rate (two from grade 7); **Ensnare**, no moving on for a turn
  (two from grade 7; other actions are fine); **Node Disconnect**, the trap node is torn out before the runner arrives:
  they stay where they were, the move costs the turn, and the node and its edges are gone for the party's run.
- **Spent once tripped**: a conduit for the party (`runs.traps`, {node: found|spent|cut}, read across the party).
- **Disarm** (`RunAction::Disarm`, a button per found trap one edge away): the OS roll. Landed, it is spent (an act's
  noise); failed, it goes off on the runner where they stand (a Node Disconnect still only takes its own node), and
  that is loud.

### Honeypots

- **From grade 5, a vault other than the tree's first is sometimes a honeypot** (a quarter of the time, two in five on
  a silo): a trap with bait (`nodes[n]['trap']` on a vault, `RunTree::isHoneypot`).
- **It reads and plays as a vault** everywhere, and only Deep Nmap (not built yet) tells it apart: no scan fact names it.
- **It holds nothing** (the restock skips it) and **goes off on the first Extract** with one of Alarm, Trace jump or
  Ensnare (never a Node Disconnect: the runner stands on it). Spent afterwards: an empty vault for the party.

### Log servers

- **From grade 5, one per tree**: a dead end off a conduit (which becomes a switch) or a switch of the quiet or rich
  path (`NodeKind::Log`), drawn in a lane of its own below the paths. Measured: nearly every tree, silos about 60% at
  grades 5–6.
- **Wipe** (`RunAction::Wipe`, a button on it): the OS roll; landed an act's noise, failed loud.
- **It covers only what came before**: offered only once the alert has gone off. Landed, that alert leaves nothing
  behind: the standing it cost whoever set it off comes back (`runs.alert_cost`, refunded as
  `StandingReason::LogsWiped`) and there is no lockdown after the run (`Run::noticed()` reads `runs.log_wiped` across
  the party). The ICE stays awake and traces keep running. The alert stays for the run, so nothing later can cost
  standing again.

### Sentries

- **None at grades 1–3, one at 4–5, two at 6–7, three at 8–9** (`RunTree::$sentries`), posted on vaults first (never a
  honeypot), then on other path nodes (never a trap, the entry, the core, a port or a log server). Fast one time in
  three from grade 7, Slow otherwise.
- **Each is the catalogue's ICE a level below the system's own** (level 1 at least); a system with no ICE posts none.
  Its stats and attacks are that row's.
- **State on the run** (`runs.sentries`: post, node, ice, integrity, awake, fast, target), one set for a party, kept the
  same on every live run of it.
- **Waking**: stepping onto a sleeping sentry's node rolls stealth against its detection (a failure wakes it, locked on
  to that runner); the alert wakes every sentry.
- **Hunting**: an awake sentry keeps its runner while they are within three steps; otherwise it locks on to the closest
  runner within three (the least stealthy on a tie, then at random), or, with nobody near, walks back to its post. It
  moves one node a turn (Fast: two) along the edges; nothing blocks it. Standing on its runner's node, it strikes them
  as the core's ICE does. A solo runner's sentries act after each of their turns; a party's once a round, after the
  core's ICE.
- **Fighting**: Attack (with an icebreaker) hits an awake sentry on the runner's node before the core's ICE; leaving a
  sentry that hunts you costs its parting hit; damage stays for the run, and a broken sentry is out of play. Attacking
  it is loud.
- **On the map**: a ▲ on nodes the runner knows, and a line naming the sentry on their own node.

### ICE controller

- **From grade 6, a dead end off the core** (`NodeKind::Controller`), drawn past it. Nothing below grade 6: posting
  sentries is all it does.
- **After the alert it posts a sentry every third sentry turn** (a solo runner's turn, a party's round), three at most
  (`runs.controller`: ticks, spawned, down; one for the party): awake, Slow, the same ICE as the posted ones, hunting
  from the controller.
- **Shut down** (`RunAction::Shutdown`, a button on it): the OS roll; landed it posts nothing more for the party's run
  (act noise), failed it is loud. Works before the alert too.

### Cordon

- **From grade 5, a sentry carries the Cordon tag one time in three** (`RunTree::$sentries[i]['cordon']`). Awake, it
  cuts the way out instead of hunting: it takes the runner nearest it, their nearest exit (the entry or a maintenance
  port), and on the shortest way there the internal relay, switch or maintenance port whose loss lengthens the way out
  most, or closes it. It walks there (at its speed), then counts down two of its turns, shown on the map (✖ and the
  turns left) and in a warning line; at zero the node and its edges are gone for the party's run, as a Node Disconnect.
  With no such node it hunts like any sentry. It never strikes.
- **Two Cordons can cut at once.** A cut costs no standing.
- **Only breaking it with Attack stops a cut, for now**; the Egress Tunnel script comes later.

### Deep Nmap

- **A flag on an Nmapper** (`programs.nmap_deep`, a checkbox in the item admin): running one makes every Nmap deep.
- **Deep**: one edge further than the Nmapper's reach; the traps and honeypots in reach are found (named, with their
  effect: "Honeypot" for a honeypot); the sentries in reach are read (`runs.sentries[i]['read']`), so the line naming a
  sentry says whether it is Fast or Slow and whether it carries the Cordon. Same noise as an Nmap.
- **A found honeypot** is disarmed like a trap (OS roll), from the next node or standing on it; once spent it is an
  empty vault. Extracting from a found, live one still sets it off.

### Egress Tunnel

- **A Script** (`programs.tunnel_strength`, set in the item admin) started from the stash by an action on any node but
  the core (`RunAction::Tunnel`; the `egress_tunneling` skill at level 1 is needed, added by migration with placeholder
  text, off the market). It holds its memory on the deck. No hold roll: it lasts while its runner stays on the node, and
  is spent when they move off, at the run's end, or when the script's lifetime runs out.
- **While it holds, the node is an exit for everybody in the party, its runner too** (`Run::tunnelNodes`,
  `Simulator::atExit`): Egress and Flood work there. Egress asks the grade below's difficulty, as at a maintenance port,
  less the tunnel's strength (the strongest on the node), plus a reaching ICE.
- **A Cordon cannot cut a node an Egress Tunnel holds**, and a cut under way stops when one goes up on it.

### The map, alive

Asked for 2026-11-14: more life, animation and colour in runs.

- **Threats in ember**: the core while its ICE stands, any node with a sentry, a running ICE controller, a security hub
  nobody has subverted (`TreeView` `threat`, `.board__node--threat`).
- **After the alert they pulse**, ember on black to black on ember (`.board--alert`).
- **A move** sends a ◆ along the wire from the old node to the new, which flashes as it arrives; **a revealed node**
  (and its wires) dissolves in. The page compares against what it last showed of the run (session storage, per tab); a
  first look, failed storage or reduced motion animates nothing.

## To come back to

Kept up to date as the branch grows (2026-11-14). Nothing here blocks the next step; all of it blocks the merge.

- **Strings**: every player-facing line added on this branch is a suggestion for the user to rewrite (`config/strings.php`,
  the `cyberspace.tree`, `cyberspace.run.action`, `cyberspace.run.log.tree`, `cyberspace.run.refused`,
  `cyberspace.reading` and `programs.spec` keys).
- **The Loopback skill** (`loopback_routing`): placeholder title and description, off the market; the user rewrites it
  and decides its price and when it goes on sale.
- **Loopback items**: none exist yet. Create them in the item admin (Script, Loopback strength 1–20) and decide where
  they are sold. The hold roll is flat (spread 8): strength 10 with no skill holds about 47% at grade 5.
- **The safe path** (an internal relay and a maintenance port on a path of its own) is not built.
- **A party test for the Loopback**: an ally's trace stalling while another runner's Loopback holds is untested.
- **The nmap migration grants items at deploy** (to every Deck Jockey, and shop shelves): decide before merging.
- **The nmap item in the Deck Jockey loadout**: added in the archetype editor at release.
- **ACiD's local nmap stack** reads 2 (granted onto an existing row); reset it to 1 if wanted.
- **Trap rates at grade 4**: about 28% of compute and relay trees (one path that may carry a trap); planned about half.
- **Deep Nmappers**: none exist yet; create them in the item admin (an Nmapper with Deep ticked).
- **Sentry speed as a scan fact** (the proposal reads it with the ICE) is not built; neither is a party test of the round's sentry turn.
- **Egress Tunnel items and the Egress Tunneling skill**: none exist yet / placeholder text; create and price them.
- **A review of the whole branch** before the flag is turned on.
