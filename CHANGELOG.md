# Changelog

## [Unreleased]

## [0.12.0] — 2026-09-21

Phase 6's other half. A backdoor becomes something you hold, earn from and
lose; a machine that finds out it was violated shuts its doors and then moves
house; and a run stops being a link you follow and becomes a plan you commit
to. Also: the trace finally has something to walk through, and the analyser
learned what Eloquent is.

### Added

- **A way back in.** A backdoor is ACCESS rather than a recollection, so unlike
  a contact or a scan it is a real foreign key to the machine — a memory of a
  system that has gone is still a memory, access to one is nothing at all. It is
  also what makes the promise true: a backdoored machine does not lock down and
  does not relocate, so its address is permanent in a world where nothing else
  is. The kind is chosen at the door with the purpose and is permanent — a
  machine siphons or it routes — because going back in for a free reroll would
  undo the one property another whole run is supposed to buy. A landed install
  is the only thing in cyberspace that cleans up after itself: no lockdown even
  though the IDS fired and the ICE woke, because the install erases the logs. A
  FAILED one cleans up nothing. What holding one buys is exactly half the risk —
  the approach roll is skipped for ever and the core roll is untouched, since
  extracting through a backdoor is still stealing.
- **A backdoor is not permanent, and four things take one.** Three are
  somebody's fault: the machine shuts, it relocates, or it burns. The fourth is
  nobody's — a nightly roll whose odds rise with AGE and with nothing else, so a
  backdoor cannot be held carefully enough to keep. One per cent on the first
  night, climbing about a point and a half a month, capped at a tenth: a median
  life near six weeks, long enough that installing one is worth the run and
  short enough that a portfolio is maintained rather than accumulated. A hit
  moves the MACHINE, which takes every other door into it along with it — so a
  stranger's bad night costs you a door you were careful with.
- **Siphons pay out.** Thirty creds a grade a night, deliberately modest against
  what raiding the same machine yields: a siphon is the safe half of a
  portfolio, and safe income should be slow income. What makes it worth holding
  is that it arrives whether or not anybody goes anywhere. The per-grade figure
  is the MACHINE'S and goes to the resolver as `siphon_yield`, so the skills
  that will make somebody better at draining one are rows rather than code.
- **The routing chain, built rather than picked.** Every routing backdoor is
  free to include and hinders the trace by exactly its machine's grade, so a
  picker would be a list whose right answer is always "all of them, best first",
  asked on every run. The chain is assembled instead — best grade first, capped
  at `routing_hops`, never the target. What a player decides is which machines
  to hold one on. It is snapshotted at the door and never rewritten: a hop that
  goes dark becomes a gap rather than a shift, so one already passed stays
  passed and one lost ahead simply gives the trace one less to get through.
- **A trace walks that chain, one hop a turn**, and a hop only ever delays it by
  FAILING. Grade 1 is 95% and not 100 for exactly that reason — at 100 a junk
  hop could never fail and so could never delay anything, which would contradict
  the design's own promise that a routing backdoor is useful on any machine at
  all. Ten junk hops is a 40% chance of one held turn: a chance rather than an
  income. Grade is the only thing a hop reads.
- **Routing and Advanced Routing finally grant something.** Both have had books
  on the shelf since the catalogue was written with nothing in the game ever
  asking either of them a question. `routing_hops` is that question: base zero,
  one hop per level from each, ten the ceiling.
- **Lockdown — a machine that knows it was violated shuts, then moves.** Two
  triggers and not the four the design table lists: the ICE can only have
  activated if something noticed, so being noticed covers both rows, and burn
  does not exist. That leaves detection, and a spent raid counter — three raids
  and it shuts however quiet the third was. A clean extraction still leaves no
  lockdown behind, which is what an unnoticed run is worth. It fires as a run
  ends, so it can never reach the runner who caused it, and a machine already
  shut is left alone rather than having its window extended: a lockdown is what
  the machine DOES about being violated, not a sentence that accumulates.
  Anybody else still inside is thrown out for one flat point of Synaptic
  Integrity and nothing else — no defeat effect, no raid, no trace, because the
  cause was not theirs.
- **The briefing, and the first way to begin a run.** The map says *Plan a run*
  and nothing more. Everything on that screen is permanent — the purpose is
  fixed at the door, a backdoor's kind cannot be changed once it lands, and the
  route is snapshotted the moment the run starts — so the decision gets a screen
  of its own. What it is FOR is the unknowns: a blank column reads as "nothing
  there", which for an IDS is the opposite of the truth, so every scan fact is
  listed either way and an unread machine says so in words. Beside it, the three
  things that turn a plan into a bad night: a deck that fails its own
  requirements, a thermal verdict naming the parts at risk, and what is running
  with what each thing IS — an icebreaker and a decryptor are both "a program"
  until one of them is the only thing between you and an ICE.
- **The evasion score, on the screen, with its working.** One report object the
  button, the card, the roll and the tests all read, so nothing can hold a
  different opinion about the same attempt. The card is the first thing in the
  game to render the resolver's own steps, which have carried "why is my Focus
  14?" since the resolver shipped with nothing drawing them — one line per
  layer, because percentages are summed and applied once and a line each would
  imply a compounding that does not happen. Then the opposition: the machine's
  grade, what is awake in it, and the snare.
- **Egress Protocols**, rank 8 behind Cyberdecks 3, +3% per level and +15%
  trained out — the first skill that makes somebody better at leaving. Nothing
  in the simulator learned it exists, which is the whole point of the shape.

### Changed

- **Static analysis can see the models now.** PHPStan was pointed at `app/` from
  the start with nothing teaching it what Eloquent is, so it ran without the
  half of the codebase that matters. Larastan at level 5, clean, with the casts
  read out of their method and the column types read out of the migrations
  rather than a live connection.
- **The run screens have a smoke test.** Two bugs shipped in 0.10.0 that
  fourteen hundred passing tests could not see, both of them the container
  failing to build something the controller asked for. The new pass asserts the
  duller thing the unit tests structurally cannot — that the routes resolve, the
  views render and nothing 500s — across the screen in five states and every
  `RunAction`, walked off the enum rather than named, so a new action is covered
  the day it is added.
- **`stats.bases`**, the third map a sheet walks after pools and formulas, for a
  stat that is neither computed from attributes nor stored on the character.
- **CLAUDE.md says the same things in half the words** — 27,800 down to 14,100,
  every rule kept and the argument around it cut. Cyberspace was seven hundred
  lines in one run and is now subdivided.
- TODO.md records three things the game does not have yet: a node's operating
  system as the last stage of a run, the physical side of the city, and
  player-built data fortresses.
- CYBERSPACE.md's "specified and not designed" note on what a hop reads is
  resolved as decided-against rather than left open.

### Fixed

- **Neural Shock skipped every rule the damage door exists for.** It called the
  dull subtraction underneath the resolver directly, so nothing could resist it
  and nothing could be immune — although the Crim's "Hard knocks" covers exactly
  the stat it deals. Going through the door makes that the first shipped perk in
  the game that actually mitigates something. Flatline deliberately stays out:
  it writes a ceiling through the injury ledger rather than landing a hit, and
  mitigation has nothing to say about an injury.
- **Nineteen writes handed a mutable `Carbon` to a column that holds an
  immutable one.** Nothing had broken yet, because the value is only ever read
  back — but a `->addHours()` on the wrong one mutates in place, which is the
  bug that does not announce itself.
- A dead request method, a validator imported from the contract where the
  concrete class's `after()` is used, a service taking a dependency it never
  asked anything, and a trace condition whose second half could never be true.

### Migrations

- `2026_09_18_130000_egress_protocols`
- `2026_09_18_140000_a_machine_that_knows_it_was_violated`
- `2026_09_18_150000_a_way_back_in`
- `2026_09_18_160000_the_routing_skills_get_a_reader`
- `2026_09_18_170000_a_run_carries_its_route`

## [0.11.0] — 2026-09-18

Leaving a machine stops being free, some ICE stops letting go, and a node
stops being an annuity. Also two things that were broken in 0.10.0: every
turn of every run, and stealth.

### Added

- **Leaving is a roll.** Egress was always described as possible but never
  certain, and until now it was simply certain. It is the `egress` stat — a
  pool of Reflex, Focus and Intellect — against the machine's grade plus a
  term for whatever is currently awake in it, so walking out of a quiet grade 1
  and tearing free of a grade 9 mid-fight are not the same act. A failed
  attempt spends the turn and the machine gets its half of it, which means
  being held is paid for in trace, in heat and in one more swing. A threshold
  that pulls you out rolls too: a mark is an instruction to try to leave, not a
  door that opens. The button quotes the chance, and it is the same number the
  attempt uses.
- **An ICE that never woke is a cheap exit**, which is one more thing an
  unnoticed run is worth.
- **Attack tags — what a piece of ICE does WHILE it fights.** The mirror of the
  defeat tags and deliberately not a variation on them: a defeat tag fires once,
  at the end, on somebody who already lost, while an attack tag is in force for
  as long as the thing is awake and alive. One changes the price of losing; the
  other changes the decisions inside the fight.
- **Ensnare**, the first of them: it takes a quarter off your chance of leaving
  at level I, two-fifths at II and rather more than half at III. Five of the
  twelve pieces of ICE carry it — a tag every guard had would be a property of
  ICE rather than something worth finding out about one. It is passive while
  the guard is alive, so **breaking the ICE is the way out from under it**,
  which makes cutting worth doing to somebody whose only plan was to leave. It
  can never close the door: the reduced chance is floored like any other, so
  what Ensnare costs is turns.
- **A node counts its raids, and the third one retires it.** Three by anybody,
  not three by you — the world is shared, and a per-character counter would
  have every popular machine raided into the ground by a hundred people who
  each thought they had two left. A raid is a RUN and not a haul: creds pocketed
  on one turn and a file finishing four turns later are one visit. The third
  one relocates the machine when the run ends — new position, new address,
  books balanced — so bookmarks pointing at it break and every scan anybody was
  holding on it stops describing anything. A quiet thief gets three good nights
  out of a system, never an annuity.

### Changed

- Both doors out of a run now go through the same roll. The standalone egress
  route used to end a run outright, which was right while there was nothing to
  roll and became a free exit the moment there was.
- `cyberspace:tag-ice` writes both tag lists, and its table shows what each
  piece does on defeat beside what it does while fighting.
- CYBERSPACE.md's open questions 1, 2 and 12 are resolved rather than open: the
  raid counter is per system, it resets on relocation, and an active ICE makes
  leaving harder.
- The lockdown window is deliberately still unbuilt, and TODO.md now says what
  it needs. The third raid is one of four triggers, and the other three want
  the same state and the same duration — building a private version of it for
  this one would be building the thing that pass has to replace.

### Fixed

- **Every turn of every run was a 500.** `Simulator` was not bound in the
  container and its constructor takes an injected die, so nothing could build
  one by reflection — which made every controller that asked for it a server
  error waiting to be requested. It shipped in 0.10.0 with the fight.
- **Stealth was resolving to zero.** It is a pool, and asking the resolver for
  a pool returns the modifiers and nothing else, so every character carrying no
  implant was rolling a bare zero against every IDS in the world. Detection has
  been far harsher than designed since the day stealth shipped.

### Migrations

- `2026_09_18_120000_ice_does_things_while_it_fights`


## [0.10.0] — 2026-09-18

Cyberspace becomes a game rather than a map. A machine can be read, it can
notice you, it can fight back, and what it holds can be carried out — and
losing costs something specific rather than a point off a number.

### Added

- **Reading a machine.** Scanning takes an analyser running on the equipped
  deck, a pass takes minutes rather than a click, and one press is one read:
  the scan goes round by itself until the picture is whole or the budget is
  gone. It catches up with the clock, so an hour away is an hour of reading,
  and a sweep every five minutes keeps the row true for anyone who is not
  looking. A read belongs to the instrument doing it, so it survives you
  walking to another machine or jacking out — and stopping the scanner loses
  the pass, which is the one thing you chose.
- **Every machine runs an operating system**, as the skill that gates it, so a
  machine you cannot speak to is a machine you cannot take from. Finding out
  which is what a scan is for.
- **A machine defends itself**, decided once at creation: an IDS that watches,
  a tracer that follows, and a piece of ICE that fights. Type sets the floor
  and grade rolls the rest, so every machine guarantees two and rolls one —
  what a scan tells you is never *does this have anything* but *which one is
  it missing*. Everything hostile can notice you, so a machine with no IDS is
  quieter rather than safe.
- **Twelve pieces of ICE**, and a bounty that belongs to the machine rather
  than the guard — so the same ICE on two systems is two different
  propositions. Every icebreaker does strictly less than would break its
  matching ICE in twenty turns, which is the floor the whole ladder is set by:
  the first icebreaker is a tool for machines that have no ICE, and choosing to
  fight is an investment decision.
- **A run, played turn by turn.** Durable state, one at a time, a lock that
  keeps you in it, and Egress always on the list — a run with no exit is a
  character deleted by a door they could not see. The player acts and then the
  machine does, so nobody takes a hit from something they have just destroyed.
- **Arriving is the first thing anything gets to notice**, and detection is
  what wakes things: an unseen run walks past the ICE and the tracer both. So
  stealth does not make a fight easier, it makes the fight not happen.
- **The trace walks the chain**, one hop a turn, and a hop once passed is
  behind it. The screen draws it coming and says how many turns are left,
  because a player should not be dividing in their head while something is
  hitting them. Completing it puts heat on the character, which follows them
  home.
- **Two thresholds that act** — trace and thermal load — set from the map
  rather than from inside a run, and they pull you out before the turn you
  asked for. A threshold that only warned would leave an unresponsive player
  in a run that never resolves.
- **A defeat's tags happen.** Flatline deepens the neural-damage injury and
  takes the ceiling with it, Neural Shock lands on the body, Surge destroys
  fitted modules, Erase takes software and data off the deck — including what
  was running — and Skim takes a share of your money. Losing to ICE XII no
  longer costs what losing to ICE I did.
- **The floor under every defeat.** Reaching zero in either resource puts a
  character on the waiting screen for a minute and gets them up at one point:
  the wait is not the punishment, it is what makes a defeat carrying nothing
  still cost something. Released lazily, so the minute ends the moment the
  player looks.
- **Extraction — taking what a machine holds.** Creds and items are instant; a
  file costs turns, at the deck's resolved bandwidth, and the pull runs inside
  ordinary turns so whatever woke up keeps swinging while it does. Spending a
  turn elsewhere pauses a transfer rather than losing it. A file becomes an
  Encrypted Data Dump carrying its own numbers on the row, and a run that
  extracted and then left reads as completed rather than as a retreat.
- **What a machine is holding**, restocked nightly and topped up rather than
  added to: creds, catalogue items and generated files, weighted by loot tables
  that are rows rather than config. Ultra-rare is weight 0 below grade 8 — a
  wall rather than a long shot — and nothing physical ever comes off a machine,
  because you are not in the room. A machine may also be *told* what to hold,
  which is what a mission node will be.
- **Stealth**, as a pool of four attributes rather than a formula, so being
  unseen reads like a capability rather than something you spend. It floors at
  zero: somebody who has not trained for it is seen, every time, by everything.
- **Neuroplasticity, and the only healing that happens by waiting** — a point a
  minute per level, credited every minute by a worker that keeps no clock. A
  character who has not trained it recovers nothing by sitting still, ever.
- **Program threads**, so a scan can run while you are elsewhere and a second
  read needs a second instrument. Multithreading raises the ceiling, and a
  program without the flag is capped at one however good its owner is.
- **Two evasion programs**, and the shape for "per level of something else" —
  five conditional modifiers rather than an expression, because a modifier's
  amount is a number.
- **A character's own skills, and the queue that trains them.** What they know,
  what is training, and the five entries behind it, with the validator's
  warnings on screen for the first time. A player picks a skill and never a
  level: pressing the button five times is how a skill gets taken to level 5.
- **Effects are edited as rows, everywhere.** One editor for every modifier
  list in the game — items, programs, skills and archetype perks — so the half
  of the game MECHANICS insists must be data is authorable by somebody who is
  not a programmer. What it does not understand, it carries.
- **The map says six things at once**, one channel each: ground for terrain,
  the glyph for the kind of machine, the hairline for its condition, the
  corners for what you have on it, a bar for the scanned grade, and colour for
  type and nothing else. Twice the cell, half the window, a legend that lives
  in its own rail beside the map, and only what is lit drawn at full strength.

### Changed

- `cyberspace:defend` arms the world that predates its own defences — a
  back-fill and not a re-roll, because a machine already carrying something is
  the case the design exists to allow.
- Losing a read has two causes and no longer shares one sentence: the
  instrument stopped, or the machine burned or relocated. One message for both
  sent people off to inspect a working deck.
- The invented skill grants came down to scale and off the ceiling, twice.
- CYBERSPACE.md carries the run's design as built: the phases, what a party
  costs, and what is still a number rather than a mechanic.

### Fixed

- A trace walked the whole chain every turn instead of one hop, which made a
  long route not slow but impassable.
- The neural injector paid a flat bonus where it should pay four and a half per
  cent a level.
- A new cell on the map went bone and stopped looking like the kind of machine
  it was; its light travels round the hairline in its own type colour instead.
- Distant cells were dimmed by a step that was real in the numbers and
  invisible on a screen.
- Standing on a machine drew ● and hid what you were standing on. The bone fill
  already says where you are.
- The panels under the map came back after a layout pass dropped them.

### Migrations

- `2026_09_15_110000_every_system_runs_an_operating_system`
- `2026_09_15_120000_create_system_scans`
- `2026_09_15_130000_the_first_scanners`
- `2026_09_15_140000_a_scanner_for_everybody_already_here`
- `2026_09_15_150000_a_scan_pass_takes_time`
- `2026_09_15_160000_profiling_makes_a_read_quicker`
- `2026_09_15_170000_some_programs_multithread`
- `2026_09_15_180000_a_scan_belongs_to_the_instance_reading_it`
- `2026_09_15_200000_bring_the_grants_down_to_scale`
- `2026_09_16_100000_what_a_node_is_holding`
- `2026_09_16_110000_comment_the_contents_bookkeeping_columns`
- `2026_09_16_120000_a_node_may_be_told_what_to_hold`
- `2026_09_16_130000_create_loot_tables`
- `2026_09_16_140000_a_machine_defends_itself`
- `2026_09_16_150000_ice_stands_on_the_machines`
- `2026_09_17_100000_a_bounty_belongs_to_the_machine`
- `2026_09_17_110000_a_scan_reads_the_bounty_too`
- `2026_09_17_120000_a_run_is_durable_state`
- `2026_09_17_130000_twelve_icebreakers`
- `2026_09_17_140000_the_waiting_screen`
- `2026_09_17_150000_a_run_carries_its_fight`
- `2026_09_17_160000_two_evasion_programs`
- `2026_09_17_170000_the_injector_pays_more`
- `2026_09_17_180000_heat_and_the_two_thresholds`
- `2026_09_17_190000_a_trace_walks_the_chain`
- `2026_09_18_100000_extraction_takes_what_a_machine_holds`
- `2026_09_18_110000_a_transfer_spans_more_than_one_turn`


## [0.9.1] — 2026-09-15

Damage becomes a mechanic rather than a subtraction, and a deck that stops
working no longer strands the person standing inside it.

### Added

- **A deck that stops running throws its owner out of cyberspace**, costing 1
  point of Synaptic Integrity and nothing else — no ICE was involved, so nothing
  flatlines. It is checked where a deck can be broken from the inside (pulling an
  implant, starting a program) and by middleware behind that, so you are told as
  it happens rather than at your next click. Nothing can currently cause it,
  which is the point of having it before something can.
- **Damage knows what it hurts and what caused it.** A hit is resolved like any
  other number in the game, so resistances, vulnerabilities and immunities are
  modifiers on ordinary rows instead of code: −15% against everything ICE does,
  −5% against physical damage, ×0 to shrug off a burnout entirely. Resistances
  add up rather than compounding, mitigation can never heal, and a hit absorbed
  down to nothing still counts as a hit.
- **The Crim gains "Hard knocks"** — −5% physical damage taken, and the first
  perk that affects a kind of damage rather than a single stat. It sat on the
  card as a decorative −15% for months because there was nothing for it to name.

### Changed

- CLAUDE.md documents cyberspace, implants, programs and the modifier sources
  again: every built system is described in the file read first.
- The roster has never had a Netrunner or a Solo. MECHANICS.md said otherwise in
  three places and four test comments repeated it.
- Display-only perks are no longer used by anything that ships, and the shape is
  deliberately kept for content that may want it.

### Fixed

- A perk targeting a generated stat — a skill's check, or now a damage stat —
  was reported as targeting nothing by the check that validates perks.

### Migrations

- `2026_09_15_100000_the_crim_learns_to_take_a_hit`


## [0.9.0] — 2026-09-15
Lots of cyberspace additions.

### Added

- feat(cyberspace): a machine is named for what it is and who runs it (cf85e3a)
- feat(db): the schema explains itself, and a command that says where it does not (71c9498)
- feat(cyberspace): the grid, the machines standing on it, and the deck lock (4e143a2)
- feat(stats): timed modifiers, skill effects, and implants in the body (47aa757)
- feat(images): pictures of things, found by convention (9f941db)
- feat(ui-kit): a warn variant, the sheen, the dials and the entity image (df85f3a)
- feat(programs): software that runs on a deck, and the storage it sits in (dcef5c3)
- feat(stats): conditions, the dependency graph and the character sheet (abb47ad)

### Changed

- docs: the world's corps, and what the last two features changed (08f3f9a)
- docs(mechanics): what cyberspace asks of the stat system (128eb47)
- refactor(decks): heat becomes thermal load (eef8e84)
- docs(cyberspace): write down the grid, the systems and the run (c9959f6)

### Fixed

- fix(home): the landing page advertises only what is listed (fcd16d9)

### Migrations

- `2026_09_04_100000_rename_heat_to_thermal_load`
- `2026_09_04_120000_create_programs_and_processes`
- `2026_09_04_140000_add_storage_to_cyberdecks`
- `2026_09_04_160000_create_cyberspace_grid`
- `2026_09_04_170000_create_cyberspace_discovery`
- `2026_09_04_180000_add_cyberspace_position_to_characters`
- `2026_09_04_190000_comment_every_column`
- `2026_09_05_100000_create_bookmarks`
- `2026_09_05_110000_comment_the_bookmarks_bookkeeping_columns`
- `2026_09_05_120000_create_corps_and_give_systems_an_owner`
- `2026_09_05_130000_add_grade_to_systems`
- `2026_09_05_140000_create_character_modifiers`
- `2026_09_05_150000_add_modifiers_to_skills`
- `2026_09_05_160000_grant_the_first_skill_effects`
- `2026_09_05_170000_scale_skill_grants_down`
- `2026_09_05_180000_create_implants`
- `2026_09_05_190000_add_the_dummy_implants`
- `2026_09_05_200000_remember_when_a_system_was_first_seen`
- `2026_09_14_100000_name_systems_for_their_owners`


## [0.8.1] — 2026-09-04

### Added

- feat(inventory): item actions live on the card, and the sidemenu gets a Deck (566a510)

## [0.8.0] — 2026-09-03

### Added

- Modules now create heat that has to be managed.
- All Deck modules now have heat tolerances: one value for where
  the module starts being at risk for melting, and a cutoff where
  the module 100% gets destroyed.
- We now have a new slot for Thermal Dissipators on decks.
- New modules: Thermal Dissipators. These can be active or passive.

### Migrations

- `2026_09_03_170000_add_board_layout_to_items`
- `2026_09_03_180000_create_deck_fittings`
- `2026_09_03_190000_add_equipped_deck_to_characters`
- `2026_09_03_200000_add_heat_to_cyberdecks`
- `2026_09_03_210000_add_heat_tolerance_to_deck_modules`
- `2026_09_03_220000_remove_deck_heat_capacity`


## [0.7.1] — 2026-09-03

### Changed

- Inventory view - list items + mousover modal with info
- Move top menu to a sidemenu, clear out unused stuff
- Introduce a fixed topbar containing cash, hp, si


## [0.7.0] — 2026-09-03

### Changed

- Archetype starting items/skills/cash (6d60b66)

### Migrations

- `2026_09_03_140000_create_archetype_loadouts`
- `2026_09_03_160000_narrow_the_roster_to_the_deck_jockey`


## [0.6.1] — 2026-09-03

### Changed

- docs: deck numbers are bases, and one deck is equipped (de94e66)

### Fixed

- fix(decks): label the PSU socket PSU, not Power (600a359)

### Added
- content: a bunch of modules and decks added

## [0.6.0] — 2026-09-03

### Changed

- Decks and modules (4bab64a). There are now cyberdecks and modules, decks
  have slots for various types of hardware, and hardware have various 
  properties.

### Migrations

- `2026_09_03_090000_create_cyberdeck_hardware`


## [0.5.1] — 2026-09-03

### Changed

- Remove the hardcoded catalogue of items (0670e10)


## [0.5.0] — 2026-09-02

### Changed

- Initial admin tools (items, skills) (aadd96f)

### Migrations

- `2026_09_02_190000_add_admin_flag_to_users_table`


## [0.4.0] — 2026-09-02

### Changed

- Change tiers to T1 & T2 only (c978030)
- Updated the release script so we can do our work with main checked out, it
  branches automatically to a feature and does all the magic when called

### Migrations

- `2026_09_02_180000_narrow_item_tiers_to_two`


## [0.3.0] — 2026-09-02

### Added

- Market restocker, every eight ours the market gets seeded. Depends on rarity,
  and previous purchase demand.
- Items now have rarity (common, uncommon, rare, ultra_rare).
- `market_purchases` now contains last 7 days purchases of items.
- `config/market.php` sets the rarity seed chances, caps and thresholds

### Migrations

- `2026_09_02_170000_create_the_market_supply_side` — adds `rarity` (defaults
  to `common`) and `seed_quantity` (defaults to **0**, meaning the market never
  stocks it) to `items`, and creates `market_stock` and `market_purchases`.
  Run `db:seed --class=ItemSeeder` afterwards to give the existing catalogue
  its shelf sizes, or nothing will ever be in stock.

## [0.2.3] — 2026-09-02
### Added
- build: add bin/release script to handle versioning, changelog etc.

## [0.2.2] — 2026-09-02
### Added

### Changed
- Skill tree changed to a list with accordions. Marks which skills you have injected
  and displays current level. 

### Fixed
- Overspill from skill level breakpoints now handled correctly.
- Reflex is supposed to be a physical attribute, not a mental one. Fixed now.
- The meters on skill levels on the skill page now displays correctly.

## [0.2.1] — 2026-09-02

### Added
- Skill tree at /skills

## [0.2.0] — 2026-09-02

### Removed
- Completely remove i18n. Having the game translatable at this stage is just too
  much effort and leads to weird stuff where some things live in db and some
  things in files.

### Changed
- Moved archetypes fully to db

### Migrations

- `2026_09_02_150000_remove_the_translation_layer` — adds `name` and `flavor`
  to `items` (backfilled for the seeded catalogue), drops `users.locale`.
- `2026_09_02_160000_create_archetypes_tables` — creates both tables and
  **inserts the roster**, ids preserved. Reference data whose ids are
  load-bearing has to exist the moment the schema does, so it is not a seeder.
  Clears any `characters.archetype` that names no archetype before adding the
  constraint; such a row was already broken and granted no perks.

## [0.1.1] — 2026-09-02

### Changed

- Self host **IBM Plex**. We already host Overpass, and we don't want the round-
  trip to google servers.

## [0.1.0] — 2026-09-02

First tagged version. Everything before this was just pushed straight onto `main`.

### Added

- There are now inventories, and `container` decides if it's a physical inventory
  or data. 
- We can now create skillbooks and inject them
- There are now container items
- /inventory is a first step

### Changed

- Items can now stack (`stack_key`).
- Increased the font-size slightly across the board

### Migrations

- `2026_09_02_120000_create_items_tables` — `items` and `inventory_items`.
- `2026_09_02_130000_add_containment_to_inventory_items` — adds `capacity` and
  `accepts` to `items`, `parent_id` and `stack_key` to `inventory_items`, and
  drops the old composite unique key.

[Unreleased]: https://github.com/nahkampf/dedlok/compare/v0.12.0...HEAD
[0.12.0]: https://github.com/nahkampf/dedlok/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/nahkampf/dedlok/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/nahkampf/dedlok/compare/v0.9.1...v0.10.0
[0.9.1]: https://github.com/nahkampf/dedlok/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/nahkampf/dedlok/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/nahkampf/dedlok/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/nahkampf/dedlok/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/nahkampf/dedlok/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/nahkampf/dedlok/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/nahkampf/dedlok/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/nahkampf/dedlok/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/nahkampf/dedlok/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/nahkampf/dedlok/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/nahkampf/dedlok/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/nahkampf/dedlok/compare/v0.2.3...v0.3.0
[0.2.3]: https://github.com/nahkampf/dedlok/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/nahkampf/dedlok/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/nahkampf/dedlok/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/nahkampf/dedlok/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/nahkampf/dedlok/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/nahkampf/dedlok/releases/tag/v0.1.0
