# Cyberspace rules

Moved out of CLAUDE.md on 2026-10-03 to keep that file readable. The rules are unchanged and
still binding; read the relevant section before touching cyberspace, runs, hunters, the
waiting room, recovery or missions. Design intent lives in `CYBERSPACE.md`.

### Cyberspace

**`CYBERSPACE.md` is the document.** Phase 3 (the grid) is done. Runs, the fight, the trace
and extraction are built. See below for what is not.

#### The world

- **`App\Cyberspace\Grid` is the ONE place that knows how the world is stored, and nothing
  else may query `systems` by position.** Materialised rows today, a generator with
  override rows at 2048×2048 later.
- **A position is where something is; an address is what answers.** Eight hex characters,
  random, unique and MEANINGLESS. **Addresses do not survive** — a burned system takes its
  address out of existence, a relocated one comes back with a new one, so everything
  storing one must treat "nothing there" as ordinary.
- **Discovery is two records and one act.** Terrain is *I have looked here*: a bitmap, 512
  bytes per 64×64 chunk entered. `App\Cyberspace\Chunk` is the only arithmetic turning a
  position into a byte and a mask, and nothing else may touch `character_terrain.bits`.
  Contact is *what was standing here*, and it is allowed to be wrong.
- **`system_contacts` is a SIGHTING and carries no `system_id`** — it must outlive the
  machine. Rewritten in place inside the swept window, so a position now empty FORGETS.
  `SystemContact::standingIs()` asks "is this still true?" and none of its three answers is
  an error.
- **`App\Cyberspace\Navigator` is the only writer of `characters.grid_x`/`grid_y`.** Having
  a position IS being jacked in — no session row, no flag. **Every arrival sweeps**, in the
  same transaction as the move.
- **A deck that stops running throws its owner out**, for 1 point of Synaptic Integrity.
  `App\Cyberspace\ReadinessWatch`, asked in TWO places: **in place** by the screens that can
  break a deck from inside (programs — implants stopped being one when surgery moved to
  clinics, which a jacked-in character cannot reach), and by **middleware**
  (`EnsureDeckStillRuns`) on every jacked-in request. The order is the one every bad ending
  uses: damage, consequence, `jackOut()`. **It must never become the answer for something
  during a run.**
- **Damage is RESOLVED.** `App\Stats\DamageResolver` is the door;
  `Character::takeDamage()` is the dull half underneath (subtract and floor) and calling it
  directly skips every rule.
- **A waypoint bookmark may only be set on swept ground** —
  `App\Cyberspace\BookmarkKeeper`, since no CHECK can reach a bitmap in another table.
  Without it, coordinates become tradeable. **A SYSTEM bookmark has no such gate**, because
  an address carries no position.
- **Every machine has an owner, and `entities` arrives by migration.** The eight hardware
  manufacturers in WORLDBUILDING.md are deliberately NOT entities — who BUILT a thing and
  who RUNS it are different questions. `entities.category` is a fixed vocabulary read as
  `App\Entities\EntityCategory` (corporation, government, military, fringe), and
  **`entities.interactable` is the whole difference between scenery and somebody you can
  work for** — false by default, the call `archetypes.playable` makes. Standing, the
  Exchange, missions and the loyalty store are all built.
- **Standing is what an entity thinks of you, and `App\Standing\StandingLedger` is its
  only writer.** Two tables: `entity_books` (one row per character and entity, standing as
  `DECIMAL(6,3)` and loyalty as an integer) and `entity_ledger` (immutable, one row per
  change, both kinds in one table). **THE BOOKS ARE ALWAYS THE SUM OF THE LEDGER** —
  clamping trims the DELTA rather than the total, so an act against somebody already at
  −100 writes a delta of 0, which says honestly that it changed nothing. `standing:verify`
  asks; `standing:drift` takes 0.25% of what is carried toward zero nightly at 05:20 and
  writes a `Decay` entry for every move. **Flavour entities keep no books**: a write
  against scenery is a no-op, not a refusal. Loyalty never decays and is spent at the
  store. The hooks are in `Simulator` (noticed, raided, burned — the burn
  BEFORE `Burner` deletes the row) and `RunStarter::chargeTheTrace()` (traced, −0.05 × the system's grade, once
  per completed trace, never beside a landed burn) and `cyberspace:age-backdoors`.
- **An Exchange is a system that is not one.** `SystemType::Exchange`, with an `exchanges`
  row carrying grade 1–5, the agent's name and `min_standing`. **That grade is NOT
  `systems.grade`**, which stays at its floor: one means what is on offer, the other means
  difficulty, and the difficulty column is what scans, loot and the OS ladder read.
  **`isInteractable()` split into four** the day this arrived, exactly as its docblock
  predicted — `isInteractable()` (a run), `isScannable()`, `isDefended()` and `isOwned()`,
  which is the one an Exchange answers yes to. Placed by `cyberspace:exchanges`, a TOP-UP
  run by hand: higher grades sit deeper, and a desk that exists is never moved, shut or
  re-rolled. **ONE GATE, NOT TWO** — `exchanges.min_standing` (0/5/20/40/60 by grade) is
  read by the work and the shelf alike. `/exchange` is the Missions & Loyalty screen,
  reached by standing on the desk. The board is real — see *Missions* below — and so is
  the store.
- **The loyalty store is `entity_offers`, and `App\Standing\LoyaltyExchange` is the only
  thing that spends loyalty.** A row is an entity, a grade span, a price and EITHER an item
  OR a service — `App\Standing\Service`, something the entity DOES and hands nothing
  over for (`ShedHeat`: `service_amount` points of heat off, floored, `bounty_word_at`
  untouched). A fixed vocabulary like `Drugs\Family`: a new service is a case. **Item XOR
  service is not a CHECK** — `item_id` cascades and MySQL refuses a CHECK on such a column
  (3823), so `EntityOfferRequest` holds it. A service that would do nothing is refused
  (`LoyaltyRefusal::NothingToDo`) rather than charged. Lockjaw's 1000-for-10-heat arrived
  by migration.
- **A name is composed, never stored** — `<Type> - <entity> NNN`. `systems.name` is the
  authored-name override (NULL on nearly every row) and `systems.designation` the only
  stored part. `App\Cyberspace\SystemNamer` decides.
- **`GridSeeder` tops the world up TO a target**, like `MarketSeeder`. Occupancy leaves
  ~30% empty on purpose — that reserve is what relocation moves through.
  `cyberspace:seed` is run BY HAND, not scheduled; `--dry-run` writes nothing.
- **Every machine runs an operating system, as a foreign key to the skill that gates it**
  (`systems.os_skill_id`). Which one follows the machine's grade via the skills' RANK.

#### Scanning

- **Scanning is `App\Cyberspace\Probe`, and it reads the machine you are standing on.** No
  target in the request. One press starts a READ; a pass rolls once per thing not yet
  known. **There is no pass budget** — a failed scan costs the expiry window and an
  analyser's compute for the length of it, not a retry.
- **A read goes round by itself** until the picture is whole or the FILE GOES STALE. The
  window in `cyberspace.scan.lifetime_hours` is the whole budget, and it is what bounds
  the catch-up walk. Stopping the scanner ends the read — the one thing the player chose.
- **A pass takes MINUTES** — one against grade 1, sixteen against grade 9, scaled by
  `scan_duration`. **Spent when BEGUN, rolled when it MATURES.**
- **Settling is lazy**, and **a READ CATCHES UP**: `Probe::settle()` walks the clock
  forward, each pass beginning when the previous MATURED, so an hour away is an hour of
  reading. It terminates because every turn either stops the read or begins a strictly
  later pass. **A lost pass stops the loop** — spending the next one is the player's call.
- **`cyberspace:settle` sweeps every five minutes and is NOT what makes a read finish.**
  What it buys is a row that is TRUE between visits. **Do not delete the catch-up because
  the sweep exists.**
- **Losing a read has two causes that must not share a sentence** —
  `App\Cyberspace\VoidCause`: the instrument stopped, or the machine burned/relocated.
- **The top bar's reading chip ticks on a JS timer** (`[data-pass-clock]`, seconds remaining and total from the server,
  never an absolute time), moving its bar and time each second and reloading shortly after the pass ends so the page
  settles it. The read dialog, which polls, is the other place the bar moves.
- **A read is a screen you watch.** The `<dialog>` in the layout polls
  `cyberspace.reading` while open, and the endpoint settles a matured pass on the way past.
  It polls only while open.
- **A read runs in the BACKGROUND and belongs to the instrument** —
  `system_scans.reading_process_id` points at the running scanner, so the read survives
  walking away or jacking out. **Settling is per character** (`settleAll()`).
- **Two instruments read two machines.** `ProbeRefusal::AllBusy` is distinct from having
  none.
- **An interrupted read is a lost read.** The analyser must have run for the WHOLE pass:
  `inventory_items.started_at` later than the read began answers it without recording
  history. Swapping analysers fails the same test. A voided pass is still SPENT.
- **`Probe` is bound in the container**, so a test can pin its dice and have the SCREENS
  use them (the map settles during `index()`).
- **Reading a machine takes software, and it has to be RUNNING.** **What a better version
  buys is data, not code**: `programs.modifiers` against `check.systemprofiling` and
  `scan_duration`. Analysers are BACKGROUND programs.
- **A scan is a file that perishes.** `system_scans` is one row per character per address,
  no foreign key to `systems`. Columns, not a JSON bag; null means *not found out*. Live
  means unspent and unexpired (`scopeLive`); a stale file is replaced, never continued.
- **`App\Cyberspace\ScanFact` is the vocabulary of what a scan asks**, and it lists the two
  nothing can read yet (ICE, cloaked) as *not yet* rather than hiding them. `isKnownOn()`
  reads COLUMNS; `readFrom()` may reach through a relation (asking a relation whether a
  scan is complete says no to one that just answered — Eloquent caches the null).

#### Defences

- **Decided ONCE, at creation, and stored.** `App\Cyberspace\Defences`: **type sets the
  floor and grade rolls the rest.** A silo always watches and fights, a relay always
  watches and traces, a compute node always fights and traces and rolls for detection — so
  every type guarantees two and rolls one, and a scan answers *which one is it missing*.
  The portal carries nothing (`SystemType::isInteractable()` already says no).
- **`cyberspace:defend` arms a world that predates its own defences.** A **back-fill, not a
  re-roll** — a machine already carrying something is left alone. A command rather than a
  migration, because it ROLLS. Run once on 2026-09-16.
- **This is why a mission node needs no override mechanism** — nothing derives a defence on
  read, so a dangerous machine is a row with different numbers. `systems.stock_rule` exists
  because CONTENTS are re-rolled nightly; defences never are.
- **Everything hostile can notice you.** A tracer carries its own detection value (a fifth
  of the IDS at grade 1, two-thirds at grade 9), so a machine with no IDS is quieter rather
  than safe. Detection is the EVENT, not the instrument.
- **Zero means "has none"; null means "not looked".** On `systems` the three defence
  columns are NOT NULL and 0 is absence. On `system_scans` they are nullable — **a scan
  that comes back clean has told the player the most valuable thing it can.**
- **A tracer is one thing, so it is rolled for once.** `tracer_detection` and `tracer_rate`
  are zero together or positive together (`chk_systems_tracer`).
- **ICE is a POINTER; the other two defences are numbers.** `systems.ice_id` points at an
  `ice` row (integrity, attack, hit chance, defeat tags). The catalogue **arrives by
  migration**.
- **`skill` is a hit chance 1–100, and a MISS DOES NOTHING AT ALL** — separate from
  `attack` rather than averaged in, because an average makes every fight the same fight.
- **A scan remembers the LEVEL, never the row.** `system_scans.ice_level` has no foreign
  key. Null is *not found out*, 0 is *there is none*.
- **`Defences` is handed the catalogue, it does not query for it.** An empty list is not an
  error.
- **A bounty belongs to the MACHINE, not the guard.** `systems.ice_bounty`, rolled when ICE
  is assigned, odds climbing with level (a tenth under ICE I, two-thirds under ICE XII). A
  scan reads it folded into the ICE fact — the one answer on `system_scans` that is not
  nullable.
- **TWENTY TURNS IS NEVER ENOUGH.** Every icebreaker does strictly less than would break
  its matching ICE in twenty turns. `DefencesTest` pins it.
- **So fighting is gated behind training**, which is the intended reading of "the bottom of
  the grid is walkable" — walkable, not fightable. Icebreaker I needs thirty turns against
  ICE I, which deals ~1.05 SI a turn against a starting pool of 31.
- **Integrity and attack are BASES and expect to move.** A fight should run 2–10 turns; the
  twelve pieces climb together so the ratio survives recalibration.
- **Damage climbs on a FLAT curve: attack 7 → 13, hit chance 15% → 50%.** A fight's cost is
  attack × hit × swings, and fights last 6–16 turns (integrity is pinned by the twenty-turn
  rule), so both had to come down — the old curve grew damage a turn fifty-fold against a
  pool that grows three-fold. Modelled with the matching icebreaker and Attack Vectors 5: a
  fight costs roughly a sixth of a starting pool at ICE I and nearly a whole maxed pool at
  ICE XII, about halved by every ICE resistance at once
  (`2026_09_29_190000_ice_hits_on_a_flatter_curve`). **Reactive Firewalling is −5% a level**
  (raised from −2.5% the same day, `2026_09_29_200000_reactive_firewalling_retuned`) — the
  smaller figure rounded away to nothing at level 1 against every grade once attack shrank
  this far; mind the floor whenever a percentage skill targets a stat this small.
- **An icebreaker is a GATE before it is a number** — `icebreaker_damage` floors at 0. Its
  bite is a modifier on `programs.modifiers`. **It also floors at every point above zero**
  — `icebreaker_damage` rounds DOWN, so a percentage modifier needs a full point of headroom
  to show anything, not half: **Mitigation Protocols** (rank 16) did nothing at levels 1–2
  against any icebreaker, so it is now `add +1` at levels 1–2 and `percentage_of_base` +4/+4/+6
  at 3–5 (`2026_09_29_210000_mitigation_protocols_below_the_floor`), the same flat-then-percent
  shape Attack Vectors already used for the same reason.
- **`check.<slug>` no longer floors either** — `App\Stats\SkillStats` rounds it `'none'`,
  because both readers (`CheckRoller`, the OS roll) take the float and nothing ever
  truncated it on purpose; flooring only threw away a fraction of every small check modifier.
  The OS table (`cyberspace.os.values`) is back to its original decimals now that nothing
  forces it to whole numbers.
- **`synaptic_integrity_max` floors too, same trap.** Neural Sheathing at +1%/level did
  nothing until level 4 for a typically-built starting character. Raised to +5%/level
  (`2026_09_29_220000_neural_sheathing_retuned`) — enough to move even the lowest legal
  attribute spread by a point at level 1.

#### Node contents

- **What a node holds is its OWN inventory model.** `system_contents`, not the character
  inventory with a different owner column: raided rather than carried, safe from ICE,
  restocked on a clock. Three kinds in one table (`creds`, `item`, `data`) with a CHECK per
  kind.
- **A file is an object, not a reward.** Size in QB, an encryption type, and TWO values —
  sealed and broken. The gap is why decryption is a mechanic. Files are **generated, never
  authored**, which is why they are rows here rather than `items`.
- **`App\Cyberspace\NodeStocker` is the only writer, and it TOPS UP.**
  `cyberspace:restock` runs nightly with `--dry-run`; the brake on the loop is the raid
  counter, not scarcity.
- **A node's items are a draw of NONE TO SEVERAL**, rolled again after each hit and capped.
- **Nothing PHYSICAL ever comes off a machine** — the shelf is `on_market` items whose
  `container` is `data`. `NodeStocker::stockableItems()` is the one thing about node loot
  that is not data.
- **`loot_tables` is the weighting, and it is rows rather than config** — one row per
  (type, grade band, rarity), `weight` out of the band's own total, and **a NULL
  `system_type` means any machine**. `LootTable::weights()` is the read (never `all()`) and
  `covers()` the question.
- **The bands are the design and both ends are hard.** Grades 1–3 draw rare at 1%, and
  **ultra-rare is weight 0 below grade 8** — a wall, not a long shot. A rarity with no items
  behind it is skipped.
- **A file is labelled for the machine it came off** (`strings.cyberspace.files.words` per
  type) — flavour, not mechanics.
- **`systems.stock_rule` is how a machine is TOLD what to hold**, NULL on nearly every row.
  `fixed`, `any_of` (drawn without replacement), `plus_random`. **A rule replaces the
  draw.** Items only — creds and files are still rolled, since a mission node holding no
  money would announce itself.

#### Raids, lockdown, relocation

- **`systems.raids` is on the SYSTEM, not the character.** Three successful raids retires
  the node. `App\Cyberspace\RaidLedger` is the only writer of that counter and the only
  thing that moves a machine once placed.
- **A RAID IS A RUN, not a haul.** Counted once, the first time a run takes anything —
  `Simulator::recordTake()` is the one door, which is why `runs.extracted` is written there.
  Counted when the take LANDS, because the shelf is empty from that moment whatever happens
  to the person carrying it.
- **Retiring is relocation, and it happens in `RunStarter::end()`** — the one door out of a
  run, asked on EVERY ending. **Not while anybody is inside**: a live run holds the machine
  in place, because relocating out from under a run is `RunEnding::Cancelled`.
- **`App\Cyberspace\Lockdown` is the window, and the only writer of
  `systems.locked_down_until`.** A violated machine shuts for 2–6 hours, nothing may run it,
  and the window's close relocates it. **Two triggers, not four**: the run was noticed
  (`runs.detected` covers both the IDS and the ICE, since detection is what wakes ICE), or
  the raid counter is spent.
- **It fires as a run ENDS**, so it can never reach the runner who caused it. A machine
  already shut is left alone — a lockdown is what the machine DOES, not a sentence that
  accumulates.
- **Anybody else still inside is thrown out** — `RunEnding::Cancelled`, one flat point of SI
  through `DamageOrigin::RunCancelled`, no defeat effect, no raid, no trace. **The floor is
  asked anyway.** `Simulator::resolveEnding()` owns it.
- **A cancellation gets its own damage origin** even though it costs what a readiness loss
  costs, because an origin is the axis a resistance is written against.
- **EXPIRY IS SWEPT AND NOT LAZY — the one place cyberspace breaks its own rule.** Two
  machines relocating into one free position is a race, and `cyberspace:lockdowns` (every
  five minutes) serialises it. A machine that opens with nowhere free is logged and left.
- **The map draws a lockdown from MEMORY** — `system_contacts.locked_down_until` is what you
  SAW.
- **Relocation is `RaidLedger::relocate()`**, public because lockdown and burn end there
  too. New position, new address, `raids` back to 0. Bookmarks break, scans stop describing
  anything, the sighting is forgotten by the next sweep — none of it an error.

#### Backdoors

- **A backdoor is ACCESS, so `backdoors.system_id` is a real foreign key** — the opposite
  call from contacts, scans and runs. A memory of a gone machine is still a memory; access
  to one is nothing. It is also what makes the promise true: a backdoored machine does not
  lock down and does not relocate. `App\Cyberspace\Backdoors` is the only writer.
- **The KIND is permanent and `install()` will not reroll it.** A machine siphons or it
  routes. Going back in changes nothing — a silent overwrite would convert a siphon into a
  routing hop for free. Chosen at the DOOR, on `runs.backdoor_kind`.
- **A landed install is the only thing in cyberspace that cleans up after itself.**
  `runs.backdoored` means no lockdown **even if the IDS fired and the ICE woke**. A FAILED
  install cleans up nothing. It moves no raid counter either.
- **What holding one buys is exactly half the risk.** The approach roll is SKIPPED (skipped
  rather than discounted — an untuned number would be a second thing to balance). It buys
  nothing at the core.
- **`Simulator::touchTheCore()` is that second look, shared** by extraction and install.
- **FOUR THINGS TAKE A BACKDOOR.** Three are somebody's fault, one is nobody's.
  `Backdoors::loseAllOn()` is asked by `Lockdown::shut()` and `RaidLedger::relocate()`; a
  BURN needs no entry (the cascade handles it). **A stranger's bad night costs you a door
  you were careful with.**
- **A siphon pays `(3000 × grade) / 100` a night** — `cyberspace:siphons`, run BEFORE the
  ageing roll. Deliberately modest: a siphon is the SAFE half of a portfolio.
- **The base is the MACHINE'S, the modifiers are the PERSON'S.**
  `App\Cyberspace\SiphonPayout` hands the per-grade figure to the resolver as
  `siphon_yield`. That stat is deliberately NOT in `stats.bases` — there is no such thing as
  a character's siphon yield, so `Character::stat()` throwing for it is right.
- **Paying out is not a write to `backdoors`**, so it does not live on `Backdoors`.
- **The nightly ageing roll is nobody's fault.** `cyberspace:age-backdoors`, one roll per
  backdoor, **odds rising with AGE and nothing else**. A hit moves the MACHINE, taking every
  other door with it. The player is not told — logged until there is somewhere to send it.
- **The backdoor mark is the one thing on the map that is NOT memory.** `MapView` reads
  positions off the machines the rows point at (not the positional query `Grid` reserves).

#### The map

- **The map screen draws from memory and never from `systems`.** The eight cells around you
  are buttons; arrows and the numpad reach the same eight, because a diagonal is one step.
- **A cell says up to six things at once, each on its OWN channel.** **Ground** is terrain
  (fog below the board with a weave and no hairline, swept ground on it with one — a layer
  difference, not a shade). **The glyph** is the kind of machine. **The hairline** is its
  condition (dashed while rebooting). **The corners** are what you have on it: bookmark
  top-right, backdoor bottom-left in ember, **scanned bottom-right in `--info`** (a machine
  you have read something OUT of, so an empty file marks nothing). **A bar at the foot** is
  the scanned grade. **Colour says type and nothing else** — steel, cyan, jade on the GLYPH,
  never a fill.
- **A cell has two pseudo-elements and four marks**, so the grade bar and the scanned tick
  are BACKGROUND LAYERS composited through `--cell-grade` and `--cell-read`. A rule setting
  `background-image` outright would take the other with it.
- **The scanned mark clears itself**, keyed by ADDRESS like the grade.
- **An UNSCANNED grade is not visible from the map** — a sweep is free. A scanned one is
  drawn, and only a live `system_scans` row can supply it.
- **The window is capped at what anybody could ever see.** `map_range` is 7 because
  `view_range` is 2 and the modifiers meant to widen it are worth ~+5. **A cell is 60px**;
  the map scrolls and centres on the player. A bare character's lit square is 5×5, exactly
  what a 360px phone shows.
- **The key sits beside the map as its own collapsible rail.** `.gridmap-frame` reserves the
  collapsed width and `.maplegend` OVERLAYS the map's right edge — the map re-centres on
  scroll, so a column that pushed it narrower on hover would move the ground under the
  cursor. Three ways in, the dock's three. **Below 720px it drops under the map and stays
  closed.** The frame declares `--key-w`, because a parent cannot read a property set on the
  child.
- **What is outside the current view range is dimmed, not hidden** — `cell--distant` at
  **.55** (it was .8, which is invisible when most cells are distant). Nothing is lit while
  jacked out.
- **An ordinary machine has no hairline.** A hairline is only ever the portal, a machine
  that has just appeared, one rebooting, or you.
- **Two things move, and differently on purpose.** The portal BREATHES between bone and
  brass over four seconds (one portal in the world). A new machine has a light TRAVEL round
  its hairline in its own type colour. A pulse and a sweep read as two statements; two
  pulses read as neither. `prefers-reduced-motion` stops both.
- **A new cell keeps its type colour** (bone was wrong twice), and phases are staggered by
  position — forty synchronised sweeps is a disco.

### Runs

**The spine, the fight and extraction are built.** Detection, ICE, the trace, defeat
consequences and taking what a machine holds all roll.

- **`runs` is durable state**, the cost of making a run interactive. **The row carries the
  ADDRESS and never a system id** — a machine relocating out from under a run is what
  CANCELS one, and a foreign key would make that an integrity error. `Run::system()`
  returning null is ordinary.
- **`characters.current_run_id` is what "in a run" means** — a column, like
  `equipped_deck_id`.
- **`App\Cyberspace\RunStarter` is the only writer**, setting the character's pointer in the
  same transaction.
- **It refuses four things and deliberately not a fifth.** Not in cyberspace, nothing there,
  the portal, and a deck that stopped meeting its requirements (checked a second time —
  cheap, and it closes the window). **It does NOT refuse a machine whose OS you lack** —
  that run is entered and the core is rolled for from the inside, because a refusal naming
  the OS would hand over a scan fact for one click.
- **SPEAKING THE MACHINE'S OS IS A ROLL, NOT A WALL.** `Simulator::opensTheCore()` is
  asked by Extract, Install and Burn — the three actions that already share
  `touchTheCore()` — and by nothing else. The check value is the runner's **LEVEL** in
  `systems.os_skill_id`, read off the `cyberspace.os.values` table, against a flat
  difficulty: 4.7% / 18% / 48% / 77% / 89%, and the certainty floor at level 5. **The table
  value is the BASE of that skill's `check.<slug>`**, resolved with the runner's
  modifiers, so a program or implant that helps with an OS is a modifier on the check.
  **A table
  and not a multiplier** — the first level is worth nearly twice the fourth, which no
  straight line draws. **No grade term** — a
  machine's grade already decides WHICH operating system it runs, via the skill's rank, so
  charging for it again would charge twice.
- **AWAKE ICE GUARDS THE CORE.** Extract, Install and Burn each make the core touch
  (the IDS's second look) and then, if any ICE is awake — woken now or on arrival — fail
  with `ice_guards` BEFORE the operating system is rolled. The turn is spent and the ICE
  swings. The runner breaks it or egresses; there is no third way to the core. Said out
  loud, unlike the core's silent failures: the ICE is on screen, so naming it gives
  nothing away. The run screen disables the three buttons while `Run::inAFight()`.
- **A cut never asks.** An icebreaker against an ICE is one program against another; the
  vocabulary only matters when the MACHINE is being told to do something.
- **`runs.os_locked` is the state of the door, not a verdict about the runner.** True from
  the start whenever the machine runs anything, cleared by the roll that lands, and **open
  for the rest of that run** — so a two-turn extraction does not re-roll. Going back in
  tomorrow means finding the way in again.
- **A failed attempt spends the turn and says nothing.** That silence is why the CHANCE is
  on the run screen (`Simulator::coreChance()`, null when there is nothing to get past):
  a streak of nothing has to be a number the player was shown rather than a bug they have
  to guess at. The line says *core* and never names the OS.
- **`EnsureRunIsFinished` is the lock**, `RequireCharacter`'s shape: named allow-list,
  everything else redirected, 409 and a URL for JSON. **Admin routes are not exempt** — an
  admin could otherwise edit their way out of a run.
- **The readiness net stands down inside a run.** `EnsureDeckStillRuns` returns early while
  `current_run_id` is set, because a burnout is *supposed* to break the deck and resolves
  its own ending.
- **Leaving is always on the list** — egress is never absent and never disabled.
- **`App\Cyberspace\Simulator` is one door for every action**, so a turn always costs the
  same thing. The die is injected. **Bound in the container**, so a test that pins the die
  gets a pinned SCREEN.
- **The player acts, then the machine does.** Somebody who breaks an ICE does not then take
  a hit from it; somebody who leaves gets their attempt first.
- **What is fighting lives on the RUN** — `runs.ice_id` and `runs.ice_integrity`. The
  catalogue says what an ICE is; these say what this fight has left of it.
- **A failed cut says one thing for two causes**: no icebreaker, or nothing awake. The OS
  is not one of them — see *speaking the machine's OS* above. `FightTest` still asserts the
  words *operating system* appear nowhere in it.
- **`stealth` is what an IDS is rolled against**, floored at 0. Its base is a **POOL** —
  `(intellect + focus + memory + perception) / 8.5`, one decimal.
- **Arriving is the first thing anything gets to notice** — `Simulator::rouse()`. **Every
  watcher rolls**, and **any one success detects you**.
- **Detection is what wakes things.** An undetected run walks past the ICE and the tracer
  both — the ICE is only written onto the run when something noticed. So stealth does not
  make a fight easier, it makes the fight not happen.
- **It takes the GLOBAL certainty floor** — `checks.floors` names no kind for it on purpose.
- **The trace advances once something has noticed**, at the machine's `tracer_rate` a turn,
  read off the machine rather than copied onto the run (unlike integrity: integrity is being
  ground down, a tracer's speed is hardware).
- **A TRACE WALKS THE CHAIN, one hop a turn.** One attempt at the NEXT system: pass and the
  trace moves up, fail and it stays — the turn ends either way. **A hop once passed is
  behind it** (`runs.trace_hop`). Rolling every hop every turn would make a long chain
  impassable rather than slow.
- **The hop's GRADE sets the trace's chance** — 95% at grade 1 down to 20% at grade 9 —
  as the base of `trace_hop_chance`, resolved with the runner's modifiers. It is the
  TRACE's number, so a skill that makes a chain hold better is a negative modifier.
- **A hop only delays by FAILING**, so **grade 1 is 95 rather than 100**: at 100 a junk hop
  could never delay anything. Ten junk hops is a 40% chance of one held turn.
- **`routing_hops` is the ceiling, and it is all skill** — base zero, one per level of
  Routing and Advanced Routing, ten total. First entry in `stats.bases`.
- Past the end of the chain the trace comes on unopposed; an empty chain is that case from
  turn one.
- **THE CHAIN IS BUILT, NOT PICKED.** `App\Cyberspace\ChainBuilder` takes every routing
  backdoor, best grade first, capped at `routing_hops`, never the target. A picker's right
  answer is always "all of them, best first".
- **`runs.chain` is system ids and never grades**, snapshotted at the door and **never
  rewritten**. `trace_hop` indexes it, so a hop that goes dark becomes a GAP rather than a
  shift — `routingGrades()` returns one slot per stored hop with null where the machine or
  backdoor has gone, and `traceGetsThrough()` steps over nulls for free. **Use
  `array_key_exists`, not `??`**: a present null is a gap and an absent key is the end.
- **`Simulator::burnTheChain()` shuts every machine on the route** through
  `Lockdown::shut()` — one call does "the backdoors are gone and the systems move".
- **A completed trace puts heat on the character and burns the chain.** `characters.heat` is
  persistent; **a trace costs the system's GRADE × 2** (`cyberspace.trace.heat_per_grade`),
  **charged as the run ENDS** (`RunStarter::chargeTheTrace()`, any ending) rather than as
  it lands, because **a landed burn REPLACES it — the two heats never compound**. The chain
  still burns the moment the trace lands. A traced runner losing nothing else is
  deliberate: heat is meant to become untenable once hunters and bounties have teeth.
  What heat DOES today is attract attention (random encounters are the plan):
  `App\Characters\Bounties` rolls every character carrying any, once a night at five, against
  the chance somebody decides they are worth money. The uncertainty floor under five points,
  climbing in a straight line to **certain at a hundred** — a straight line because nothing
  is balanced against it yet, and `chanceFor()` is one method to change when it is.
- **That roll is NOT a check, which is why it may reach certainty.** A check is somebody
  attempting something against a difficulty and the floor exists so skill never becomes a
  guarantee; this is the world deciding about you, with nothing to be good at. It takes the
  percentile shape the market's rarity roll and the backdoor ageing roll use — injected die,
  strictly inside the bound, so nothing never fires and everything always does.
- **Zero heat is not rolled at all.** A floor is what an attempt keeps, not something the
  world owes somebody who has done nothing.
- **A hit adds to the bounty.** `characters.bounty` is creds on the character's head and
  ACCUMULATES: each hit adds `heat × cyberspace.heat.bounty.amount.per_heat` (50), scaled
  `1 + per_grade (0.25) × (grade − 1)`. The grade is `characters.bounty_grade` — the highest
  grade of system that cost heat since the last hit, written beside `heat` by
  `RunStarter::chargeTheTrace()` and the burn in `Simulator`, reset to 0 by the hit. Zero
  grade prices as grade 1. `Bounties::amountFor()` is the sum. Shown on the character sheet;
  the inbox message names the running total. **Nothing collects or clears it yet** — the
  player-versus-player attack that pays it out is unbuilt. All figures unbalanced.
- **Word already out is not news.** `characters.bounty_word_at` remembers the last time,
  and nobody is rolled again until `cyberspace.heat.bounty.cooldown_days` have passed — a
  memory on the character, never a gentler curve, and a column rather than a look through
  the inbox, because a player clearing their messages must not reset the world's opinion.
- **And it comes off again, slowly.** `App\Characters\HeatDecay` takes half a point a
  night at 05:10, **after** the bounty roll — a character answers for the heat the night
  ended with, and the cooling is the morning after rather than a discount applied before
  anybody looked. **The rate is the `heat_decay` stat**, based at 0.5 in `stats.bases`, so
  the first skill that speeds it up is a modifier and nothing in the command learns it
  exists. Resolved **through the Sheet** (`Character::stat()`), never `Resolver::resolve()`
  with a base handed in — `stats.bases` is a map only `Sheet::baseFor()` walks.
- **`characters.heat` is a DECIMAL**, one place, because what earns heat is whole and what
  removes it is not. It floors at nothing and has no ceiling.
- **`characters.heat` and `runs.thermal_load` are different quantities.** The design calls
  both "heat"; the run's column is named for what it is.
- **The two thresholds ACT, and BEFORE the player's choice** — checked at the start of the
  turn, and a breach attempts egress then and there whatever was asked for. A threshold that
  only warned would leave an unresponsive player in a run that never resolves. **Zero turns
  one off.**
- **They are set from the MAP, never from inside a run** — an accordion on the cyberspace
  screen, because they are a play style, and changing them mid-fight would be changing the
  rules of a fight while standing in it.
- **The run log is drawn NEWEST FIRST** (stored oldest first, reversed in the view), so the answer to the turn just taken is where the eye already is instead of below the fold, and so is the hunter's.
- **The core-touch button (Extract, Install) turns `.btn--go` when the core is OPEN**: no ICE awake and nothing left to get past (`Simulator::coreChance()` is null — the operating system has been spoken to, or there never was one). Burn keeps its ember: it is the destructive one. Backdoor kinds read through `backdoor.kind.*` (a label once read a key under `cyberspace.`, which does not exist, and the button said so).
- **A log line is stored rendered, and may carry a `tag`.** `runs.log` entries are `{text, tone,
  turn}` plus an optional `tag` — who did it ("ICE VII"), shown as a tag in front of the text. It is
  kept BESIDE the text rather than inside it so the screen can style it, and an old log without one
  still reads: changing a sentence in `strings.php` changes new runs only.
- **The run screen draws the trace as the CHAIN it walks** — the machine at one end, you at
  the other, backdoored relays between. Two nodes today, drawn as a chain so it will not
  have to change. `Run::turnsUntilTraced()` is worked out server-side.
- **The map's *Interact* box holds the two things you do to the machine you stand on**: *Plan a run* and *Scan*
  (the scan button is no longer in the top bar). *Plan a run* is `.btn--go` when the live scan is whole
  (`SystemScan::isComplete()`) and `.btn--warn` (brass) when it is not or there is none; Scan is a plain button, so
  there is never a second filled one beside it. While a read runs, *Scan* is replaced by *Show scan* (the way back into
  the read window). Scan is dead while the file is whole and live again once
  the file is stale (a stale scan is not the live `$scan`); an interrupted partial read can be started again. The
  *Thresholds* panel beneath shows its two values as tags. Under the buttons the box lists what the live scan has found
  (`<x-scan-facts>`, the briefing's and the scanner window's own rows): every fact either way, so a blank reads
  UNKNOWN rather than as nothing to find. Above them, an *Installed here* row lists the backdoor you already hold in this system and its kind
  (read live off `backdoors`; access you were given for an entity's job leaves no row of yours, so it is not shown).
- **`run.setup` is the briefing, and the only way a run begins.** The map says *Plan a run*.
  Everything on the briefing is permanent: purpose fixed at the door, backdoor kind
  unchangeable, route snapshotted at start.
- **The briefing's product is what is NOT known.** An unread machine says so in words — blank
  reads as *nothing there*, which for an IDS is the opposite of the truth. Every `ScanFact`
  is listed either way. Beside it: deck requirements, thermal verdict per part, what is
  running and **what each thing IS**, and the route as the same chain widget.
- **The briefing speaks the other screens' languages, through shared components.** The system
  overview is `<x-scan-facts>` — the scanner window's own rows, every fact either way — and a file
  that is not complete wears a thin `.hazard-stripe--thin` along its top. The deck is
  `<x-deck-vitals>`, the deck screen's own bars (compute, memory, storage, draw, heat). Every node of
  the route opens a card through the kit's inspector (hover to float it, tap for a modal): a relay's
  is `<x-scan-facts>` over what the player has read of it. Change a row in the component, not the page.
- **An intention is a form, not a select** — each its own button with its own hidden purpose
  and kind. All three are real submits now that burn is built.
- **It reads the machine LIVE, not the contact**, and everything else on it is the same call
  the run makes.

#### Extraction

- **`App\Cyberspace\Extractor` is the only door between a node's inventory and a stash.**
  **Creds and items are instant; a file costs turns.** `takeCredsAndItems()` and
  `takeFiles()` are the two halves a multi-turn pull needs; `totalFileQb()` sizes one;
  `extract()` composes all three for non-interactive callers.
- **A file's turn cost lives on the RUN** — `runs.extracting_qb`, null when no pull is
  running. `beginTransfer()` sets it from `totalFileQb()` minus one turn's bandwidth,
  `continueTransfer()` spends another each time Extract is chosen, `completeTransfer()`
  calls `takeFiles()` at zero. **Continuing is an ordinary call to `Simulator::extract()`
  inside the ordinary turn** — no path skips the machine's half. A pull can only BEGIN
  with no ICE awake (see below). Continuing never touches the core again, but the IDS
  still watches (below). Spending the turn elsewhere pauses a pull, never loses it.
- **The core-touch detection roll fires only on the turn that BEGINS a pull**, every
  watcher rolling. Hence the `extracting_qb === null` check ahead of the OS/purpose/
  empty-node check that shares `extract_fails`.
- **Every later turn of a pull, THE IDS ALONE rolls again** (`idsWatchesThePull()`) —
  only while undetected, never on a system with no IDS. A long file is a gamble as well as
  slow, which makes bandwidth a stealth stat. Caught, the ICE wakes and guards the core:
  the pull stops where it was, KEPT, and resumes once the ICE is broken.
- **Bandwidth is read through `DeckTotals::forCharacter()`, floored at one.** The floor is a
  degenerate-case guard, not a balance number.
- **`transfer_gone` is its own log line** — losing a race after a countdown is an ordinary
  outcome, unlike the OS gate `extract_fails` protects.
- **Three kinds, three ways of becoming a possession.** Creds go onto the character. A
  catalogue item goes through the SAME merge-or-create `LoadoutGranter::grantItem()` uses. A
  file becomes one unstackable "Encrypted Data Dump".
- **A file's real numbers live in `inventory_items.extracted_file`, as JSON, on the ROW.**
  `App\Cyberspace\ExtractedFile` is the one author and reader — the shape `DownedCause`
  takes, and for the same reason.
- **Two files never merge**, even though the item is unstackable anyway — a summed quantity
  would destroy information.
- **`RunEnding::Completed`'s trigger is `runs.extracted`**, checked in BOTH doors to leaving
  so they cannot disagree.
- **One line covers every reason a core touch can fail** — unspeakable OS, wrong purpose,
  empty machine. The UI only offers Extract on an extraction run, so the purpose check
  defends against a hand-built request, but it must fail exactly as quietly.

#### Egress

- **LEAVING IS A ROLL.** `Simulator::egressChance()` is public and separate from the roll:
  the button quotes the number the attempt uses. The value is the `egress` stat — a POOL of
  Reflex, Focus and Intellect — so the first skill that helps is a modifier, never a line in
  the simulator. The difficulty is the machine's GRADE plus a term for whatever is fighting
  you (`cyberspace.egress`): tearing free mid-fight is not walking out of a quiet machine.
- **THE SCORE IS ON THE SCREEN, AND SO IS ITS WORKING.** `Simulator::egressReport()`
  assembles one `EgressReport` that the button, the card, the roll and the tests all read;
  `egressChance()` is a field of it. The card is the FIRST renderer of
  `ResolvedStat::$steps` — one line per LAYER, with the contributing sources named.
- **"Evasion" is the capability; "Egress" is the act.** The panel's meter is the CHANCE, not
  the score, because a pool has no maximum. The explanation is one `<template>` cloned into
  the hover layer and the modal both.
- **`character.names` names the two pools**; `egress` reads *Evasion* there.
- **Egress Protocols is the first thing that moves `egress`** — rank 8, behind Cyberdecks 3,
  +3% of base per level as five grants on one row. Arrives by MIGRATION. Nothing in
  `Simulator` learned it exists.
- **A failed egress costs the turn**, so the machine gets its half. **A threshold-forced
  egress rolls too** — a mark is an instruction to try, not a door that opens.
- **Both doors to leaving go through the same roll.** `RunController::egress()` takes the
  ordinary turn and `resolve()` is the shared tail.

#### Burn

- **`App\Cyberspace\Burner` is the only thing in the game that deletes a
  system.** A burn is not a relocation and not a lockdown: the row goes, the
  address goes out of existence with it, and the position it held is simply
  empty until a seeding pass puts something new there. CYBERSPACE.md: *dead,
  then empty, then eventually something new.*
- **It is a core touch**, so it takes the IDS's second look through the shared
  `Simulator::touchTheCore()`, exactly as extraction and an install do. Being
  seen changes nothing about whether the machine burns.
- **One line for every reason it can fail** — a machine already gone, an
  unspeakable operating system, a purpose that is not `RunPurpose::Burn` — the
  same silence `extract_fails` and `install_fails` keep, so the OS gate never
  announces itself.
- **A landed burn is the last turn of the run.** Nothing is left to strike
  back, advance a trace or be shut, so `take()` returns early and the run ends
  `RunEnding::Completed` — the second thing besides `runs.extracted` that means
  *they did what they came to do*.
- **It is why lockdown's fourth trigger needs no code.** `Lockdown::afterRun()`
  looks the machine up by address as the run ends and finds nothing: the
  trigger resolves itself by making its own subject absent.
- **Delete FIRST, then cancel everybody else.** The obvious order is wrong —
  cancelling first walks each other runner's ending through `afterRun()` on a
  machine still standing, and one that had noticed any of them would shut,
  which throws out everybody inside, which re-enters the loop already running.
- **`Simulator::cancelEveryRunOn()` is that shared consequence**, keyed by
  ADDRESS so it can be called after the machine is gone, and taking the
  burner's own run as the exception. Lockdown calls it too — it used to be a
  private method there, and two copies would be two places for the ending order
  to drift.
- **What it costs is heat and nothing else.** The system's grade × 2.5
  (`cyberspace.burn.heat_per_grade`), against a trace's grade × 2 — burning a
  system is always worse than being caught hacking it. A traced burn pays this and not
  the trace's heat as well. No loot, no
  raid counted, no lockdown: the real price is everything the machine would
  ever have been worth again, which is the design's own answer to what a burn
  costs the runner.

#### Attack tags and defeat

- **ATTACK TAGS are what a piece of ICE does WHILE it fights** — the mirror of defeat tags:
  a defeat tag fires once at the end, an attack tag is in force while the thing is awake.
  `ice.attack_tags`, `App\Cyberspace\AttackTag`, `config/cyberspace.attack_tags`;
  `cyberspace:tag-ice` writes both lists.
- **`Ensnare` is the first, and it MULTIPLIES the egress chance** — a quarter off at I, two
  fifths at II, rather more than half at III. A multiplier because every other percentage in
  the game is one. **`CheckRoller` owns the multiplication** (a third argument to `chance()`
  and `roll()`), because the factor applies to the raw curve and the floor applies AGAIN
  after it. Ensnare makes leaving expensive; it can never close the door.
- **The REDUCTION is a stat (`ensnare_reduction`), the MULTIPLIER stays a plain float.**
  `AttackTag::multiplier()` is still pure — the table value, untouched, and what the tests
  above pin. `Simulator::ensnareMultiplier()` is the one place a character's own modifiers
  reach it: the tag's raw reduction is resolved with `character->modifiers()` before it
  becomes `max(0, 1 − reduction/100)`. **Signature Scrambling** (rank 16, needs Egress
  Protocols 3) is the first skill on it, −5%/level.
- **It is PASSIVE while the ICE lives and nothing is stored on the run** — read off
  `runs.ice_id`, so there is nothing to expire and no condition outliving its cause.
- **`App\Cyberspace\DefeatResolver` is what makes the tag set real.** Called from
  `Simulator::resolveEnding()` **before** the ejection: damage, consequence, `jackOut()`,
  floor.
- **Flatline deepens the injury; Neural Shock lands on the body.** Flatline rolls its
  percentage against maximum SI **as it stands right now** and writes through
  `InjuryLedger::inflict()` — the same *neural damage* row a freeze deepens; current SI
  clamps on the save that follows, via the model's hook. Neural Shock goes through
  `DamageResolver` with `DamageOrigin::Ice` and touches no maximum. **Flatline deliberately
  does NOT come through the door** — mitigation has nothing to say about an injury.
- **Surge deletes the module outright**, chosen from what is CURRENTLY fitted;
  `deck_fittings.module_id` cascades. One roll per module the level names; a module already
  taken cannot be taken twice. No deck, or nothing fitted, is a no-op.
- **Erase draws from storage AND whatever is running — deliberately wider than
  `inventoryIn()`.** A running row is stopped through `ProgramRunner::stop()` before it is
  deleted.
- **Skim reaches `creds` directly**, floored. Bounty never appears in this list — it is
  rolled onto the MACHINE, so `DefeatResolver` returns null for it honestly rather than
  omitting the case.
- **`DownedCause` has an `effects` field.** `tags` is names; `effects` is the resolver's
  rendered account. The waiting screen prefers `effects` and falls back to tag pills for a
  cause built before `DefeatResolver` existed.
- **`cyberspace:tag-ice` is a console command, not a migration** — a fresh clone's ICE
  carries no tags until it is run, so tests write tags onto the row directly.

### Hunters

**Heat becomes an encounter, checked on every move.** A hunter is not a run against a
system — no trace, no IDS, nothing snapshotted at a briefing. It is a straight exchange
against ICE generated on the fly, and it locks the screen until it is settled.

- **A HUNTER IS AN ICE, ON THE FLY.** `hunts.ice_id` points at a real row from the twelve
  in the catalogue, drawn from the level range its grade allows — never invented numbers.
  That is what makes every existing ICE resistance (Reactive Firewalling, Defensive
  Compartmentalization, Spectral) and Ensnare reach a hunter for free: the damage is
  `damage.synaptic_integrity.ice`, because it IS that damage, and `HunterCombat` is the
  same exchange shape `Simulator::iceStrikes()` already is, not a second one invented
  beside it.
- **`App\Cyberspace\Hunters::rolls()` is the die, checked in `Navigator::arrive()` after
  every sweep** — jacking in, a step, a jump, a recall, all funnel through the one
  `arrive()` hook a system sweep already uses. **The chance is a straight line on heat**,
  capped, never certain: `cyberspace.heat.hunt.floor_below` (5) to `certain_at` (100)
  maps `chance_at_floor` (0.5%) to `cap` (35%). Below the floor it is not rolled at all —
  the same shape `Bounties::chanceFor()` uses, deliberately **without** its cooldown: a
  hunter just beaten is exactly as likely on the very next step as the curve says.
- **Five grades, not the ICE's own level.** `hunts.grade` is the difficulty band the
  spawning heat fell into (`cyberspace.heat.hunt.grades`), kept alongside the ICE it
  happened to draw so creds and the heat reduction on a win can be priced by band without
  re-deriving one from a level two grades might share. Grade 5 draws from the same level
  range (10-12) a grade 8-9 SYSTEM does.
- **`characters.current_hunt_id` is what "in a hunt" means** — a column, `current_run_id`'s
  own shape. `App\Cyberspace\Hunters::spawn()` is the only writer, inside one transaction
  with the character's pointer.
- **NO DOOR OUT UNTIL IT IS SETTLED.** `App\Http\Middleware\EnsureHuntIsResolved` is
  `EnsureRunIsFinished`'s own shape — named allow-list, 409 JSON for API, redirect for
  web, admin not exempt. `RunStarter` refuses with `RunRefusal::Hunted` and
  `Navigator::jackOut()` refuses with `TravelRefusal::Hunted`; `EnsureDeckStillRuns` stands
  down for a live hunt exactly as it does for a live run, since a hunt can only exist while
  jacked in.
- **THE FIGHT PICKS ITS OWN LOADOUT.** `Hunters::spawn()` calls
  `startBestIcebreaker()`, which ranks every owned, idle icebreaker by its resolved
  `icebreaker_damage` and starts the strongest one `ProgramRunner::start()` will accept —
  the player chooses nothing, because there is no briefing to choose it at. Owning none
  starts nothing, and a fight with nothing running still resolves: zero damage a turn is
  a turn the hunter still gets to swing back.
- **THE PLAYER ACTS, THEN THE HUNTER DOES** — the same order every fight in this game
  keeps. `HunterCombat::fight()` deals the running icebreaker's damage first; if that
  breaks the ICE the hunter never swings. Otherwise the hunter's `skill` is a hit chance
  the same way a system's ICE is, and a hit goes through `DamageResolver` under
  `DamageOrigin::Ice` like any other.
- **Winning is priced by GRADE, losing is not.** A win reduces heat by a flat
  `grade × win_heat_reduction_per_grade` (5) and pays creds from the grade's own band
  (`cyberspace.heat.hunt.grades.*.creds`, ~300 at grade 1 to ~1000 at grade 5), plus a low
  chance (`program_chance`, 8%) of a program — silently skipped if it would not fit,
  `Storage::fits()` the same gate extraction uses, nothing forced. **A loss does NOT reset
  heat to zero** — it drops a random `cyberspace.heat.hunt.defeat_heat_loss` slice (5-15%)
  of whatever it currently is, deliberately less generous and less predictable than a win,
  so losing is never the cheap way to manage heat. Either way `ProgramRunner::stopRunOnly()`
  clears the loadout the hunt started, and a loss calls `WaitingRoom::checkFloor()` exactly
  as a system fight's defeat does.
- **One transaction per exchange.** A hunt fought halfway and left unsaved is a fight the
  next request would have to guess the middle of — `hunt.log` (JSON, append-only) is what
  `hunt/show.blade.php` replays, `runs.log`'s own shape.
- **The hazard stripe is the one place ember fills rather than outlines.** `.hazard-stripe`
  in the UI kit (a 45° repeating-linear-gradient between `--ember` and `--bg-0`, 32px tall,
  full width) marks the screen as a hunter attacking the player rather than a run against a
  system — the same licence `.hud-alert` spends, on a different shape, because nothing
  else on a hunt screen says so: no trace, no IDS, no briefing to read it off of.

### Being on the floor

**Reaching 0 in either resource puts a character on the waiting screen** —
`App\Characters\WaitingRoom`.

- **The wait is NOT the punishment.** What losing costs is whatever the defeat tags carried;
  the minute is there so a defeat carrying nothing still costs something. A long wait would
  punish the player who lost to a harmless guard hardest.
- **Zero to one, and no further.** Getting up is not healing. **Both resources come back if
  both were empty**; one that was not empty is left exactly where it was.
- **Released lazily, with no scheduler** — `EnsureCharacterIsStanding` asks on the way past.
- **A second hit does not restart the clock.**
- **It runs BEFORE the run lock.** If they overlap, being unconscious wins.
- **`ReadinessWatch` is its first caller**, and the screen comes after `jackOut()` — a
  character must never sit on it while holding a position in cyberspace. **One point can do
  it.**
- **`characters.waiting_cause` is JSON, temporary on purpose.** Columns modelled against an
  unwritten simulator get modelled wrong. `App\Characters\DownedCause` is the single author
  and reader. **Every field is optional.**

### Recovery

- **Everybody mends a point of SI every four hours** — `synaptic_mend_minutes`, base
  240 in `stats.bases`, an INTERVAL so a percentage off it lands (a rate of 1/240 a
  minute would floor to nothing). `characters:regenerate` credits it with no clock: the
  point lands on the minute whose epoch number divides by the character's interval.
- **Anything faster is paid for, and `synaptic_regen` floors at 0 to say so.**
  **Neuroplasticity** (rank 16) is a point a minute per level. Per MINUTE so
  anything crediting it can work off elapsed time. **`characters:regenerate` credits it
  every minute** and keeps NO stored clock — a minute lost to a restart is a point. It heals
  from ZERO.
- **A dose can carry `synaptic_regen` too, and that is how healing is SOLD.** Rewire
  (nootropic, by migration) is +2 a minute for ten minutes, −8% Intellect and Focus while it
  runs, tolerance 10 a dose, not addictive. **Two a minute and not one because regen
  FLOORS**: tolerance scales one point to 0.9, which floors to nothing. Tolerance scales
  only the positive amounts, so a tolerant user heals at half the rate and pays the full
  side effect.
- **`Character::recover()` is `takeDamage()`'s mirror** — addition and a ceiling. **Capped
  at the maximum as it is NOW**, which is why it cannot be damage with a minus sign: healing
  must never climb past what injury lowered.

### Missions

**Work done for an entity: a designated set of systems, an action required of
each, and a clock.** `App\Missions\MissionBoard` rolls them,
`App\Missions\MissionLedger` does everything afterwards, and the two never
overlap.

- **A BOARD IS ROLLED PER CHARACTER AND NEVER SHARED**, refreshed on the hour
  and **lazily** — `Probe::settle()`'s shape rather than a sweep, because a
  scheduled refresher would roll boards for every character at every desk they
  have ever walked past. Nobody can take your job and two players at one desk
  see different work.
- **Declining DELETES the offer**, and it stays gone until the hour comes
  round. That is what makes turning something down a decision. An offer nobody
  took is not history; only accepted work has a story worth keeping. **An empty
  board is stale by definition**, which is what makes the floor work — somebody
  who declined everything gets a fresh roll rather than an empty desk.
- **Four actions, and each is a different kind of EVIDENCE.**
  `mission_targets.satisfied_at` is one column filled several ways, and an
  action is what decides which way. A **survey** carries files in and spends
  them at the desk; a **demolition** and a **placement** are satisfied by the
  act, in the transaction that performs it; **supply** names no systems at all.
- **The act reaches the ledger, not the other way round.** `Simulator` calls
  `MissionLedger::recordAgainst()` inside its own transaction, and it is
  deliberately quiet: a runner who burns a machine they happen to owe somebody
  is not interrupted to be told, and the desk is where they find out it
  counted.
- **A PLACEMENT IS NOT AN INSTALL.** No `backdoors` row is written for the
  runner — the door belongs to the entity, so it pays nobody nightly, is not a
  routing hop, never enters `ChainBuilder`'s chain, and none of the four things
  that take a backdoor away has anything to take. **The flag on the target row
  IS the record.** It is still an install for the one consequence that is not
  about ownership: `runs.backdoored` is set, so the machine does not lock down.
  A run installs once, so a run spent planting for somebody else is a run not
  spent planting for yourself.
- **Three endings and three prices.** Completing pays; failing charges;
  **abandoning charges exactly what failing charges**, because if giving up
  were free nobody would ever do it — they would sit on the job and let it run
  out. **Voiding costs nothing at all.**
- **A target that stops existing VOIDS the job**, detected by the snapshot
  rather than watched: the id resolves to nothing, or to a row wearing a
  different address. A lockdown's close is a relocation, so it is the same
  case.
- **`mission_targets.system_id` NULLS and never cascades.** A burn deletes a
  system, and a cascade would take the target row with it — leaving a job with
  nothing outstanding, which reads as *finished*. The row has to survive its
  own subject. **The general rule: a row that exists to REMEMBER something must
  not cascade from the thing it remembers.**
- **The rewards are snapshotted at the OFFER**, so retuning
  `config/missions.php` never changes a job somebody already took. The same
  call `market_purchases.unit_price` makes.
- **The reward is a RESOLVED stat and the penalty is not.** `standing_gain` is
  declared in `config/stats.php` and deliberately NOT in `stats.bases` — there
  is no such thing as a character's standing gain in the abstract, only what
  this job at this desk was worth — so the base is handed in at the call site
  the way `scan_duration` is. The penalty is flat: being bad at consequences is
  not a thing to be good at.
- **One active mission per ENTITY**, not per character. Three desks can have
  you working at once; one agent cannot hand you two jobs.
- **The window is 4–24 hours and starts on ACCEPT**, never at the offer.
  `accepted_at` and `expires_at` are null together and set together, which a
  CHECK enforces. **It must stay under `cyberspace.scan.lifetime_hours`** or a
  file's expiry becomes a second, invisible deadline.
- **`missions:expire` sweeps every five minutes**, the lockdown sweep's cadence
  and its reason: the penalty has to land whether or not the player ever comes
  back. A job whose target has gone voids rather than fails, even there.
- **Supply spends complete, unexpired files only**, and the target rows are
  written AT HAND-IN as the receipt — one per file accepted — so the job still
  records which systems were sold. Asking for more than is held is refused
  rather than partly taken.
- **The same gate guards the work and the shelf.** `exchanges.min_standing` is
  read by both: an agent who will not sell to somebody will not employ them
  either.
- **There is no mission index and never will be.** A board belongs to a DESK,
  and a desk is reached by standing on it — the whole of the permission, the
  same call the run briefing makes.

