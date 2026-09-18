# Cyberspace

The grid, the systems on it, and what happens when you break into one.

Read MECHANICS.md first for anything that produces a number: every threshold,
chance and modifier named here resolves through the one resolver, and none of
the arithmetic lives in this file.

---

## Status

**Phase 3 is under way; everything past it is still design.** This document is
the agreed shape, written down so the implementation has something to be
checked against — the same contract MECHANICS.md holds for the stat system.

| Piece | State |
| --- | --- |
| Everything below, unless a row here says otherwise | **specified only** |
| The grid itself — positions, chunks, the portal, the edges | **built**; `App\Cyberspace\Grid` is the one place that knows how it is stored |
| Addresses | **built**; generated, validated and normalised, and unique on the table |
| The world generator | **built**; `cyberspace:seed` tops the grid up to its target occupancy, like the market's pass |
| System names | **built**; composed as `<Type> - <corp> NNN` from columns the row already has, never stored — `systems.name` is now the authored-name override |
| Operating systems on machines | **built**; `systems.os_skill_id` points at the skill that gates it, chosen by grade off the skills' own rank ladder |
| The OS as a gate you cannot see | **specified only**; a machine whose OS you lack is entered rather than refused, and every action against it fails. Indistinguishable from bad luck on purpose — a refusal would hand over a scan fact for free |
| Egress | **built**; always possible, never certain. The `egress` stat — a pool of Reflex, Focus and Intellect — rolled against the machine's grade plus a term for whatever is awake. A failed attempt spends the turn, so the machine gets its half of it; a threshold-forced egress rolls too. `Simulator::egressChance()` is public, and the button quotes it |
| The run, interactive | **the spine is built**: `runs` is durable state, `App\Cyberspace\RunStarter` begins and ends one, and `characters.current_run_id` is what says somebody is inside. The turns themselves are the simulator and are not built |
| The fight | **built**; `App\Cyberspace\Simulator` resolves one turn — the player acts, then the machine does. An icebreaker grinds integrity down, a miss does nothing at all, and a defeat ejects before the waiting screen |
| Detection, on approach | **built**; every watcher on the machine rolls against the runner's `stealth` and any one success detects them. Nothing is woken by a run that goes unnoticed. The global certainty floor, not a kind of its own |
| Detection, on the core | **built**; extraction touches the core, so it is the second roll — every watcher gets a fresh look, independent of what happened on approach, and the roll fires whether or not the extraction it accompanies goes on to succeed |
| Extraction | **built**; `App\Cyberspace\Extractor` takes creds and catalogue items the moment the core is touched, a catalogue item merging the way an archetype loadout does. A file becomes one unstackable "Encrypted Data Dump" carrying its real numbers as JSON, and costs turns to match its size in QB, cut back down by the deck's resolved bandwidth — `runs.extracting_qb` is the countdown, and `App\Cyberspace\Simulator` spends it a bandwidth's worth per turn, so the ICE (if anything woke) keeps swinging on every turn a pull runs |
| `RunEnding::Completed` | **built**; egress after a successful extraction ends this way rather than as a plain egress — "they did what they came to do" is only true from that point on. `runs.extracted` is the flag both doors to leaving check |
| The trace | **advances**, once something has noticed: the machine's `tracer_rate` a turn, and it WALKS the routing chain, one hop a turn: a single attempt at the next system, passed or not, and a hop once passed is behind it for good. Grade 1 never holds it, grade 9 holds it four turns in five. Drawn on the run screen as the chain it walks, with an estimate of the turns left |
| A completed trace | **built**; puts heat on the character (one point, a placeholder — see TODO.md) and calls the chain-burn, which is a no-op because there are no backdoors |
| The two thresholds | **built**; they ACT before the player's choice, every character is born with 70% and 30%, and they are set in an accordion on the map. Zero turns one off |
| The run lock | **built**; `EnsureRunIsFinished` sends every request but signing in, signing out and the run's own routes back to it. Admin routes are NOT exempt, unlike `RequireCharacter` — a run is a character in jeopardy |
| The readiness net inside a run | **built**; `EnsureDeckStillRuns` stands down while `current_run_id` is set, so a burnout resolves its own ending instead of racing a middleware that would call it a readiness loss |
| The OS gate at the door | **built**; a machine whose operating system the runner lacks is ENTERED and `runs.os_locked` records it. Nothing shows it, and nothing reads it yet — the failures it causes are the simulator's |
| The run lock | **specified only**; while a run is live every request but signing in and out is sent back to it. This is what replaces "pulling the plug cannot save you" |
| The blind-run warning | **specified only**; the screen that starts a run names what the scan did not find out, from `SystemScan::unknowns()` |
| Mapping as a run purpose | **withdrawn**. The probe took the job: a read happens on the open grid, produces the same sellable file, and needs no run. Scanning from inside a run is not allowed either — a blind run is a commitment |
| A scan is spent by the run that uses it | **built as a column** (`system_scans.used_at`, consumed on first use); nothing spends one, because spending one means a run. In co-op the most complete file covers the party and only that file is spent |
| Concurrent runners | **specified only**; a machine holds several at once and they are not instanced. First extraction takes it, and both raids count |
| A cancelled run | **specified only**; burned or locked down under you ends the run for 1 flat SI and nothing else. Asked on the lock, never by a sweeper, and the run row carries the ADDRESS so a burned target is an ordinary answer |
| Coordinated Strike, Advanced Coordination | **not built**; rank 8 (needs Cyberdecks 5) and rank 16 (needs Coordinated Strike 5), +1 co-op slot per level each. Skill ROWS, authored in the editor — but the `coop_slots` stat has to exist first or the modifier editor refuses the target |
| `coop_slots` | **not built**; the base is 0 in config and every slot is a modifier. `program_threads`' shape, floored at ZERO rather than one, because zero is the gate |
| Detection scaling with party size | **decided, and it is no number at all**: every runner rolls their own approach and detection is party-wide, so a party is louder by compounding. A per-runner difficulty term was measured and rejected — it changes nothing at the grades co-op is for |
| A trace per runner | **specified only**; one meter each on the same clock, so a completed trace burns the chain that runner came in on and not the party's |
| The turn protocol | **specified only**; a turn resolves when all have chosen or 30 seconds pass, an absent player is resolved by their own thresholds, and `auto-run` hands the whole run to them. Shared with physical combat as a PROTOCOL, never as an engine |
| The two thresholds | **specified only**; they ACT rather than warn, every character is born with 70% trace and 30% SI, and they are changed in an accordion on the cyberspace screen |
| The notification queue | **not built**, and named here because a cancelled run needs one. One place, reached from the rail, with priorities and categories. Until it exists the cancellation screen is the notification |
| Scanning — the probe | **built** for what a system currently HAS: grade, operating system, intrusion detection and tracer. A machine with none of the last two reads as *None*, which is an answer rather than an unread field. Needs an analyser RUNNING on the equipped deck; one press starts a READ, which goes round pass after pass until the file is whole or the `scan_passes` budget is gone |
| A pass takes TIME | **built** — about a minute against a grade 1 and sixteen against a grade 9, cut by skill and by a better instrument through the `scan_duration` stat. Settled lazily by whoever next looks |
| Node inventories — what a machine holds | **built** — creds always, a generated FILE on a roll, and none to several items, all scaled by grade and leaned by type. `system_contents`, written by `App\Cyberspace\NodeStocker` |
| Rarity, per grade band | **built** — `loot_tables`, one row per (type, band, rarity). Grades 1–3 see rare at 1%; ultra-rare is weight 0 below grade 8, which is a wall and not a long shot |
| What a raid can carry out | **built** — data only. A node never yields a physical object: the shelf is `on_market` items whose `container` is `data` |
| A machine told what to hold | **built** — `systems.stock_rule`, NULL on nearly every row: `fixed` for exactly these, `any_of` for this many from a set, `plus_random` to keep the ordinary draw as well. What mission systems will use; nothing sets one yet |
| Nightly restock | **built** — `cyberspace:restock`, a TOP-UP like the market's pass: a node still holding something is left alone, so emptying one is temporary |
| The raid counter | **built**; `systems.raids`, on the system and not the character, written only by `App\Cyberspace\RaidLedger`. Counted once per RUN that took something, the moment the take lands; the third one is one of the two things that shut a machine |
| Lockdown | **built**; `systems.locked_down_until`, written only by `App\Cyberspace\Lockdown`. Two triggers — the run was noticed, or the books are spent — asked as a run ENDS, so it never reaches the runner who caused it. 2-6 hours, rolled. Nothing may run a shut machine, and anybody still inside is thrown out as `RunEnding::Cancelled` |
| Relocation | **built**; `RaidLedger::relocate()` — new position, new address, books balanced — driven by `cyberspace:lockdowns` every five minutes. **The one thing in cyberspace that is not settled lazily**: two machines drawing the same free position is a race, and the sweep serialises it |
| `RunEnding::Cancelled` | **built**, and it finally has a caller. One flat point of Synaptic Integrity through `DamageOrigin::RunCancelled`, no defeat effect, no raid counted, no trace — and the floor is asked, because one point can put somebody down |
| A read runs in the background | **built** — it belongs to the running copy of the scanner, not to where its owner is standing: walk away or jack out and the answer is waiting |
| Two instruments, two machines | **built** — a second read needs a second running instance, which costs its own compute and a free thread |
| Threads — how many copies of one program run at once | **built** — `program_threads`, one by default, raised by Multithreading and only for software flagged `multithreaded` |
| An interrupted read is lost | **built** — the analyser has to have been running for the WHOLE pass, which `inventory_items.started_at` answers without recording a history |
| Defences — the IDS and the tracer | **built** as columns on `systems`, decided once at creation and stored. Type guarantees two countermeasures and grade rolls the third; `App\Cyberspace\Defences` is the decision. Nothing rolls against them — that is the simulator |
| A machine told what to defend itself with | **built**, and it needed no mechanism: defences are stored rather than derived, so a mission node is a row with different numbers in the same columns |
| Defeat tags | **built and APPLIED.** `App\Cyberspace\DefeatResolver` rolls and applies every tag a piece of ICE carries the moment it wins — Flatline deepens the neural-damage injury, Neural Shock lands on current hit points, Surge destroys fitted modules one roll per module, Erase wipes storage AND anything running, Skim takes a share of carried creds. Called from `Simulator::resolveEnding()` before the ejection, per the bad-ending order |
| What each piece of ICE does | **written**, by `cyberspace:tag-ice`. The bottom of the ladder carries nothing, permanent damage starts in the grade 6-7 band, and Erase III — the deck wipe — is on exactly one piece |
| Whether losing gets a price put on you | **built** as `systems.ice_bounty`, rolled per MACHINE when its ICE is assigned. The same guard on two systems is two different risks, and **a scan reads it** — folded into the ICE fact, one roll for both |
| Icebreakers | **built** as twelve programs, damage carried as a modifier on `icebreaker_damage`. **Twenty turns is never enough** — every one does strictly less than would break its matching ICE in that time, and the whole gap is left for skills |
| Fighting at all | **gated behind training, deliberately.** A bare character cannot beat even ICE I: thirty turns against its 1.05 SI a turn is 31.5 against a starting pool of 31. The bottom of the grid is walkable, not fightable |
| Attack tags — what ICE does WHILE it fights | **built**; `ice.attack_tags`, the mirror of `defeat_tags` and deliberately not a variation on it — a defeat tag fires once on somebody who lost, an attack tag is in force while the thing is awake. **Ensnare** is the first: it multiplies the egress chance (a quarter, two-fifths, rather more than half), is passive while the ICE lives so breaking the guard frees you, and is floored again after the reduction so it can never close the door. Five of the twelve carry it |
| ICE | **built** as a catalogue of twelve, three per grade band, arriving by migration like the corps. Integrity, attack, a hit chance (`skill`, 1-100 — a miss does nothing at all) and detection. `systems.ice_id` points at one; a scan remembers the LEVEL and carries no foreign key. Defeat tags are content — `cyberspace:tag-ice` writes them, a console command and not a migration, so a fresh database's ICE carries none until it is run |
| Scanning software, in versions | **built** — three analysers, differing by what their own rows carry: a bonus to the read, and extra passes |
| Scan files — perishable, consumed | **built** as rows: 48 hours, spent on first use. Nothing spends one yet, because spending one means a run |
| Discovery — terrain | **built** as a chunked bitmap, 512 bytes per 64 x 64 chunk entered |
| Discovery — contact | **built** as rows keyed by position, with no foreign key to `systems` on purpose |
| Sweeping — the act that writes both | **built**; `App\Cyberspace\Sweep`, clipped to the grid |
| Jacking in and out | **built**; `App\Cyberspace\Navigator`, gated on `DeckTotals::isReady()` |
| Stepping and jumping to an address | **built**; every arrival sweeps |
| The deck lock while jacked in | **built**; `Character::isLockedInCyberspace()` is the question, and the three writers all ask it |
| Losing readiness while jacked in | **built**; `App\Cyberspace\ReadinessWatch` ejects and takes 1 SI, asked in place by the screens that can cause it and by middleware on every jacked-in request. An idle player stays inside until they next click — there is no scheduled sweep |
| Bookmarks — waypoints and system bookmarks | **built**; one table, two kinds, and a waypoint may only be set on swept ground |
| Recall — travelling to a bookmark | **built**, and free: walking is what buys new ground |
| The map screen | **built**; `/cyberspace`, drawn from memory and never from `systems` |
| Marking a machine you have not seen before | **built**; `system_contacts.first_seen_at`, carried through a sweep and restarted when a different address appears at a position |
| Moving by tap, click and key | **built**; the eight around you are buttons, arrows and the numpad reach the same eight |
| The storage socket and its measure | **built**; every deck has one, and nothing reads the totals yet |
| The check layer it all resolves through | **built** — the stats, the graph, conditional modifiers, `character_modifiers`, skill effects, and now the die: `App\Checks\CheckRoller`. Nothing calls it, because nothing has a difficulty yet. See MECHANICS.md |
| Jack in / sign in — the vocabulary split | **done**; auth reads Sign in / Sign out, and `ui.action.jack_*` is reserved for cyberspace |
| Operating System skills — AuraOS, Prism-V, IRONCLAD, Lattice | **built** as skill rows; nothing reads them |
| Network Topology, System Profiling, Advanced System Profiling | **built** as skill rows; nothing reads them |
| Encryption skills — Cinder, Halcyon-3, BLACKGLASS, Ouroboros | **built** as skill rows with books; placeholder descriptions, no prerequisites yet |
| `DeckTotals::isReady()` — the readiness gate | **built**; jacking in is what will call it |
| The waiting screen | **built**; `App\Characters\WaitingRoom`, one minute through the `recovery_minutes` stat, released lazily on the next request. **Zero to one, and no further** — getting up is not healing. `ReadinessWatch` is its first caller, and the ejection happens before it |
| Synaptic Integrity as a resource | **built**, and the readiness dump is the first thing that damages it. Damage resolves like any other number — origin and resource are both tags, so a resistance is a modifier; see MECHANICS.md. Reaching zero has no consequence yet: there is no waiting screen |
| AuraOS in the Deck Jockey loadout | **done**; granted at level 1 alongside the deck |
| The thermal rename | **done**. Accumulation itself is **specified only** — nothing runs a deck yet |
| Burning a module out mid-run | **specified only**; the thermally weakest module is destroyed, the trace is cancelled, the player is thrown out for 25–75% of current SI and a 12% chance of a flatline — **Flatline I**, the mildest there is, because a burnout is an accident. The numbers are in `config/cyberspace.php` and nothing reads them |
| Flatline levels | **specified only**; I-III at 4-10 / 9-16 / 14-19 per cent of maximum SI, and none of them reaches a fifth. `config/cyberspace.php`, unread |
| The order a bad ending resolves in | **specified only**; damage, then consequence, then ejection, then the waiting screen — one order for defeats, burnouts and readiness losses alike, and **a consequence never becomes a new cause of damage within it**. That is what says a Surge carries its own tag and nothing else |
| Radiator cooling, recalibrated for accumulation | **done** as data — the eight radiators, compressed to 90–520 TU |
| `config/checks.php` — the certainty floor | **built** as a config constant at 0.0085; nothing reads it |
| Routing, Advanced Routing | **built** as skill rows with books and prerequisites; nothing reads them |
| Radiator power draw, raised alongside cooling | **done** as data — 25–300 W |
| Everything a deck's numbers do | **specified only** — see MECHANICS.md |

---

## Vocabulary

Three words were already spoken for and have been re-cut. Getting these wrong
in a player-visible string is a bug, not a style preference.

**Jack in / jack out means cyberspace, and nothing else.** Entering and leaving
the grid. Signing into the *game* is **sign in / sign out**, and the auth
screens have to be re-worded: `auth.login`, `auth.logout`, `auth.remember` and
the submit label all currently say "Jack in", which would put two Jack out
buttons in the rail meaning two different things. Equipping a deck is a third
thing again — which deck you are carrying — and was never either of these.

**Heat is how badly the world wants you.** A completed trace puts heat on a
character; it is a persistent property of the person, not of a machine. What a
deck's hardware does thermally is **thermal load**, measured in **thermal units
(TU)**, and every stat id, column and class carrying the old name has to move —
see MECHANICS.md, *The thermal rename*. A player reading "your heat is high"
must never have to ask which of the two is meant.

**Grade is how hard a system is.** Not `rarity`, which already means how often
the market stocks an item, and not `tier`, which already means what an item is.
Grade decides a system's integrity, what countermeasures it runs, what it is
carrying, and whether it is survivable at all. A grade is the number a scan
exists to discover before you find it out the expensive way.

---

## The grid

Cyberspace is a coordinate space. It is **64 × 64 today and will be 2048 × 2048**,
and that second number is a design input rather than a someday: at four million
positions, discovery stops being flavour and becomes the point of the map.

**A position and a system are two different things, and this is the single most
important structural fact here.** A position is a coordinate; it never moves and
never changes. A system is a machine with an identity, a grade, an operating
system, contents and a name — and its address and its coordinates are
*attributes it currently has*, which change when it relocates. Anything written
as though a cell "is" a node will break the first time a system moves, and
systems move constantly.

**Roughly 30% of positions are empty** — not a dead node, not a placeholder,
just space. That reserve is what relocation and instantiation move through, and
without it a grid that churns has nowhere to churn to.

### The centre and the edges

One position near the middle holds the **Cyberspace Portal**, where every player
arrives on jacking in. It is not interactable in any way: it cannot be scanned,
intruded, burned or owned, and it is the one system on the grid that is none of
those things. 64 has no true centre cell and it does not matter — any single
cell near the middle is the portal.

**The grid does not wrap.** The edges are edges. A viewing range at the edge
simply sees fewer positions, and the corners are the quietest part of the map,
which is a reasonable thing for the corners of a map to be.

### Addresses

An address is **8 hexadecimal characters**, unique, random, and carries no
information — you cannot read a position out of one.

**Addresses do not survive.** A system that is burned takes its address out of
existence with it, and a system that relocates comes back with a new one. This
is deliberate and it is what keeps the map alive: an address is a fact about a
system *right now*, not a permanent name for it.

Jumping to a typed address goes there if it resolves. **Trading addresses is
fine and expected** — it is the same act as inviting someone onto a shared run,
and at four million positions the constraint on exploration was never secrecy.
What paces exploration is patience: you walk, you bookmark the frontier, you
come back. Subnets are the exception, because things there attack you for moving.

### Subnets

A subnet is a separate, much smaller grid reached through a relay. **Subnet
addresses are in their own space and are not confusable with grid addresses:** a
prefix naming the subnet, then a **16-bit address — 4 hex characters**:
`mil:4A19`, `dark:00FE`.

65 536 addresses is far more than a small grid needs, which is fine. What
matters is that the prefix makes the space unambiguous, so nothing has to guess
which grid an address belongs to.

### Discovery: two records, not one

Discovery used to be one question. Relocation makes it two, and they behave
differently:

- **Terrain** — *I have looked at this position.* A position cannot move, so
  this never goes stale and is never wrong.
- **Contact** — *I know this system: its name, its address, what it looked
  like.* This dies when the system relocates or burns.

**The map a player sees is their memory, not a live feed.** It shows the terrain
they have swept and the last thing they saw at each position, marked stale where
the sighting is old. Jumping to a bookmark and finding nothing there is not a
bug — it is the world having moved on, and it is the mechanic that makes
exploration continuous instead of a thing you finish.

**Bookmarks are therefore two kinds.** A *waypoint* is a position and survives
everything; it is how you return to the frontier. A *system bookmark* is an
address and dies with the system. Special systems a player discovers are
bookmarked automatically, as systems.

**A player's own nodes are the exception**: when one relocates, the owner's
bookmark to it is rewritten to the new address rather than being allowed to die.
See *Player-owned nodes*.

**What each kind is FOR, in the player's terms**, because the two are not
alternatives and a screen that presents them as one list still has to make the
difference obvious:

- **A waypoint marks a NEIGHBOURHOOD, not a machine.** You find a stretch of
  the grid thick with high-value targets and you want to come back to *there* —
  and you mark the position rather than any one machine because the machines
  are what will eventually move. Nothing places such a cluster today; it is a
  statistical accident. But an accident is enough, because **the grid only
  moves where players push it**: an untouched cluster keeps its shape until
  somebody starts working through it, and by then the waypoint has already paid
  for itself. See *Relocation*.
- **A system bookmark is an address, and an address behaves like an IP.** You
  do not have to have swept the surrounding area to connect straight to one;
  you do not have to know where it is, or what is between you and it. Somebody
  can simply give you one.

**What may be bookmarked is not symmetric, and that asymmetry is what paces
exploration.** A waypoint may only be set on ground the character has SWEPT;
a system bookmark may be set on any address at all, seen or not. It looks
inconsistent until you ask what each one is worth to somebody else: an address
is a door, so handing one over is the invitation the design already blesses,
while a position is a *map*, and a tradeable position would let one player hand
another the walking they did. So the terrain bitmap gates one and nothing gates
the other. `App\Cyberspace\BookmarkKeeper` is where that lives, because no
CHECK can reach across to another table's bitmap.

### How the grid is stored

At 64 × 64 this is 4 096 rows and none of it matters. At 2048 × 2048 it is
4.2 million positions and every decision below starts costing something. Both
sizes have to work, so:

- **Discovery is a chunked bitmap, never a row per position.** The grid divides
  into 64 × 64 chunks — 512 bytes of bits each, one chunk today and 1 024 of
  them at full size. A character stores a blob per chunk they have entered, so a
  player who has explored five chunks holds 2.5 KB and a player who has explored
  nothing holds nothing. A row per discovered position is the version that does
  not survive the grid getting bigger.
- **Contact is rows**, because there are few of them and they carry content —
  what you saw, when, and whether it is stale.
- **The world is materialised now and procedural later.** 4 096 system rows is
  a table; 2.9 million is a generator with override rows for everything that has
  diverged from it. Both are the same question — *what is at (x, y)?* — so it is
  answered in one class from the start and the storage swaps underneath it.
  Nothing else in the codebase should know which of the two is in use.

The cost of the procedural version, stated in advance so it is not a surprise:
questions like *list every grade-5 system on the grid* stop being cheap, and the
override table grows with churn rather than with the world. Both are acceptable;
neither is free.

---

## Systems

Most systems on the grid are intrusible. What one carries:

| Property | What it is |
| --- | --- |
| **System Integrity** | the core's hit points |
| **Grade** | how hard it is, what it runs, what it holds |
| **Operating System** | which OS skill you need at level 1 to attempt it at all |
| **Type** | data silo, compute node, network relay, player-owned, and so on |
| **IDS** | detects an intrusion; detection is what wakes everything else |
| **ICE** | the opponent — integrity, attacks, and a defeat effect |
| **Tracer** | runs the trace that ends in heat |
| **Cloaked systems** | hidden extras; found by scanning, worth more |
| **Owner** | a faction, a player, or nobody |
| **Contents** | its own inventory — see below |

**Grade spans magnitudes.** The easiest systems sit comfortably inside a
starting character's Synaptic Integrity; the hardest can flatline someone at 200
SI. That range is exactly why scanning is not optional flavour — the difference
between a system you can take and a system that ends your character is a number
you are meant to find out *before* you approach.

**A system's contents are its own inventory model**, not the character inventory
with a different owner column. A node holds creds, data and items; it is raided
rather than carried; and what is in it is safe from the losses a character takes
when ICE beats them. That is a different set of rules from a stash, and it gets
a different model rather than a flag on the same one.

**A node's inventory restocks nightly**, in housekeeping. Emptying one is
therefore not permanent, which is what keeps the low end of the grid worth
walking through — but a node also counts its raids, and the third one retires
it. See *Lockdown*. A quiet thief gets three good nights out of a system, not an
annuity.

### Data is a file, and it is always encrypted

Stolen data is not a generic reward. Every file has:

- **A size in QB.** Transferring it takes turns, and turns are trace and thermal
  load. A greedy haul is an exposed one.
- **An encryption type.** Everything comes off a system encrypted. There is a
  skill per encryption type, the same shape as the skill-per-operating-system
  gate, and breaking one is a check against it.
- **Two values.** What it fetches encrypted, and what it fetches once broken —
  the second much higher. **NPC buyers are the primary market.**

**Decryption happens later, at leisure** — never during a run. A file comes out
of a system encrypted and stays that way until the player sits down with it,
which keeps the run simulator free of a second kind of check and makes the
stolen file an object with a future rather than a number that resolved on the
way out.

There are **four encryption types**, ranks 2, 4, 8 and 16, one skill each, the
same shape as the operating systems: cheap at level 1, worth training past it,
and gated by owning the book. Cinder, Halcyon-3, BLACKGLASS and Ouroboros exist
as rows today with placeholder descriptions.

Decryption is where a sell-it-now player and a sit-on-it player diverge: one
takes the encrypted price, the other turns skill into margin.

### Where a program lives and what it costs to run

Three deck measures, three different jobs, and a program touches all three:

| Measure | What it holds or spends |
| --- | --- |
| **qFLOPS** — compute | what a *running* program consumes. Every program has a qFLOPS cost, and a deck without enough free qFLOPS cannot run it. |
| **Quantabits** — memory | what a *loaded* program occupies |
| **Storage** — bulk | where files and unloaded programs sit; where extracted data lands |

That is the loadout puzzle: memory limits what you can bring, compute limits
what you can have running at once, and storage limits how much you can carry
out. Storage is a **new socket kind and a new measure** — see MECHANICS.md, *The
sixth measure*, for why it cannot simply be more quantabits.

### Lockdown

**Lockdown is what happens when a system knows it was violated.** That single
rule is the whole of it, and every trigger below is an instance of it:

| Trigger | Why the system knows |
| --- | --- |
| **The IDS fired** | it saw you. Applied after the run, whatever else happened. |
| **The ICE activated** | it fought you. Applied after the run. |
| **Burn** | there is nothing subtle about it. |
| **The third raid** | see below — nobody's books stay unaudited forever. |

**Extraction is stealing, and a clean extraction does not lock a system down.**
Getting in, taking what it was holding, and getting out unseen leaves a system
that does not know anything happened. Its inventory is empty and it restocks in
housekeeping, so a quiet thief has somewhere to come back to.

**Three raids and it locks down anyway.** A counter **on the system, not on the
character**, incremented by every successful raid whoever made it, and when it
reaches three the system locks down and relocates as though it had been caught.

> **Built.** `App\Cyberspace\RaidLedger` counts the raid — once per run, the
> moment a take lands — and `App\Cyberspace\Lockdown` shuts the machine when
> the run ends, for 2-6 hours, after which `cyberspace:lockdowns` relocates it.
> Two triggers rather than four: the ICE can only have activated if something
> noticed, so `runs.detected` covers both of those rows, and burn does not
> exist. The compromise the raid pass carried — a machine held in place while
> anybody was inside it — is gone, because a lockdown now throws them out.
Somebody eventually reconciles the numbers. This is what stops a perfectly
executed loop from being a permanent, riskless income: even flawless play
retires the node.

**The counter resets on relocation.** A relocated system is effectively a new
one — new address, new position, and its books balanced. Without that reset
every system in cyberspace would be on a permanent countdown to its third raid
and the grid would grind itself into pure churn.

Together with the detection floor — **no run is ever safer than about 3%**, see
*Detection* — that is two independent brakes on the same loop, one that fires on
luck and one that fires on arithmetic.

### Relocation

**When lockdown ends, the system moves.** New position, new address, same
system. Its bookmarks break, its address dies, and every scan anyone was holding
on it becomes worthless.

**RELOCATION IS A CONSEQUENCE, NOT A BACKGROUND PROCESS.** The grid does not
churn on its own. A system moves because somebody raided it and its lockdown
expired, or because somebody sold a scan of it to an NPC buyer — both of which
are players acting on it. **A system nobody has touched sits exactly where it
is, indefinitely.** That is easy to misread as ambient churn and it is worth
being plain about, because a great deal follows from it: a stretch of grid a
player has found and not yet worked through is *stable* while they work through
it, and it only starts moving once somebody starts taking it apart. Discovery
keeps its value; exploiting what you discovered is what spends it.

This is the mechanic that makes the grid a living thing rather than a board that
gets solved, and it is why roughly a third of the grid is empty: relocation
needs somewhere to go. A relocation is a *move* — it frees a position and takes
one — so it does not consume the empty reserve. Only burning and instantiation
do, and burning returns the position to empty after a while.

**A relocated system keeps its identity.** It is the same machine with the same
grade, contents and history, wearing a new address. That matters for backdoors,
for ownership, and for anything that will one day want to say *this is the
system that got you last time*.

### Backdoors

A backdoor install does not lock a system down, **and it does not lock one down
even if the IDS fired or the ICE woke up**. That is the whole value of the
operation: the install erases the logs, resets the countermeasures it tripped,
and leaves the system believing nothing happened. A backdoor run is the only
thing in cyberspace that cleans up after itself.

Three consequences, and they follow from the fiction rather than being bolted
onto it:

- **A backdoor run that fails cleans up nothing.** Egress early or lose the
  fight and there was no install, so the ordinary triggers apply and the system
  locks down like any other.
- **A completed trace still lands.** The cleanup fixes the system's *afterwards*
  — its logs, its alarms, its lockdown — but a tracer that finished its job
  found you while you were standing there, and heat is a fact about the world
  and not about the machine. Erasing the logs does not un-know what the tracer
  already told somebody.
- **A backdoor does not increment the raid counter**, because the counter is
  the system noticing a pattern in its own records, and those records are gone.

A backdoor keeps a system open: no lockdown, no relocation, a permanent address
in a world where nothing else has one. That is a genuinely powerful thing to
hold, so it is not held forever.

**A backdoor is one of two things, and the choice is made when it is installed.**
A system can siphon or it can route. It cannot do both, and changing your mind
means going back and doing the run again.

| | **Siphon** | **Routing** |
| --- | --- | --- |
| What it gives | a little cash every night, paid straight into your creds — **not built** | a hop in the chain a trace has to walk — **not built** |
| Where it is useful | on a system with something worth taking | on any system at all, however worthless |
| How you lose it | the nightly ageing roll, and nothing else | the ageing roll **or** a completed trace, which takes every routing backdoor in that run's chain at once |

That asymmetry is the point. **A siphon is safe and slow; a routing hop is free
and expendable.** Income is insulated from routing risk — a trace that catches
you never touches your siphons — so the two halves of a backdoor portfolio have
genuinely different risk profiles and are worth building separately.

**Both kinds give a discount on getting back in.** A backdoor skips or heavily
reduces the **approach** detection roll on every return visit. It does
**nothing** for the core roll: extracting through a backdoor is still stealing,
still rolls, still counts toward the system's three raids, and still locks the
system down if it is caught. A backdoor buys you half the risk permanently, not
a bypass of the brake.

> **Backdoors are built, less the chain and the siphon payout.** The table,
> the two kinds, installing one on a `RunPurpose::Backdoor` run, the cleanup
> that leaves no lockdown behind, the skipped approach roll, the map's mark —
> and every way one is lost: the nightly ageing roll
> (`cyberspace:age-backdoors`), a lockdown somebody else triggered, a machine
> that relocates for any reason, and a burn through the foreign key. What is
> NOT built: the routing chain below, so `Run::routingGrades()` still returns
> `[]` and a trace still walks unopposed; and the nightly siphon payout, so a
> siphon is currently a choice that pays nothing.

### The routing chain

Backdoored systems can be **chained**, and every run is routed through the chain
before it reaches its target. That is the reason to hold a backdoor on a system
worth nothing at all.

**A hop does not slow the run down.** The extraction is a direct pull — the data
comes straight back — and it is the *trace* that has to walk the chain to reach
you. So a chain costs no turns, no bandwidth and no heat. What limits it is
skill, and what it risks is itself.

**The chain is declared per run, not held standing.** Every run picks its own
path from the routing backdoors the character holds, so how much infrastructure
goes on the table is a decision made fresh each time: a long chain for the job
that matters, a short one or none for a routine raid. That is the decision the
exposure rule exists to make interesting.

**Chain length is a skill, and only a skill:**

| Skill | Rank | Requires | Gives |
| --- | --- | --- | --- |
| **Routing** | 4 | Cyberdecks L3 | one system in the chain per level |
| **Advanced Routing** | 8 | Cyberdecks L5, Routing L5 | one further system per level |

Ten hops is therefore the ceiling a character can reach on skills alone, and
implants and gear are expected to lift it later. **Built**: `routing_hops` is a
resolved stat with base zero, and both skills grant one per level — so the
ceiling is content rather than code, and an implant that lifts it is a row.
Nothing READS it yet, because the chain itself is not built. Siphon backdoors are not eligible and never appear in the list.

~~**Each backdoored system will hinder the trace by a different amount** —
depending on what it is, who owns it and how well it is dug in.~~ **Decided:
grade, and nothing else.** A hop's chance of letting the trace through is what
`cyberspace.trace.advance` says for the machine's grade, which is the
differentiator already on the row. Owner was rejected as a number per corp for
121 of them; age was rejected because it would pull against the ageing roll,
which makes an old backdoor more likely to vanish rather than better at its job.

**A hop only ever delays by FAILING.** Passing one costs the trace nothing — it
moves up and advances in the same turn — so what a chain is worth is the held
turns its failures buy. That is a decision and not an oversight: the
alternative, where passing a hop also costs a turn, would put the price of a
chain in its length rather than in what it is made of, and ten junk machines
would be worth ten guaranteed turns.

**Grade 1 is 95% and not 100%**, which is the whole of what keeps a worthless
machine worth backdooring. At 100 a low-grade hop could never fail, so it could
never delay anything, and this document promises a routing backdoor is useful
on any system at all. At 95 one junk hop is worth about a twentieth of a turn
and a full chain of ten has a 40% chance of buying at least one — a chance
rather than an income, which is the right shape for a machine nobody would
otherwise bother with.

**A completed trace burns the whole chain.** A trace *passing through* a
backdoored system does nothing to it — that is what the chain is for. But a
trace that reaches 100% and finds you has followed the path all the way home,
and every system on it is exposed: the backdoors are gone, the systems lock
down and relocate, and the chain is simply not there any more.

**LOSING ONE HOP IS NOT LOSING THE CHAIN.** That is the only case where the
whole thing goes. A hop that is lost for any other reason — somebody else's run
shut the machine, a burn took it, the nightly ageing roll found the backdoor —
**drops out of the chain and the rest of it stands**. A chain is a route and
not a circuit: one relay going dark shortens the road home, it does not open
it. Anything else would make a long chain fragile in exactly the way the
exposure rule already prices, and would punish the holder twice for one loss.

Three things follow, and they are the implementation:

- **A live run whose chain loses a hop keeps running.** The chain it declared
  is re-read against what the character still holds, and the missing system is
  simply not in it.
- **The trace does not gain or lose ground.** `runs.trace_hop` is a position in
  the chain, and a hop once passed is behind it for good. Dropping a hop the
  trace has ALREADY passed moves the position down with it, so nothing it
  fought through is re-fought; dropping one still ahead of the trace leaves the
  position alone and simply gives it one less to get through. Neither case is
  allowed to hand the trace a hop for free or take a passed one back.
- **A chain can empty out entirely**, and that is the ordinary no-routing case
  rather than an error: the trace comes on unopposed from wherever it had got
  to, exactly as it does for a run that declared no chain at all.

That is the cost that replaces latency, and it is a better one. **A longer chain
is more protection and more to lose at once.** A ten-hop chain represents ten
successful backdoor runs, and one bad night takes all of them — which is exactly
why the chain is chosen per run rather than left standing.

### Names

Systems have names as well as addresses, because `A3F91C08` is not something a
player will ever say out loud. A generated name is **what it is and who runs
it**, with a serial to tell it from its siblings: *Data silo - Anansi Data
Weaving 041*. Those are the two questions a player has about a machine they
have just swept, and the word-bag names that came first — *Fast Forge*, *Cold
Vault 041* — answered neither.

**The name is composed, not stored.** Both halves are already columns: `type`
has always been one and `owner_id` arrived with the corps. Storing the sentence
they spell would be storing the same facts twice, free to drift the first time
a corp is renamed. What is stored is `systems.designation`, the one part of the
name that is nowhere else, and `systems.name` becomes the **override**: NULL on
nearly every row, and carrying an authored name where there is one. Renaming a
corporation therefore renames every machine it runs, which is correct — and
renames nothing a player *remembers*, because a contact snapshots the sentence
as it read at the moment of looking.

**Every generated machine carries a serial.** 121 corps by three types is 363
combinations against nearly three thousand machines: unnumbered, a name would
identify about eight systems at once. It is rolled rather than counted, so
nobody can read how many machines a corp runs off the side of one of them.

**Grade is deliberately not part of the name**, though an earlier draft of this
section had it there. A grade is the thing a scan exists to discover — see
*Grades* — and a name that whispered it would hand that away from the map for
free.

Event, mission and quest systems carry authored names. Player-owned nodes are
**named by their players**, which makes them the first player-authored text
other players will read, with everything that implies for escaping and for
policy.

---

## Getting in

### Readiness is the gate, and it is checked twice

`DeckTotals::isReady()` is the whole check — compute, memory, link, power, and
draw within supply. There is no second list.

- **On jacking in.** An unready deck does not enter cyberspace.
- **At the start of every intrusion run.** Cheap, and it closes the window
  between entering and attacking.

**If readiness is lost while jacked in, the player is jacked out and takes 1
point of Synaptic Integrity damage.** Not a warning, not a grace period — the
machine stopped working while they were inside it. Since the deck is locked
(below), the only thing that can cause this is the resolver: a buff expiring, a
drug wearing off, an injury landing. The damage is small because the cause is
usually not the player's fault; it is nonzero because being dumped out of
cyberspace should never be free.

That damage carries **no defeat effect**. No ICE was involved, so nothing
flatlines, surges or erases — at worst it puts the character on the waiting
screen like any other trip to zero.

**`App\Cyberspace\ReadinessWatch` is where this lives, and it is asked in two
places for two different reasons.** The screens that can break a deck from
inside — installing or pulling an implant, starting or stopping a program, none
of which the deck lock forbids — ask it themselves, so the player is told at the
moment it happens and in the same breath as what they asked for. Middleware asks
it on every request from somebody who is jacked in, as the net under everything
else: a modifier that expired on its own, a console command, anything that has
not learned to ask. One rule, two alarms.

**What the net cannot do is wake somebody who is not clicking.** A player who
walks away stays in cyberspace on a dead deck until their next request. Closing
that would take a scheduled sweep, and nothing yet justifies one — but it is
also why the net is not the only caller, and why anything happening *during* a
run must resolve its own ending where it happens rather than leaving it to the
next click.

### The deck is locked while you are jacked in

You cannot fit a module, strip the deck, unequip it, or destroy it or anything
fitted in it while jacked in. Three writers enforce one rule — `ModuleFitter`,
`DeckEquipper`, and the destroy path. Everything else is unaffected: the stash
still opens, other items can still be destroyed, training carries on.

### The operating system is a gate you cannot see

**Every machine runs an operating system, and you need the matching skill at
level 1 to do anything to it.** What makes it a gate rather than a door is that
**nothing ever tells you which one it runs.** A scan is the only thing that
answers that question, and a run against a machine whose OS you do not have is
not refused — it is entered, and then **every action you take against the system
fails.**

**A refusal would be the leak.** "You do not have Lattice" is a scan fact handed
over for one click, for free, with no instrument running and no minutes spent —
so anybody could read the operating system off every machine on the grid by
trying to enter it. The gate has to cost a run, or it is not a gate; it is a
lookup.

**The failures are indistinguishable from bad luck, deliberately.** Nothing in
the log says the commands are not understood, because that sentence narrows the
OS to one you do not have and the whole point is that nothing narrows it for
free. What the player has instead is the PATTERN: every action failing, run
after run, is not what a bad night looks like.

**That signal is a streak, and a streak needs length.** Three failures in a row
is an ordinary afternoon at the certainty floor, so a short poke at a system
teaches nothing and a long haul teaches quickly.

**An interactive run is what makes this legible**, and it is the strongest
argument the turn loop has. A player who suspects something is wrong can spend
another turn to find out, and another — watching a dozen actions fail in a row is
a conclusion, where a log of three failures handed over after the fact is a
shrug. The cost of asking is real, since every turn is trace and heat, which is
exactly the shape this should have: the answer is available and it is not free.

It follows that **nothing may cut a run short on the player's behalf while
everything is failing.** Shortening it is deleting the only evidence there is.

**What fails is what touches the SYSTEM.** Extracting, backdooring, injecting,
burning, and scanning from inside. Everything that happens TO the player carries
on exactly as normal: the IDS still rolls on approach, the tracer still traces,
the ICE still hits, and the deck still cooks. That asymmetry is the fiction —
the room works, and it is the intruder who cannot operate in it.

**Egress is never one of the things that fails.** See below: leaving is always
possible, whatever else is not.

Two consequences fall out of it, and both are wanted. **The raid counter does
not move**, because nothing succeeded — so a machine that beat you this way is
still there to come back to once you have read it. And **there is no cheap probe
here**: finding out costs the approach roll, the turns and the heat, which is
several orders more than a refusal would have cost.

### Scanning

You can go in blind. It carries steep penalties, and against a system several
grades above you it is how characters end.

A scan catalogues what is installed: the operating system, the grade, the IDS,
the ICE, the tracer, and any cloaked systems. **A cloaked system that is
detected is detected** — there is no second roll for it at ransack time. A full
scan means you bring your whole skill and software loadout to bear with no
penalty; a partial one means penalties in proportion to what you did not find.

**Scan data is perishable.** It expires **48 hours** after it is taken, and it
is **consumed the first time it is used**.

**A scan produces a file you can sell**, and that is the whole point of mapping
as a living — safer than intrusion, for people who would rather be paid less and
survive. **It is not a kind of run**: reading a machine happens on the open
grid, from the square you are standing on, and needs no run to be inside of.
That is what makes it the safe trade — there is no core to avoid touching,
because you never went in.

- **Sold to another player**, on the market, it is ordinary goods: the buyer
  gets one use of it and the system is none the wiser.
- **Sold to an NPC buyer**, it is a vulnerability report. They pay, they delete
  it, and **the system resets** — it relocates and takes a new address, exactly
  as though its lockdown had expired. Nobody else can use that scan afterwards,
  because there is no longer anything for it to describe.

That second route is deliberately a *different shape* of payday: guaranteed,
immediate, and it burns the target. A player who maps for NPCs is quietly
churning the grid for everybody.

### The run plan

**A run is INTERACTIVE, turn by turn.** The player picks the target, the
purpose, solo or cooperative, and their software loadout before they go in — and
then plays the run out: each turn they choose what to do, and the server
resolves that turn and waits for the next one.

**This reverses an earlier decision, and it is worth saying what that decision
was buying.** A run used to be atomic — resolved end to end in one transaction,
with the client replaying a log of something already finished. That bought three
things, and each one has to be re-bought here rather than quietly dropped:

| Atomicity bought | What buys it now |
| --- | --- |
| **Pulling the plug cannot save you** | **the run lock** — see below. Signing out does not end a run, it postpones one |
| **No reconnection problem** | the run's own state IS the reconnection. There is a row, it says whose turn it is and what has happened, and coming back means being put in front of it |
| **Preparation is the game** | still true, and no longer the whole of it. The loadout, the deck and the scan decide what you CAN do; the turns decide what you do with it |

The cost is honest and should be stated: **a run is now durable state.** An
atomic run needed no row at all — it began and ended inside one request. An
interactive one is a state machine that has to survive a disconnect, a crash and
a deploy, and every ending in this document has to be reachable from it. That is
the single biggest thing phase 5 gained.

What the reversal is FOR: a bad run stops being something you watch and becomes
something you are in. Loading different software, burning a drug, or deciding
that this is the turn to leave are decisions, and a decision made while losing is
worth more than the same decision made on a planning screen twenty minutes
earlier.

What a turn offers: an action against the system, **loading or dropping
software**, using something carried — a drug, a patch, a one-shot — and
**trying to egress**, which is always on the list. Nothing here is free: every
one of them spends the turn, and a turn is trace and thermal load.

**MAPPING IS NOT A RUN, and scanning is not a turn.** It used to be a purpose
of its own — scan only, never touch the core — and the probe took that job the
day it shipped: a read happens on the open grid, from the square you are
standing on, with no run, no lock and no core to avoid, and it produces the same
sellable file. A second way to do one thing is one of them being wrong, so the
purpose is gone and so is the scanning phase that used to head the turn table.

**You cannot scan from inside, either.** Reading a machine is preparation, and
**a blind run is a commitment** — the decision to go in without a picture is the
decision, and being able to buy the picture afterwards from inside the room
would refund it.

**A scan is SPENT by the run that uses it**, which is what the file always said:
`system_scans` rows are consumed on first use and were built that way before
anything could consume one. So a file is a vulnerability report you can read
*or* sell, never both, and walking into a machine is the moment you choose. The
run that goes in blind spends nothing, which is the only thing going in blind
has ever had going for it.

**In a co-op run the best file covers everybody.** Whoever brought the most
complete picture has it applied to the whole party for that run — counted as
facts known, and settled in the run owner's favour if two files tie. **Only that
file is spent.** Everyone else's stays whole and stays sellable, because it was
not the one that got used, and burning the party's spare copies would make
bringing one a penalty.

**A blind run is warned about, clearly, every time.** Going in unscanned is
allowed and always was — but it must never be something a player did without
noticing, so the screen that starts a run says what is not known before it will
start one. **A partial picture gets the same treatment as no picture**, naming
the gaps rather than counting them: *you have not found out this machine's ICE
or its tracer* is a sentence a player can act on, and "3 of 6 known" is not.

That list is already exact and already computed — `SystemScan::unknowns()` is
the questions a file has not answered — so the warning is a read of the player's
OWN file and leaks nothing about the machine. **It also cannot help them past
the operating system**, which is the point of that gate: being told the OS is
unknown is not being told which one it is.

The purposes:

- **Extraction** — steal what it is holding. Clean, it leaves no lockdown behind
  and the node restocks; caught, it locks down like anything else.
- **Backdoor** — keep access. Never locks the system down, even when the run
  went loudly, and the system stays where it is until housekeeping catches it.
- **Burn** — destroy it. Dead, then empty, then eventually something new.

**A run is roughly 4 to 20 turns**, and knowing that number is what every
thermal figure in the catalogue has to be calibrated against:

| Phase | Turns |
| --- | --- |
| Fighting the ICE | 2–10, if it was ever activated. |
| Extracting or installing | a few, scaling with the size of the data and scaled back down by the deck's bandwidth. |

That last row is the whole economy of a run plan in one line: **a bigger haul is
more turns, and a better NIC buys them back.**

**Those turns are now a typical range and not a bound**, and the change is worth
being explicit about because every thermal figure in the catalogue is calibrated
against them. An atomic run took as many turns as the plan implied; an
interactive one takes as many as the player spends, and a player may spend them
on anything — probing a machine whose commands are not landing, waiting out a
trace, trying egress after egress. **Nothing caps a run, and nothing should.**

What stops one running forever is the deck: heat accumulates per turn and bleeds
off at the radiators' rate, so a run long enough to be free is a run long enough
to cook a module. **That makes thermal accumulation the real limiter on run
length**, which is a better brake than a turn count would have been — it is
bought with hardware, it can be planned around, and it fails loudly rather than
by refusing a button.

### The two thresholds, and what they now decide

- egress if **trace** rises above *x*%
- egress if **Synaptic Integrity** falls below *x*%

**They ACT. They do not warn.** Checked at the start of each of your turns, and
when one is breached the egress is attempted then and there. That is deliberately
not the gentler rule — a threshold that only warned would leave an unresponsive
player sitting in a run that never resolves, and in a co-op run it would leave
everybody else sitting there with them.

**They persist, and every character is born with them.** 70% trace and 30%
Synaptic Integrity, granted at creation like anything else a character starts
with, so a player who has never found the sliders is still carried out of a run
they were losing. They are changed in an **accordion on the cyberspace screen** —
two sliders, open when you want them and shut the rest of the time, because
they are a play style rather than a per-run decision and a screen that asked
every time would train everyone to ignore it.

**They are also the whole of the default-action policy**, which is what makes a
timeout resolvable without the engine guessing at an intention. See *Taking a
turn* below.

**`auto-run` is the same idea, chosen on purpose.** A player may hand the whole
run over to their thresholds: no timer, no prompt, each turn resolved by the
policy until the run ends or a threshold pulls them out. It is the honest
version of walking away — the run resolves at speed rather than crawling one
deadline at a time, and a player who wanted the old atomic run can have one by
ticking a box.

### The run holds you

**A live run is the only thing on screen.** While one is running, every request
that is not the run itself is sent back to it: the map, the stash, the deck, the
market, the admin tools. The two exceptions are **signing in and signing out**,
which have to work so that a session can end and resume at all.

**This is what replaces atomicity, and it is the whole reason the lock exists.**
An interactive run that a player could walk out of is not a risk, it is a menu:
close the tab when the ICE lands a good hit, sign back in, and the bad run never
happened. So signing out does not end a run and does not pause its
consequences — **it postpones a turn, and the turn is still there when you come
back.** There is nothing to escape to.

**It is the shape `RequireCharacter` already has**, and should be built as that
shape rather than as a new idea: one middleware, a named list of what is allowed
through, everything else redirected whatever it asked for. A JSON caller gets
**409 and the URL**, the way both creation guards already answer one, rather
than a redirect a `fetch()` would follow into HTML.

**While a run is live, the readiness net stands down.** `EnsureDeckStillRuns`
ejects a jacked-in player whose deck stopped meeting its requirements — and
inside a run that is exactly the wrong answer, because a burnout or a Surge is
*supposed* to break the deck and already resolves its own ending where it
happens. Two middlewares would otherwise race for the same player, and the one
that knows least would usually win. This is the same rule the deck lock follows
and it is worth stating twice: **the net is for what happens to somebody
standing still, never for what happens to somebody in a fight.**

**Stalling is possible and is its own punishment.** Nothing forces a turn, so a
player who is losing can simply stop — but the lock means stopping costs them
the entire game: no stash, no training screen, no market, no second character,
nothing, until that run is finished. A player who would rather hold a doomed run
open forever than resolve it has chosen not to play. No timer, no scheduled
sweep, and nothing that has to have been running — the same call the readiness
net makes about players who walk away.

### Taking a turn, and running one together

**A turn resolves when everybody has chosen, or when the deadline passes.**
Thirty seconds. A party that is paying attention moves at its own speed and
never meets the clock; a party waiting on somebody who has walked away waits
thirty seconds and moves on.

**A timeout is not a pass — it is the player's own policy, applied.** Continue
the purpose, unless a threshold is breached, in which case egress. That is why
the engine never has to guess at an intention: the player declared one, in the
accordion, and a cautious player gets cautious defaults without configuring a
second thing. **A timeout costs exactly what acting costs** — the same turn, the
same trace, the same heat — because the moment timing out is cheaper than acting
it becomes a tactic.

**An absent player resolves themselves.** Turn after turn of defaults walks their
trace up or their Synaptic Integrity down until a threshold trips and carries
them out. Nobody has to write a rule for abandonment; it falls out of the policy,
and the lock releases when they go.

**The deadline is a deadline and not a scheduled job.** The run screen polls, the
way the reading dialog already polls while it is open, so **the polling client is
the clock**: whichever request arrives first past the deadline is the one that
resolves the absent players and advances the turn. If everybody walks away
nothing resolves and the run sits frozen — which is correct rather than broken,
since the lock is punishing all of them equally and there is nobody being kept
waiting.

**A solo run has no deadline.** The timer is a courtesy owed to other players,
so it exists when there are other players. A solo runner who stops is only
holding up themselves, and the lock is already the answer to that. The
thresholds still act, and `auto-run` is still there for anyone who would rather
not sit through it.

**A PARTY IS A THING YOU TRAIN FOR, and by default you cannot have one.**
Calling a co-op run takes **Coordinated Strike**, and a character without it
runs alone — not as a penalty but as the ordinary state, the same way a
character without an icebreaker cannot fight ICE. Coordinating a strike is a
skill somebody has or does not, and no amount of deck, software or nerve
substitutes for it.

| Skill | Rank | Requires | Grants |
| --- | --- | --- | --- |
| **Coordinated Strike** | 8 | Cyberdecks at level 5 | +1 co-op slot per level |
| **Advanced Coordination** | 16 | Coordinated Strike at level 5 | +1 co-op slot per level |

*Coordinated Strike* — "Allows for cooperative Runs on a system, where several
runners coordinate to strike a single system in concert."

Both maxed is **ten slots**, so eleven runners in one machine. **That is overkill
for anything currently on the grid and is meant to be** — it is built for
machines that do not exist yet, the ones no party of three could reasonably
take. A ceiling nobody needs today is the right shape for a ladder that has to
have somewhere to go; what it must not be is a ceiling that arrives *after* the
machines that need it.

**It is the OWNER'S skill that sizes the party.** Whoever calls the run is who
trained for it, which is what makes the skill worth a rank 8 and a rank 16 slot
rather than something everybody buys one level of.

**`coop_slots` is the stat**, and it is `program_threads` with one difference
that matters: **it floors at ZERO, not at one.** A character who can run no
copies of any program is a character for whom software stopped existing, so that
stat is floored at 1. A character who can call no co-op runs is just a character
who has not trained for it — zero is the ordinary state and the gate, which is
why the base lives in config at 0 and every slot above it is a modifier.

**A big party does not sneak, and it costs no new number to say so.** Every
runner rolls their own approach, and detection wakes the ICE for everybody — so
a party is louder simply because there are more of them, with nothing added to
the machine's difficulty. Against a mid-career runner at grade 5 that is 29%
caught alone, 75% as four, and 98% as eleven.

**A per-runner difficulty increase was measured and rejected.** Two reasons, and
the first is the one that settles it: **at the grades cooperation exists for, the
shape makes no difference at all.** Grade 7 and above is already pinned at
effectively certain detection from a party of two upward under every variant
tried — the machines that need eleven people are machines where being noticed
was never the question. What an added term actually changes is the grade 3-5
band, where a party is a convenience rather than a necessity, and there it turns
a group of four from a risk into a formality. That is not making large parties
loud; it is making small ones pointless.

The second: it would be a third multiplier on a stack this document already
warns about. Detection compounds across ROLLS — *four of them and a clean run is
96.6%, not 99.15%* — a party compounds that across PEOPLE, and a difficulty that
also climbed with party size would compound a compound.

**Only the approach compounds.** The IDS's second roll fires on touching the
core, and only the runner who touches it makes that one — so eleven people are
eleven approach rolls and still a single core roll, and the delicate work goes
to whoever is best at it.

**Two behaviours fall out of this, and both are wanted.** Stealth becomes worth
training, because a party's chance of going unnoticed is the product of its
members' and every weak link is felt by everybody. And **the owner has a reason
not to invite just anyone** — an eleventh runner who is bad at this costs the
whole party more than they bring, which is what makes a party a thing you
assemble rather than a queue you join.

**The trace runs for every runner, separately.** One meter each, advancing on the
same clock — so an egress threshold is your own, a completed trace lands on you
and not on the party, and the routing chain it burns is the chain *you* came in
on. It follows that a backdoor is worth having individually rather than
collectively: the careful runner in a loud party still gets the protection they
paid for, and the one who came in over open ground is the one the heat finds.

**The party forms at the door.** Nobody joins a run in progress — a run is a
plan, the loadouts and the scan were decided against it, and a late arrival is a
different plan wearing the same name.

**One runner leaving does not end it.** They egress, their lock releases, and the
rest play on. What they had already taken is theirs.

**Who gets what:**

- **The owner keeps the files.** The player who called the run is who the haul
  belongs to. Parties settle the rest between themselves, which is what parties
  do.
- **Creds are split evenly, including with the ones who left.** Somebody who
  egressed at turn three took a risk for the same money and is owed their share
  of it; a split that paid only the survivors would make leaving a punishment on
  top of a punishment.
- **If the OWNER leaves and the run still succeeds, the files pass to one of the
  others at random.** Not to whoever is nominally next, because there is no
  ordering that is not somebody's advantage to engineer.

**What this shares with physical combat is the PROTOCOL, not the engine.** They
are two engines deliberately and stay two — but everybody choosing, a turn
resolving when the last of them has or the deadline passes, and an absent
fighter getting their own policy is how a turn is *taken*, not what an action
*means*. When physical combat arrives it takes the same protocol and resolves it
with entirely different numbers.

### More than one runner at a time

**A machine can hold several runners at once, and they are not instanced.** One
world, one node, one shelf — and everything that follows is a consequence of
that rather than a rule on top of it.

- **The first extraction takes it.** Two runners after one file is one runner
  with a file and one looking at an empty shelf. Instanced contents would make
  raiding safe and the restock clock meaningless; a shared shelf is what gives
  concurrency teeth.
- **Both raids count.** A node retires on its third, and two thefts in one
  evening are two thefts — the counter is about the books not balancing, and
  they do not balance twice as slowly because it was busy.
- **Somebody else's run can end yours.** That is the subject of the next
  section, and it is the price of a shared world: a machine you are standing in
  is a machine somebody else can burn.

### A run that stopped being valid

**Some things end a run without anybody in it losing.** The machine is burned
out from under you; the machine locks down because another runner finished what
they came for; the character is left in a state that cannot hold a run at all.
All of them are meant to be possible — a world where they cannot happen is a
world where nobody else is real.

**Validity is asked on the lock, not by a sweeper.** The lock already stops every
request from a player in a run; that interception is where *is this still a run?*
gets asked, so nothing has to have been running and nothing has to be scheduled.
It is the same lazy settlement a matured scan gets, a training catch-up gets, and
the readiness net gets — a player who never comes back needs to be told nothing,
and the moment they come back is the moment they are told.

**THE RUN ROW CARRIES THE ADDRESS, NEVER THE SYSTEM ID.** Exactly the rule
`system_scans` and `system_contacts` already follow, and for exactly the same
reason: it has to outlive the machine. A burned target must be an ordinary
answer — *there is nothing at that address* — rather than a foreign key
violation or a row that cascaded away with the evidence.

**A cancellation is an ending, and it is the one that already exists.** The
player is out, it costs **one flat point of Synaptic Integrity**, and nothing
else happens: no defeat effect, no lockdown, no raid counted, no trace. That is
`ReadinessWatch`'s consequence and its reasoning transfers word for word — small
because the cause was not theirs, and nonzero because being dumped out of
cyberspace is never free.

**What was already taken stays taken.** Extraction moved it; the object is the
evidence. What had not been lifted yet is gone with the machine.

**It is explained, and that is not optional.** A player thrown out of a run they
were winning, by something they could not see, with no account of it, has been
handed a bug rather than a consequence. The message is the notification queue's:
*System experienced a lockdown during your run, and the run was cancelled.*

**The notification centre is a named dependency and not a blocker.** It is meant
to be one place, reached from the rail, with priorities and categories, and it
does not exist. Until it does, **the cancellation screen is the notification** —
the lock guarantees the player lands somewhere, so that somewhere is the
explanation. When the centre arrives this becomes one more thing that emits into
it rather than a rewrite.

### Detection

**Everything hostile can notice you, not just the IDS.** ICE and tracers carry
their own detection values, much lower than an IDS's — so a system with no IDS
but with ICE is a real and ordinary thing, and a quiet-looking system is not a
safe one.

**The IDS gets two chances:**

1. **On approach**, when you arrive.
2. **On touching the core** — burning, extracting, backdooring, injecting. Any
   action against the core, not just the destructive ones.

That second roll is why mapping is safe: it never touches the core, so it never
happens. Detection wakes the tracer and activates the ICE; an undetected run
walks past both — and leaves no lockdown behind it.

**No check in the game resolves to a certainty**, and detection is only the
most visible case. `config/checks.php` holds `certainty_floor`, a float, at
**0.0085**: every roll keeps that much probability at each end however good the
character and however trivial the opposition. The best runner alive still trips
a household IDS about once in every hundred and twenty attempts.

That is not a balance patch bolted on afterwards — it is the rule the whole risk
curve rests on. A mechanic that can be reduced to a certainty stops being a
mechanic, and cyberspace has several loops that would be farmed the moment one
was.

**It compounds, and that is the part to design around.** A run makes several
floored rolls — the IDS on approach, the IDS on the core, the ICE, the tracer.
Four of them and a clean run is 0.9915⁴ = 96.6%, not 99.15%. Across the three
raids a system allows, a flawless player gets through untouched 90.3% of the
time — so roughly one perfect farming attempt in ten still ends in a lockdown.
**The felt risk of a run is therefore a function of how many rolls it makes**,
and that count has to be a deliberate design number rather than an accident of
how the simulator happens to be structured.

At this floor the **raid counter is the dominant brake and the floor is the
tiebreaker**. That is the intended division of labour: good play is not punished
by luck, and a node still retires.

### The fight

Turn-based, automated, and **its own engine**. Physical combat and cyberspace
combat are two engines, deliberately — they share a character and nothing else,
and forcing one abstraction over both would make each of them worse.

**Every turn does four things**, and they are all the same clock:

1. The ICE and the player act.
2. Data on the wire moves, at the deck's bandwidth.
3. Thermal load accumulates — generated by the modules, bled off by the
   radiators, and what is left over stays.
4. The trace advances.

That single clock is the spine of the whole design. A bigger haul is more turns;
more turns is more heat and more trace; a faster NIC buys back turns; a better
radiator buys back heat. **Nothing in a run is free, because everything in a run
costs turns.**

It ends four ways: the ICE is defeated, the player is defeated, a threshold
trips and the player egresses — which is itself a roll, so retreat can fail and
cost a turn — or **the deck burns a module out and throws the player through the
wall.**

> **Built.** `App\Cyberspace\Simulator` rolls it, the `egress` pool is the
> value, `config/cyberspace.egress` the difficulty, and both doors out of a run
> go through the same roll. **Egress Protocols** is the first thing that moves
> the stat — rank 8, behind Cyberdecks 3, +3% of base per level — and it is
> exactly the shape promised above: a row, not a branch. The run screen shows
> the score and explains it, naming whatever contributed without knowing what a
> skill is.

**Egress is always POSSIBLE and never certain**, and those are two different
statements. Nothing can take the attempt away — not an active ICE, not a wrong
operating system, not a trace about to land — because a run with no exit is a
character deleted by a door they could not see. What varies is the chance, and
it is a DERIVED one: the character's attributes, modified by their skills, rolled
against the system. **Leaving a high-grade machine is much harder than leaving a
low-grade one**, which is what stops a deep run from being safe merely because
the player set a cautious threshold.

**An active ICE makes it harder.** The difficulty is the grade, plus a term for
anything currently fighting you — so walking out of a quiet machine and tearing
free of one mid-fight are not the same act, and an ICE is something you have to
get past on the way out as well as on the way in. A machine whose ICE was never
activated is the cheap exit, which is one more thing an undetected run is worth.

It resolves through `App\Checks\CheckRoller` like everything else, so it carries
the certainty floor at both ends — the best runner alive is occasionally held,
and the worst one occasionally walks. The per-grade difficulty is the shape
`scan.difficulty` already uses, and "modified by their skills" needs no code: a
skill that helps you leave is a modifier targeting the egress stat, the way
Overclocking targets `deck_qflops`.

**Accumulated thermal load resets when the run ends.** Heat is a property of a
run, not of a session or of a deck, so the risk is always about *this* run being
too long rather than about a deck being tired. That also keeps the simulation
self-contained: a run needs no state carried in from the last one.

### Burning out a module ends the run

**Thermal load accumulates per turn, so a long fight is a hot deck, and a module
CAN break from heat inside a run.** This is what the tolerance thresholds are
for — `heat_level_warning` and `heat_level_limit` are read against the deck's
accumulated load, and a fight that runs long is the ordinary way a deck reaches
them. A drawn-out ICE fight is therefore dangerous twice over: the ICE is
hitting you, and your own deck is cooking.

**A module that breaks is DESTROYED.** There is no damaged state, no repair and
no degraded mode — the same vocabulary as Surge, which destroys fitted
hardware, and the same as everything else in the game: an item exists or it does
not.

**Which module breaks depends on what broke it**, and the two rules are
different on purpose:

- **Thermal overload takes the thermally weakest module** — the one that
  overheats first, deterministically. It is the part the player was warned
  about, on the screen, before they went in.
- **Anything else that breaks hardware takes a random one.** ICE does not know
  or care which part is closest to its limit.

When the deck freezes:

- **The run ends and the player is jacked out.** Not an egress and not a defeat
  — there is no roll, nothing to retreat from, and the ICE does not get a say.
- **The trace is cancelled.** It was walking a chain to a deck that has just
  stopped existing as a working machine. Nothing to follow, nothing to arrive
  at.
- **The player takes 25–75% of their CURRENT Synaptic Integrity**, rolled, and
  rounded the ordinary way — below 0.5 is 0. At very low current that means no
  damage at all, which is fine and needs no special floor.

  **This can only finish somebody who was already at 1 or 2 SI.** Reaching 0
  needs a roll at or above `1 - 0.5/current` — 50% at 1 SI, 75% at 2, and above
  100% from 3 upwards, which the range cannot produce. That is deliberate
  rather than a hole: the flatline below is what carries the real risk of a
  freeze, and it does not care how much SI you had.
- **A freeze flatlines 12% of the time**, and when it does it deals
  **Flatline I** — 4–10% of maximum SI, the mildest rung, rolled. See
  *Losing*: a burnout is an accident, so it must never take more of a ceiling
  than software built on purpose to.

  The chance is one number rather than the 10–15% the design first said,
  because a probability that is itself rolled uniformly is arithmetically just
  its own mean: rolling 10–15 and then rolling against it is a flat 12.5%. Two
  dice to say what one says. Move the number, do not widen it back out.

  The amount is small on purpose, because **a flatline is permanent and
  flatlines ACCUMULATE** — the one that matters is the fourth, not the first, so
  a single event taking a fifth of a ceiling would end characters rather than
  mark them. `config/cyberspace.php` holds all three numbers so they can be
  calibrated together, against each other and against a survivable run length.

  Permanent damage to maximum SI is an unexpiring modifier row and never a
  subtraction into a column, because maximum SI is derived — see MECHANICS.md,
  *Flatline is a permanent stored modifier*. It deepens the character's ONE
  *neural damage* modifier rather than adding a second — see *Losing*. **The
  stat floors at 1, so accumulated flatlines converge on a maximum of 1 rather
  than 0**: they permanently cripple and never themselves kill. Current SI then
  clamps down to the new ceiling on the next save, the way it always has.
- **No PROGRAM tag fires.** Nothing surges, erases, skims or bounties, because
  the tag set belongs to the program that got you and here nothing got you. The
  flatline above is not a tag firing — it is what a violent ejection does to a
  brain.

**The freeze leaves no new state behind, because it does not need one.** A
module is gone, so the deck is short of what it needs, so `isReady()` says no
and the player cannot jack back in until they replace it. That is the lasting
consequence and it is already expressible — no cooldown, no repair flag, no
column.

**Four different causes damage SI and they must not be conflated:**

| Cause | Current SI | Flatline? | Program tag? |
| --- | --- | --- | --- |
| Readiness lost while jacked in, outside a run | **1 point**, flat | no | no |
| A run cancelled under you — burned or locked down | **1 point**, flat | no | no |
| The deck freezes — a module broke | **25–75% of current**, rolled | **12%**, and it is **Flatline I** | no |
| Defeated by ICE | whatever the tag carries | only via the **Flatline** tag | **yes** |

### The order a bad ending happens in

**Damage first, then the consequence it causes, then the ejection, then the
waiting screen.** Every bad ending resolves in that order, and it is one order
for all of them so that no two endings can disagree about what a player has
left:

1. **The damage lands.** SI, and any hardware the event destroys — a burnout's
   module, a Surge's modules.
2. **The consequence is worked out.** A destroyed module means a deck that may
   no longer meet its requirements; a freeze means one that certainly does not.
3. **It is resolved immediately.** A frozen or unready deck cannot be stood in,
   so the player is jacked out then and there. Not at the end of the turn, not
   on their next request — `Navigator::jackOut()`, as part of the same
   resolution.
4. **Only then, the waiting screen**, if SI or HP reached 0.

The waiting screen coming last is the load-bearing part. A character must never
be sitting on it while still holding a position in cyberspace — that is the one
ordering that produces a state nothing else in the game knows how to read.

**This answers who ends the session on a defeat: every bad ending does, through
the same door.** A defeat, a burnout and a readiness loss all end with
`jackOut()` before anything else is shown.

**A CONSEQUENCE NEVER BECOMES A NEW CAUSE OF DAMAGE WITHIN THE SAME ENDING.**
That is the rule the order above implies, and it is worth stating outright
because the alternative is not merely wrong, it is circular: step 2 is where a
consequence is worked out, and a consequence that generated fresh step 1 damage
would re-enter an ordering whose whole purpose is that no two endings can
disagree about what a player has left.

**So a Surge carries its own tag and nothing else**, and the freeze's damage
belongs to a *thermal* burnout. A Surge destroys fitted hardware, that hardware
may leave the deck unready, and the unready deck is the CONSEQUENCE — it does
not reach back and roll the freeze's 12% flatline on top of a defeat the player
has already paid for. Otherwise whether somebody took permanent brain damage
would come down to whether the Surge happened to destroy their NIC or their
spare RAM, which is nobody's design decision.

**The price is attached to the CAUSE, never to the end state**, and this
document had already decided that twice over before the question was asked.
`ReadinessWatch` handles precisely the state a Surge produces — a deck that
stopped meeting its requirements with somebody inside it — and prices it at one
flat point with no flatline and no tag. The table above is four causes and four
different prices for what is, at the end, the same player in the same chair.

The same answer covers everything shaped like this without anyone asking again:
an **Erase** that deletes the program holding the deck together is the identical
case, and so is anything else that breaks a requirement on its way past.

### Losing

**Reaching 0 Synaptic Integrity is not one outcome, it is whichever outcome the
program that got you carries.** Offensive programs are tagged, and the tag is
what happens. The tag set is open by design — a new consequence is a new tag,
never new code in the simulator:

| Tag | What it does | Levels |
| --- | --- | --- |
| **Flatline** | permanent damage to the character's *maximum* SI | **I-III** — 4-10 / 9-16 / 14-19% of maximum |
| **Neural shock** | damage to current **hit points** — the mind's defeat landing on the body | **I-III** — 8-15 / 15-28 / 28-45% of maximum HP |
| **Surge** | destroys fitted hardware, at random | **I-III** — 45% x1, 45% x2, 55% x3, **rolled per module** |
| **Erase** | deletes software or data held on the deck | **I-III** — one file, half the deck, the whole deck |
| **Skim** | takes carried creds | **I-III** — 10%, 30%, 60% of what you were CARRYING |
| **Bounty** | puts a price on the character — the mechanic comes later, the tag exists now | none, and **it belongs to the MACHINE rather than to the guard**: rolled per system when its ICE is assigned, 10% under ICE I rising to 65% under ICE XII |

**Five of the six come in strengths, and what rises is different for each** —
an amount for Flatline and Neural Shock, a share of what you were carrying for
Skim,
and how much of the deck goes for Erase. `App\Cyberspace\DefeatTag` is the
vocabulary and `config/cyberspace.defeat_tags` holds the numbers, so they can be
tuned against one another the way the freeze's three are.

**Skim reaches what you CARRIED and never what you stored**, which is the same
rule that keeps a node's contents safe from anything that happens to a person.
It is also the only tag a player can act on in advance: banking before a run is
a decision, where a flatline is not.

**Erase III wipes the deck** — every program and every file on it — and is the
most expensive thing in the tag set by some distance. It belongs only on ICE
nobody should meet by accident.

**NEURAL SHOCK TAKES HIT POINTS, and it used to be called Drain and aim at
Synaptic Integrity.** That could never have worked: a tag fires *because*
Synaptic Integrity reached zero, and subtracting from zero is nothing. Aiming it
at the body is what the mechanic was reaching for all along — the feedback comes
back down the interface and stops a heart rather than a thought. It touches
neither maximum; that is Flatline's job, and permanent.

Scaled against a new character's 30 hit points, so Neural Shock III is a third
to a half of somebody's body — enough to finish a runner who walked in hurt, and
not an execution on its own.

**SURGE ROLLS ONCE PER MODULE, not once for the tag.** Surge II is two separate
45% rolls, so it usually takes one module and sometimes takes two or none —
which is a different feel from a single roll that either costs both or costs
nothing, and it is what makes the level read as *how much is at stake* rather
than *how likely a disaster is*. On average: 0.45 modules at I, 0.90 at II, 1.65
at III, where the top rung moves both numbers at once.

**It never needs a special case for a deck with nothing in it.** A deck that is
not ready cannot be in cyberspace at all — `DeckRequirement` wants compute,
memory, link and power — so anybody a Surge can reach has modules to lose.

A Surge does not stack with itself: a piece of ICE carries one, and the level is
which one.

**A BOUNTY BELONGS TO THE MACHINE, NOT TO THE GUARD**, and it is the only part
of a defence that is not a property of what is defending. It lives on
`systems.ice_bounty` and is rolled when a machine's ICE is assigned, at odds
that climb with the PIECE's level — a tenth under ICE I, two-thirds under ICE
XII — because what is being asked is how much trouble a guard of that calibre
implies about whoever installed it.

**So the same piece of ICE on two machines is two different propositions.** One
of them belongs to somebody who answers. A player who has met an ICE XII and
walked away has learned exactly what it hits for and **nothing at all** about
whether the next one will have them followed home — which is the thing a
catalogue-level roll could never have given, since a piece would then either
always report you or never.

No ICE means no bounty: there is nothing to lose to. `cyberspace:tag-ice` writes
what the guards do and never touches this; `cyberspace:defend` rolls it.

**A SCAN READS IT, FOLDED INTO THE ICE FACT.** One question and one roll — a
file that knew there was an ICE XII but not whether losing to it would be
remembered would be describing half a decision. So the answer comes out as
*ICE XII · Bounty*, or just *ICE XII* where the owner does not answer, and a
machine with no guard still reads *None*.

That is also why `system_scans.ice_bounty` is the one answer on that table which
is **not** nullable: `ice_level` already carries whether the fact was read, and a
second nullable column would be a second way to ask one question — free to
disagree with the first.

**FLATLINE IS LEVELLED, I TO III**, the way Surge is, and what rises is the
amount rather than a chance — a Flatline tag fires because the program that beat
you carries it, so there is nothing to roll for except how much.

| Level | % of MAXIMUM SI | Is |
| --- | --- | --- |
| **I** | 4-10 | what your own deck does to you when it cooks. A scar |
| **II** | 9-16 | a bad night carried permanently |
| **III** | 14-19 | the one people warn each other about |

**NOTHING REACHES A FIFTH, AND THAT IS THE RULE AND NOT AN ACCIDENT.** A single
event taking 20% of somebody's ceiling would end characters rather than mark
them, and the whole design leans on accumulation instead: flatlines deepen one
*neural damage* modifier, converge on a maximum of 1, and **permanently cripple
without ever themselves killing**. The fourth one is the one that matters. A
level that broke that would not be a harder flatline, it would be a different
mechanic wearing the name.

**The freeze is Flatline I**, and that is where the level came from rather than
a number of its own. It used to carry 5-15% because it was the only flatline in
the game and had nowhere else to get one; a thermal burnout is an ACCIDENT, and
it should never take more of a ceiling than software built on purpose to do it.
So the mildest rung is the one your own deck stands on.

**Three levels is enough.** The whole usable range is fifteen points wide, and a
fourth rung would be a distinction invisible to the person it happened to.

**A PROGRAM MAY CARRY SEVERAL TAGS.** An ICE tagged Flatline and Surge does both
— it takes a permanent piece of the ceiling *and* destroys fitted hardware — and
the worst software in the game is worst because it carries more than one
consequence rather than because it carries a bigger number. That is what keeps
the tag set the whole vocabulary of losing: a new kind of bad night is a new
combination, never a new special case in the simulator.

**It does not disturb the rule above**, which is worth being plain about because
the two look adjacent. A program with Flatline and Surge rolls **one** flatline —
its own, because its row says Flatline — and destroys hardware, because its row
says Surge. What it never does is roll a *second* flatline on the grounds that
the hardware it destroyed left the deck unready. Every consequence here is
declared by the program; none is inherited from the state the program left
behind.

**Lobotomy was designed and is now dropped.** It stripped skill points from a
random skill, and it was the only tag whose consequences did not stop at the
character sheet: losing points can lose a level, losing a level can invalidate a
skill that gated a module or a program, and the player has to be told all of it
rather than finding out later as a deck that stopped working. A cascade with a
notification design attached, for a consequence the other six already cover
between them. Recorded here so nobody re-derives it and mistakes it for an
oversight.

Underneath all of them: **reaching 0 SI or 0 HP puts the character on a waiting
screen for a minute**, shortenable by implants and skills through the
`recovery_minutes` stat. **You come back on ONE point of whatever emptied** —
getting up is not healing, and the way back from one is Neuroplasticity, a
clinic, or something a player does. It is the floor
that makes every defeat cost something even when the program that beat you was
carrying nothing worse.

**What is in a node is never reachable by any of this.** A defeat takes what the
character was carrying, never what they had stored.

**Flatline is a permanent stored modifier, not a written-down number.** Maximum
SI is derived and must never be stored, so a flatline is an unexpiring modifier
on `synaptic_integrity_max`, floored at 1 the way maximum HP is. Permanent
damage to both HP and SI is reversible later, by means not yet designed. See
MECHANICS.md.

**Every flatline deepens ONE injury rather than adding another.** *Neural
damage* is a single accumulating modifier per character: -5 that takes another
-3 becomes -8, and the row is updated rather than joined by a second. The nerves
do not care what caused the damage, only that there is more of it — which is
what lets a freeze, an ICE flatline and, later, a drug overdose all write into
the same injury without knowing about each other, and lets a clinic heal one
number. MECHANICS.md has the shape.

### Trace, and the heat it leaves

A completed trace puts **heat** on the character. Heat is persistent, it belongs
to the person, and it is what the world knows about them.

**A trace only lands if it completes.** A deck that freezes cancels it outright
— see *Burning out a module ends the run* — so burning a module out is not the
worst of both: it costs a module and a great deal of SI, and it does at least
take the trace with it.

What a completed trace does:

- **Heat on the character.**
- **If the system belongs to a faction**, standing with that faction falls.
  Factions are out of scope for now and this is the hook they arrive on.
- **If the system belongs to a player, the owner learns who attacked them** —
  and that is the *only* way they find out. An untraced raid is anonymous, which
  is what makes stealth worth investing in on both sides of it.
- **The whole routing chain is exposed and locked down.** Every backdoor on it
  is gone. See *The routing chain* — this is the price of the protection.
- **Later: heat draws rogue programs** while moving through cyberspace, and
  especially through subnets.

**Heat decays on its own, slowly.** Doing nothing is a valid way to cool off and
a slow one. It can also be **cleaned** through processes not yet designed —
which is where the money sink is, and the reason heat is a number to manage
rather than a punishment to endure.

Routing through backdoored relays is what slows a trace down, which is what
makes a backdoor run worth doing for its own sake.

---

## The map, and how it is driven

**The map is the player's MEMORY, not a live feed.** It draws the terrain they
have swept and the last thing they saw at each position — so most of it is what
*was* there, and only the current view window is fresh.

**The window is always centred on the player**, which is what makes the whole
interaction work: the eight legal steps are therefore always at the same eight
places on the screen, and the map is already a D-pad without anything drawing
one.

- **Tap or click a cell to step to it.** The eight around you are step targets.
  One gesture, all eight directions, no extra chrome, and identical with a
  mouse or a thumb.
- **Tap anything else to inspect it** — what you remember of it, and the
  actions that belong to it: bookmark the position, jump to the address if you
  know one. A cell with nothing to say is not interactive at all.
- **Arrow keys move orthogonally; the numpad moves in all eight**, 8 for north
  and 7 for north-west, the way every roguelike has done it. 5 looks again
  without moving. Keys are ignored whenever focus is in a text field, or typing
  an address would walk you across the grid.

**A mis-step is the cheapest error in the game** — one step back, nothing
destroyed, nothing confirmed — which is what makes a tap target smaller than
the usual guideline acceptable here. It is not what makes it acceptable
anywhere else.

---

## Decryption

Every file comes off a system encrypted, and breaking one is **a slow background
process, never an action**. You start it and walk away.

- **Cycles of several minutes.** At the end of each cycle one check runs — the
  character's skills and attributes against the file's encryption type and its
  difficulty. Pass and the file is clear. Fail and the cycle starts again. There
  is no partial progress and no accumulating bonus: each cycle is a fresh
  attempt, which is why a bad matchup is slow rather than impossible.
- **One at a time, with a queue behind it.** The queue is ordered and the player
  arranges it.
- **It occupies the deck the whole time.** Compute and memory are consumed while
  a decryption runs, and the file itself sits in storage. So a decryption is not
  free background work — **it is a standing claim on the same budgets a run
  needs**, and starting a run while one is going means starting it with less of
  everything.
- **It generates thermal load**, continuously, for as long as it runs.

**This is the training queue's shape, and it should be built as one.**
`training_queue`, `App\Skills\TrainingDeposit` and `TrainingQueueValidator`
already solve an ordered queue, a scheduled tick, and entries that quietly went
invalid. The one real difference is that training deposits deterministically and
decryption rolls — the tick is a check rather than an accrual.

**The queue has to survive its contents disappearing.** A queued file can be
erased by ICE, sold, destroyed, or lost with the node it was stored in, and the
queue must notice and skip rather than break — the same job
`TrainingQueueValidator` does when it finds a queued skill that was never
injected.

### A decryption can cook a deck

Thermal load accumulates whenever the deck is working, and a decryption is the
deck working. That makes the arithmetic binary and easy to warn about:

- **If cooling covers what the decryption generates**, net is zero or negative,
  nothing accumulates, and the decryption can run for a week.
- **If it does not**, accumulation never stops, and the deck will eventually
  cook its weakest module — not during a run, but overnight, unattended.

**So the screen has to say so before the player starts one**, in the same voice
the deck screen already uses for a loadout that cannot survive: *this deck
cannot sustain a decryption*. A player who loses a RAM stick while asleep should
have been told, in advance, that they would.

### Starting, stopping, and the deck it runs on

- **Startable while jacked in, never stoppable.** You may queue and begin a
  decryption from inside cyberspace; you may not call one off. That asymmetry is
  the one that never traps anybody — a player can always add work and can never
  strand themselves halfway out of a state.
- **Unequipping a deck mid-decryption warns, and then does it.** MECHANICS.md
  anticipated this — *unequipping will one day have to stop long-running
  processes, and jacking out will not*. This is that day: the confirmation says
  what will be lost, and if the player goes ahead the decryption stops and its
  progress is discarded. They start again from the beginning.
- **Cycles do not accumulate, deliberately.** Each one is a fresh attempt and a
  failure carries nothing forward, so what an unequip discards is the current
  cycle and nothing more. The warning is therefore honest about a small loss,
  which is the right shape: unequipping a deck is an ordinary thing to do, and
  it should cost minutes rather than an evening. A file is never *partly*
  decrypted — it is encrypted until the cycle that opens it.

---

## Player-owned nodes

Players can own systems: somewhere to keep creds and data, and eventually
somewhere to run processes unattended.

- **They can be raided by other players.** That is the point of them, and it is
  why holding several is better than holding one — a raid takes what is in the
  node it reached, not what you own.
- **What is in a node is safe from what ICE takes off you.** Losing a fight in
  someone else's system does not reach into your own. That is the trade the
  player is balancing: carried is usable and exposed, stored is safe and remote.
- **Stealth and security are investments**, in skills and in software, on the
  defending side as much as the attacking one.
- **They lock down and relocate like anything else**, and that is fine, because
  the owner is told: a notification saying the node was hit, that it is in
  lockdown, and where it has moved to — **and the owner's bookmark is rewritten
  to the new address**. A player is never made to hunt for their own property.

**Players are never visible to one another on the grid.** No presence, no
positions, no synchronisation — this is not a game that supports two people
being in the same place at the same second, and pretending otherwise would drag
timing into everything.

---

## Subnets

Smaller grids behind relays: military, darknet, AI. Entry needs prerequisites or
an intrusion against the relay itself, and what is inside is different in kind
rather than in degree.

**Rogue programs wander subnets and attack on movement.** This is the one place
exploration is not merely patience, and the reason a subnet is somewhere you go
deliberately rather than somewhere you drift into.

---

## Housekeeping

Cyberspace needs scheduled work, and it is **two jobs on two cadences**, not
one. Both follow `market:seed`'s precedent: `withoutOverlapping()`, no stored
clock, a missed pass is never made up, and a `--dry-run` that prints the whole
decision table and writes nothing.

**Nightly** — the world's slow metabolism:

- **Backdoor discovery.** One roll per backdoor, odds rising with its age and
  with nothing else. A hit removes the backdoor, relocates the system,
  reassigns its address, and **notifies the player who installed it**.
- **Backdoor siphons pay out.** A little from each backdoored system, scaled by
  its grade, straight into the holder's creds.
- **Node inventories restock**, so emptying one is temporary.
- **Heat decays** on every character carrying any.

**Frequently — every few minutes** — lockdown expiry:

- Systems whose 2–6 hour lockdown has run out are relocated and reassigned.
  **Built**: `cyberspace:lockdowns`, every five minutes, `withoutOverlapping()`
  and a `--dry-run` like the rest. A machine that opens but finds nowhere free
  to move to is logged rather than left shut for ever — a full grid is a
  seeding problem, not a reason to keep a machine locked.

The second cannot be folded into the first: a 2–6 hour lockdown that only lifts
at 04:00 is not a 2–6 hour lockdown. It also cannot be lazy — resolved when
somebody next looks — because two systems relocating into the same free position
is a race, and the sweep is what serialises it.

---

## What is not built

All of it. In the order it should be built:

0. Settle this document.
1. **The check layer.** **Done.** `check.*` and `skill.*` stats, the
   derived-stat dependency graph, conditional modifiers, `character_modifiers`
   — the table an unexpiring flatline is a row in — skills as a
   `ModifierSource`, and the die itself: `App\Checks\CheckRoller`, a logistic
   on the margin with one tunable spread. What is left is a caller, and a
   caller needs a difficulty — the first of those is a system's grade, in
   phase 4.
2. **Software.** **Built**: `programs` beside `items`, three costs, a kind that
   gates and modifiers that resolve; the admin editor; the topbar's readout;
   the deck screen's Running and Storage tabs, where a program is stopped
   beside the compute and memory it is spending. A program is running or it is
   not, running spends compute and memory, and owning it spends storage. Still missing: anything that READS the kind — the gate half waits
   on the run simulator, which is what will pass `duringRun` and start an
   icebreaker.
3. **The grid.** Positions, systems, addresses, movement, discovery, the map,
   bookmarks, jumping. No intrusion at all — a walkable, empty world.

   **Built so far**: the coordinate space and its one storage class, addresses,
   the world generator behind `cyberspace:seed`, generated names, both halves
   of discovery — the terrain bitmap and the contact rows — written together by
   a sweep, and the session itself: jacking in against the readiness gate,
   jacking out, stepping, and jumping to an address, every arrival sweeping.
   Bookmarks are built too, both kinds, along with recalling one, and the map
   screen that draws all of it. Systems have owners, drawn from the corp roster
   in WORLDBUILDING.md. The deck lock is built as well. **Phase 3 is done.**
   Losing readiness while jacked in — carried forward, since it was never
   phase 3's — is built too: `ReadinessWatch`, asked both where a deck can be
   broken and by middleware behind it. Nothing can currently cause it, because
   no modifier in the game lowers a deck measure.
4. **Systems and scanning.** Grades, types, owners, node inventories, the probe,
   and the scan file. Still no combat.

   **Built so far**: every machine runs an operating system — a foreign key to
   the skill that gates it, chosen by grade off the rank ladder those four
   skills already form — and the probe reads a machine you are standing on.
   **A pass takes minutes**: about one against a grade 1 and sixteen against a
   grade 9 for somebody with no skill, cut by levels of System Profiling and by
   a better instrument. One press starts a pass; the roll is made when it
   matures, by whoever is standing there then; five passes is the budget and a
   file lasts 48 hours. Grade and operating system
   are what there is to find out, because they are what a system currently
   has: the IDS, the ICE, the tracer and cloaked extras join the list as they
   are built, each a nullable column and a line in `SystemScan::unknowns()`.

   **Node inventories are built too**: every machine holds creds, and on a
   roll a generated FILE — a size in QB, an encryption off the same ladder its
   operating system comes from, and two values with the gap between them that
   makes decryption a decision — and sometimes something from the catalogue.
   All of it scales with grade and leans by type, `cyberspace:restock` tops up
   every emptied node nightly, and `systems.raids` counts raids now —
   `RaidLedger` writes it and the third one retires the node by relocating it.

   **The loot table is built**: `loot_tables`, one row per (type, grade band,
   rarity), and a NULL type meaning any machine. Grades 1-3 see rare at 1% and
   ultra-rare is weight 0 below grade 8 — a wall rather than a long shot. What
   a raid can carry is **data only**: files and programs, never objects, since
   nobody is in the room.

   **Defences are built, less the ICE**: `systems.ids_detection`,
   `tracer_detection` and `tracer_rate`, decided once at creation by
   `App\Cyberspace\Defences` and carried on the row. **Type sets the floor and
   grade rolls the rest** — a silo always watches and always fights, a relay
   always watches and always traces, a compute node always fights and always
   traces and rolls for its detection, which is the machine this document means
   when it says a system with no IDS but with ICE is real and ordinary. The
   probe reads both, and *None* is a finding rather than an empty field.

   Because they are STORED rather than derived, a mission node needs no
   override mechanism at all: a machine that has to be dangerous is a row with
   different numbers in the same columns. `stock_rule` exists because a node's
   contents are re-rolled nightly; defences never are.

   **ICE is built too**, which closes the defence set: a catalogue of twelve,
   three per grade band, carrying integrity, an attack, a hit chance and a
   detection value of its own. `ScanFact::Ice` reads, so the only fact still
   saying *not yet* is cloaking. **Integrity and attack are the numbers to
   expect to move** — a fight is meant to run 2–10 turns and that depends on
   icebreaker throughput, which does not exist — and the twelve climb together
   so the ratio survives being recalibrated. The two ends are checkable
   today: ICE I lands 15% of the time for 7, about a point of Synaptic
   Integrity a turn against a starting character's 31; ICE XII lands 95% of
   the time for 57, which takes somebody at 200 SI down in four turns.

   **Extraction is built too**, which closes this list. `App\Cyberspace\Extractor`
   takes everything a `system_contents` row is holding — creds straight onto
   the character, a catalogue item merged or created the way an archetype
   loadout already is, a generated file becoming one unstackable "Encrypted
   Data Dump" carrying its real numbers as JSON on the row rather than on a
   catalogue entry that would have to pretend every file is the same object.
   Creds and items come out the moment the core is touched; a file costs
   turns to match its size in QB, cut back down by the deck's resolved
   bandwidth — `runs.extracting_qb` is the countdown and `Simulator` spends it
   down one bandwidth's worth per turn, through the ordinary turn cycle, so an
   ICE that woke on the touch keeps swinging on every turn a pull runs.

   **Still missing**: selling a scan, which needs a storefront the market does
   not have.

   ~~**Decide here whether the probe needs a running analyser.**~~ **Decided:
   it does.** Reading a machine takes software, and the software has to be
   RUNNING — an analyser on the equipped deck, spending compute and memory the
   whole time. Scanning is a tool-assisted act rather than a talent, which is
   what makes the first scanner a thing a character wants and a better one
   worth paying for.

   **A version is worth what its row says**, through the two channels every
   program already has: `programs.modifiers` against `check.systemprofiling`
   for a steadier read, and against `scan_passes` for another look at the
   machine before the picture is the one you get. The probe knows about neither
   — one arrives through the resolver and the other through a stat — so a Mk IV
   is an item row and nothing else.

   The analyser is a BACKGROUND program, not a run-only one: mapping happens on
   the open grid. So `$duringRun` still has no caller passing `true`, and the
   simulator remains the only thing that ever should.
5. **The intrusion.** The simulator, the log, extraction, the defeat tags.
   Single-player only — cooperative runs are phase 7.

   **The run is interactive**, so this phase is a durable state machine and a
   lock rather than one long transaction — see *The run plan* and *The run holds
   you*. Every ending in this document has to be reachable from that state, and
   the lock has to be in front of it before the first bad run exists, not
   after: a run a player can walk out of teaches them to walk out of runs.

   **Built so far: all of it, in the narrow sense.** A run begins, detects on
   approach, fights, traces, extracts — a file costing turns against the
   deck's bandwidth the way this document always priced it — applies what a
   defeat's tags carry, and ends — through the waiting screen where the ending
   calls for one. What is NOT built is the richer version of several of these:
   heat accumulates by one flat point with no decay, no thresholds and no
   faction consequence; and the routing chain that would slow a trace down is
   empty, because backdoors — phase 6 — do not exist. Nothing here is a gap in
   the SPINE; every gap is a number or a mechanic phase 5 deliberately left thin
   rather than half-inventing what phase 6 or 7 should decide.
6. **Consequences.** Trace, heat, backdoors and routing, burn, lockdown,
   relocation, housekeeping.
7. **The rest.** Cooperative runs, subnets, player-owned nodes, event systems,
   decryption, glitches.

---

## Open questions

Decided enough to build around, not settled. Resolve them before they acquire
dependants.

1. ~~**Is the raid counter per system or per character?**~~ **Resolved: per
   system.** Three by anybody. The world is shared, so a node is a resource
   other people are also emptying — and a per-character counter would have
   every popular machine raided into the ground by a hundred people who each
   thought they had two left. `config/cyberspace.php`'s `raids.limit` is the
   number, and `RaidLedger` is the only thing that writes the counter.
2. ~~**When does the raid counter reset?**~~ **Resolved: on relocation**, for
   the reason the question already gave — a system that never balanced its
   books would be on a permanent countdown and the grid would grind into pure
   churn. It is not a separate decision from the count, which is why one class
   owns both: `RaidLedger::relocate()` writes the new position, the new address
   and the zero together.
3. ~~**Does the 3% floor apply to every roll or only to detection?**~~
   **Resolved: every roll.** It is a principle, applied once in
   `App\Checks\CheckRoller` rather than decided per mechanic — egress can
   always fail and an attack can always miss. A kind of roll may name its own
   floor in `checks.floors`, which is config with the reasoning beside it and
   never an argument at a call site; the list is empty, and a list that grows
   past a handful means the floor has stopped being a principle.
4. **How much is a mapping payday worth against an intrusion?** The whole run
   type is a design promise about safe income, and the ratio is what makes the
   promise true or empty. It is also what sets how fast NPC scan sales churn
   the grid for everyone else.
5. **How is thermal load recalibrated, concretely?** A run is 4–20 turns, so a
   module's per-turn generation and a module's tolerance are now two numbers
   about three orders apart from where the catalogue has them. Someone has to
   pick a survivable-run length and re-author every module against it.
6. **How does a decryption attempt work?** A single check per file, or repeated
   attempts with a cost? Can a failed attempt destroy the file? It is the one
   place skill turns directly into money, so it wants more shape than "roll".
7. **Where does decryption happen?** It is at leisure and not in a run — but is
   it instant, does it take time like training, and does it occupy the deck
   while it runs?
8. **What are the node types, and do they mean anything mechanically?** Data
   silo, compute node, network relay, player-owned. Only the relay currently
   has a job (routing). The rest are flavour until they decide loot tables or
   countermeasure mixes.
9. **Player-authored node names** are the first player text other players read.
   Length, character set, uniqueness, reporting, and whether an admin can
   rename one.
10. **Does Lattice get prerequisites, and does the OS ladder chain?** Today only
    `IRONCLAD ← Prism-V L3` exists; AuraOS gates nothing and Lattice — rank 16,
    the top — is reachable from a standing start. The encryption skills have no
    prerequisites at all yet, deliberately.
11. **Where does a relocating system go?** **Answered for now: anywhere.** A
    free position drawn uniformly from the whole grid, which is what
    `Grid::freePosition()` already does. Local relocation — a system moving
    within some radius of where it was — is the interesting alternative and is
    parked below rather than decided. Nothing is blocked by leaving it random.

12. ~~**Does an active ICE make egress harder, or only the grade?**~~
    **Resolved: it does.** The difficulty is the grade plus a term for anything
    currently fighting you, so tearing free mid-fight is not the same act as
    walking out of a quiet machine — and an undetected run is worth one more
    thing than it was, since an ICE that never woke is a cheap exit.

13. ~~**What does an interactive run do to cooperative runs?**~~ **Answered:
    they share a run and take turns in it.** A turn resolves when everybody has
    chosen or thirty seconds pass, an absent player is resolved by their own
    thresholds, and nobody is held by anybody — see *Taking a turn, and running
    one together*. The size cap is the run owner's `coop_slots`, trained
    through Coordinated Strike and Advanced Coordination and zero without them.
    And the noise a party makes needs no number: every runner rolls their own
    approach and detection is party-wide, so a party is louder by compounding
    alone — see *Taking a turn, and running one together*. **Nothing here is
    open any more.**

14. ~~**Does a Surge that leaves the deck unready roll the freeze's flatline
    chance?**~~ **Resolved: no.** A Surge carries its own tag and nothing else.
    The general rule it rests on is in *The order a bad ending happens in* — a
    consequence never becomes a new cause of damage within the same ending —
    and it answers the next one of these before it is asked.

    (*Who ends the session on a bad ending* was the question here before, and
    it is settled: every bad ending ejects, through `Navigator::jackOut()`,
    before the waiting screen is shown.)

15. ~~**What does Drain do, given the defeat already took you to zero?**~~
    **Resolved: it spills into the body.** It lands on defeat, takes current
    Synaptic Integrity, and what will not fit comes out of current hit points.
    It touches neither maximum and it does not lengthen the wait — overshoot
    buys damage somewhere else, never time.

16. ~~**Can one program carry more than one tag?**~~ **Resolved: it can.** An
    ICE tagged Flatline and Surge does both, and the worst software in the game
    is worst for carrying several consequences rather than one large number. It
    leaves the ordering rule untouched: every consequence is declared by the
    program, and none is inherited from the state the program left behind.

---

## Ideas, parked

Not questions blocking anything, and not on the roadmap. Written down so they
are not re-derived from scratch later, and so nobody mistakes them for
decisions.

**Give the grid geography.** Today every position is interchangeable: grade does
not vary with where a system is, so a dense cluster of high-value targets is a
statistical accident rather than a place. It could instead be a place —
grade correlating with distance from the portal, or districts laid down by
noise, so a region keeps its character no matter which individual machines
occupy it.

What it would buy:

- **Somewhere to go.** It is what would make 2048 x 2048 mean something other
  than four million interchangeable cells — a frontier worth reaching rather
  than more of the same at a greater distance.
- **A reason to map rather than to raid.** A region with known character is
  information worth selling, which is what mapping for a living rests on.
- **Durable waypoints.** A neighbourhood that is dense *by design* stays dense
  through relocation, so marking one is a lasting investment rather than a bet
  on a cluster holding still.

Why it is parked rather than open: clusters already work well enough without it,
because the grid only moves where players push it. This is an enrichment, not a
repair.

**Relocate locally.** A system moves within some radius of where it was rather
than anywhere on the grid. Clusters would survive being worked through, and
*it moved, but it is still around here somewhere* would become true — which
would quietly make a waypoint the way to re-find a system whose address died.
Relocation is uniform-random to begin with; this is the first thing to try if
that turns out to feel arbitrary.
