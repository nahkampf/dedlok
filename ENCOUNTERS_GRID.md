# Encounters in cyberspace

**Designed 2026-10-10, built 2026-10-12 except for avatars.** What differs from the first design:

- **The realm is the door's, not a column.** `EncounterStart::isGrid()`: `grid_step`, `system` and `run` are cyberspace's, so
  there is no second field to disagree with `starts_on`.
- **Doors built:** `grid_step` (an arrival by a step, a jump or a bookmark, never the jack-in), `system` (the position has a
  system; filters: type, grade range, owner), `run` (after a turn of a live run; filters: grade range, purpose) and `spot`. Base
  chances are `cyberspace.encounter.{grid_step,system,run}_chance`.
- **The spot is a button in the Interact box**, not rolled, pinned to a SYSTEM (`encounters.spot_system_id`, a plain id the editor
  fills from an address, so it follows the machine when it relocates and goes dormant, not deleted, when it is burned). It is
  offered while its prerequisites and flag hold and its per-character cooldown (`cooldown_hours`, remembered in
  `encounter_cooldowns`) has run out. `once` is separate and still the flag.
- **The clock stops** (`App\Characters\EncounterPause`): sweeps skip a held character, scripts and doses are read as at the opening
  and every stored moment moves forward by the encounter's length on close. Crafting credits nothing while held. A fight opened
  inside an encounter is counted in both, which is in the player's favour.
- **`run_end`** is on the ending's node (`kill_run` or `continue_run`), required on every ending of a `run` encounter and refused
  elsewhere. `kill_run` ends the run through `RunStarter::end()` as `RunEnding::Encounter`; a bare close with no ending screen is
  `continue_run`.
- **Outcome kinds built:** `trace` (points, clamped 0 to 99 so it never lands), `wake_ice`, `detected` (the same, and the owner
  minds) and `grid_move` (`ref` = `x,y`; grid doors that are not a run). The first three are refused outside a run encounter.
- **Prerequisites** are shared with the City (`CITY.md`).
- **An encounter may set its own chance** (`encounters.chance`, percent, blank is the door's default): rolled on its own at each opening
  of its door, still adjusted by the resolved `encounter_chance` stat, tried before the door's roll, and left out of the door's weighted
  draw (so weight does not matter to it). Refused on a place or a spot, which are buttons.
- **Not built:** avatars and the fight choice, and a message for `kill_run`.

**The original design follows, kept for the reasoning.** Every number in it is a default the author has not tuned.

## What it is

Something that happens to a character while jacked in: a graph of screens and choices with checks and
outcomes, authored at `admin/encounters`, or a straight fight against an NPC *avatar*. It is the same system as
the City's, not a second one.

- **One system, two realms.** `encounters.realm` is `city` (today's) or `grid`. One editor, one runner
  (`EncounterRunner`), one outcome vocabulary, one `character_encounters` row and
  `characters.current_encounter_id`. A grid-only outcome kind is a case in `OutcomeKind` and a branch in
  `EncounterOutcomes`, like any other.
- **Data, never PHP.** Same rule. If a story needs new PHP the vocabulary has leaked.
- **Nothing is seeded.**

## Doors

**Four doors, one writer: `Encounters::open()`.** Each is a value of `starts_on`, and a `grid` encounter has
exactly one.

| Door | Fires from | Filters on the row |
| --- | --- | --- |
| `grid_step` | `Navigator::arrive()`, after the sweep and beside the hunter roll | a grid box (x/y min and max) |
| `system` | arriving at a system: the sweep, or jacking in to it | system type, grade range, owner |
| `run` | between turns of a run: on a node, or on the way out | grade range, run purpose |
| `spot` | a button, like a City `place`: a coordinate or a named system always offers it | `required_flag`, and `once` or a cooldown |

- **Like `Hunters::spawn()`, opening one is its own write**, OUTSIDE the arrival's transaction: failing to open
  must never take back a step that already happened.
- **The spawn roll is not a check.** A flat percentile, injected and container-bound, exactly as the City's.
  Its chance is the resolved `encounter_chance` stat, with a base per door in `config/cyberspace.php`
  (`encounter.grid_step_chance`, `system_chance`, `run_chance`). `spot` is not rolled.
- **The draw is the same weighted pool**, out of `max(eligible weights, city.encounter.pool)`. Eligible is the
  right realm, door and filters, flag held, not retired, an entry node, and, if `once`, not seen.
- **`canHappenTo()` is realm-aware.** A `city` encounter keeps today's rule (not jacked in). A `grid` encounter
  requires being jacked in, and keeps every other guard: no hunt, no fight, no encounter open, not on the
  floor. A `run` door additionally requires `current_run_id`; the other three require NOT being in a run
  (they open on the map).
- **A hunt and an encounter never overlap.** Both are rolled at `arrive()`: the hunter first, and a spawned
  hunt makes `canHappenTo()` false.

## Prerequisites

**Every encounter, in either realm, may carry prerequisites: conditions that must all hold before it can be drawn,
and the list may be empty.** Designed 2026-10-11; the shape, the kinds and the rules are `CITY.md` (*Narrative
encounters*, *Prerequisites*), and a grid encounter uses the same table and the same judge.

- **Judged with the rest of eligibility**, in the pool `Encounters::open()` builds for a door, so a grid encounter
  that waits for a flag and ten scan files is not in the draw until both are true. `spot` is not offered while one
  is unmet.
- **Kinds that matter on the grid** are the ones the City already has (`flag`, `item`, `scan_files`, `creds`,
  `skill`, `standing`) plus candidates for the grid's own facts, each a case in the one judge and none of them PHP
  in an encounter: `heat_at_least`, `has_backdoor` (on a system, or of a grade), `owns_program_kind`. Which are
  wanted is not decided.
- **Held, never spent**, exactly as in the City. A cost is an outcome.
- **A `run`-door encounter may ask about the run** (the system's grade, its owner, the purpose) through its door
  filters, which remain columns; prerequisites are about the CHARACTER, filters are about the PLACE.

## The clock stops

**Decided 2026-10-10: while a grid encounter is open, the player's own clocks stand still**, as they do in a
fight (`COMBAT.md`, *The clock stops*). The same mechanism, extended, not a second one.

- **Sweeps skip** a character with `current_encounter_id` while jacked in: `characters:regenerate`,
  `drugs:withdraw`, `programs:expire`, `crafting:settle`, `decryption:settle`, `scans:settle`.
- **On close, stored expiries move forward by the encounter's length**: dose `expires_at`, a script's
  `started_at`, a scan's `pass_ready_at`, a craft's and decryption's accrued time. `Fights::finish()` is the
  precedent for doses; the rest follow its shape.
- **`ActiveDoses` judges an ordinary dose as at the moment the encounter opened**, as it does for a fight.
- **A run is turn-based, so it needs nothing**: the trace and the ICE advance only when you act, and an
  encounter between turns costs none.
- **THE WORLD DOES NOT PAUSE.** A lockdown still ends and a machine still relocates. A run cancelled by
  relocation closes its encounter gracefully, because a character carries a `node_key` and never an id, and
  the `run` door's encounter is simply over.
- **The abuse to design against is a pause bought on demand** (open one to run out a trace's last turn).
  Nobody can open one at will: the draw is random, and a `spot` is `once` or on a per-character cooldown
  (`character_flags` with an expiry is not enough; the cooldown is its own column on the live row's history).

## Ending a run: `kill_run` and `continue_run`

**Every ending of a `run`-door encounter says what happens to the run.** Decided 2026-10-10.

- **`encounter_nodes.run_end`** is `kill_run`, `continue_run`, or NULL. It lives on the ENDING screen (`ends`)
  and nowhere else, so a run is never touched mid-conversation.
- **`continue_run`**: the encounter closes, the run is exactly where it was (same node, same turn, trace,
  ICE, loadout, `os_locked`) and the player gets their next action. Nothing in a run is rewritten by an
  encounter that the encounter's own outcomes did not write.
- **`kill_run`**: the run ends, and **it ends through `RunStarter`**, the only writer, with a new ending
  reason (`RunEnding::Encounter`). What that means is the rest of the list:
  - run-only loadout stops (`ProgramRunner::stopRunOnly()`), as at any end;
  - **a pull under way is lost** (`extracting_qb` cleared); **what was already taken stays taken**;
  - **the trace is charged as for any ending** (`chargeTheTrace()`), because heat is charged as a run ENDS;
  - the character stays JACKED IN, standing on the grid at that system's position. It is not a jack-out and
    not a defeat; a defeat is what `WaitingRoom` does when a resource empties.
- **Required on a `run`-door encounter, and refused on the others.** `EncounterRequest` holds both: every
  ending of a `run` encounter must pick one (no default, so an author decides), and a `grid_step`, `system` or
  `spot` encounter may not carry one. NOT a CHECK: `encounter_nodes` cascades from `encounters` (MySQL 3823).
- **A `fight` choice inside a run encounter** ends its win, lose and flee screens like any other, so each of
  those carries a `run_end`. A fight that empties SI or hit points is a defeat and ends the run by
  `WaitingRoom::checkFloor()` whatever the screen says: **the engine, not the author, decides a defeat.**
- **Only an ending decides.** An outcome that wanted to end the run is a screen's arrival outcome plus
  `kill_run`, not a new outcome kind.

## Fights: avatars

**An avatar is an NPC of the grid.** Hunter-style exchange, decided 2026-10-10: not the physical engine.

- **`avatars`** is a row of numbers in `npcs`' shape without weapons: integrity, `attack`, `skill`,
  per-type resistances, drops (`avatar_loot`, physical items only, as for NPCs), `tags`, `entity_id`. Authored
  at `admin/avatars`, one writer (`AvatarEditor`). A fight reads it ONCE into a snapshot, so an edit never
  touches one running.
- **The exchange is `HunterCombat`'s shape**: the player acts first (the strongest running icebreaker's
  `icebreaker_damage` against the avatar's integrity), then the avatar's `skill` is a hit chance and a hit goes
  through `DamageResolver` as `DamageOrigin::Avatar` against `damage.synaptic_integrity.avatar`, so a
  resistance is `all_of: ['damage', 'avatar']`. The loadout is picked by `startBestIcebreaker()`, as for a hunter.
- **A fight is a kind of CHOICE**, as in the City: `fight_avatar_id`, with `win_key`, `lose_key` and
  `flee_key`, no roll and no outcomes of its own. A win's reward is the win screen's arrival outcomes.
- **It writes through what exists**: creds through `Wallet` (`Reason::Fight`), drops through `ItemGranter`,
  standing through `StandingLedger`. Only a win pays or moves standing.
- **Open at build time: a new `avatar_fights` table, or `hunts` generalised** (`ice_id` nullable, plus a
  snapshot). Leaning to generalise, because the lock, middleware and screen exist. Check against MySQL error
  3823, which will not show in the SQLite suite.

## New outcome kinds

Each goes through the writer that already owns the thing. The City's eight (`creds`, `item`, `standing`,
`heat`, `flag`, `injury`, `damage`, `dose`) work unchanged.

| Kind | Writer | Realm |
| --- | --- | --- |
| `trace` (± turns) | `Simulator`, the run's `trace_hop` and counter | `run` only |
| `wake_ice` | `Simulator::rouse()` | `run` only |
| `detected` | the run's IDS state, through the simulator | `run` only |
| `grid_move` | `Navigator`, to a position | any grid door |

- **`EncounterRequest` refuses a run-only kind in a non-`run` encounter**, naming the row, as it does for an
  item outcome with no item.
- **`damage` can empty a resource**, so a choice that dealt any asks `WaitingRoom::checkFloor()` last, as in
  the City.

## State and the locks

- **`EnsureEncounterIsResolved` already owns an encounter's screen.** Grid encounters add to the same
  middleware: the encounter routes are in the run lock's and the hunt lock's allow-lists, and the run lock
  stands down for an open one exactly as `EnsureEncounterIsResolved` stands down for a live fight.
- **Jacking out, egress and every other way out are refused while one is open**
  (`RunRefusal::InEncounter`, `TravelRefusal::InEncounter`). The player can only answer.
- **`character_encounters` gains `run_id`**, a plain column and never a foreign key (a run is remembered, not
  owned), so closing knows which run to continue or kill and a relocated machine closes it cleanly.
- **One transaction per choice**, on the locked character and the locked live row, as in the City. A choice
  belongs to the CURRENT node.
- **No inbox message.** A grid encounter opens on a press, so nobody was away and there is nothing to say.

## The editor

`admin/encounters` gains a realm switch. Choosing `grid` shows the door and its filters instead of the City
ones. Refused on top of the City's rules (`EncounterRequest`, `EncounterGraph`):

- a `run` encounter with an ending that has no `run_end`, and any other door with one;
- a run-only outcome kind outside a `run` encounter;
- a `grid_step` with no box, a `spot` with neither coordinate nor system, a grade range with min above max;
- a fight choice whose avatar has been deleted (the avatar restricts deletion while one points at it).

## Build order

1. **Realm and doors.** Migration (`realm`, new `starts_on` values and filters, `run_end`, `run_id`),
   realm-aware `canHappenTo()`, `grid_step` and `spot`, the editor's switch, the new rules.
2. **The clock stops.** Sweeps skip, expiries move on close, `ActiveDoses` as-of.
3. **`system` and `run` doors**, `kill_run` / `continue_run`, the lock ordering. Test both endings through
   `RunStarter` and a relocation under an open one.
4. **The four new outcome kinds** and their editor rules.
5. **Avatars** and the fight choice. The balance pass waits for authored avatars, as combat's did.
6. **Docs.** A section in `CYBERSPACE_RULES.md`; `CITY.md` links here; `CLAUDE.md` gets an *Encounters in
   cyberspace* entry once something is built.

## Not decided

- What `kill_run` should say to the player. The run screen needs an ending panel (like burn's) and a
  `strings.cyberspace.encounter.*` line; the wording is the author's.
- Whether a `continue_run` encounter may itself have charged the run a turn. Today it does not: an
  encounter is between turns and costs none.
- Whether `spot` cooldowns are per character or global. Per character is assumed.
- Avatars as hunters: whether a high-heat hunt may draw an avatar rather than an ICE.
