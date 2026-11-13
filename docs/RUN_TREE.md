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
  every node is). Nodes is only rolled once Layout is known.
- **A run starts with what the live scan knows**: no scan, only the entry and its edges; Layout, the whole shape;
  Nodes, everything typed.
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
- **A firewall opens by moving on**: choosing a move past a closed firewall makes the OS roll. Landed, it opens and the
  runner moves in the same turn (act noise). Failed, they stay, the turn is spent, and it is loud.

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
