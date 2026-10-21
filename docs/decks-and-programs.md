# Cyberdecks, programs, locators

_Split out of CLAUDE.md. Heading levels are as they were there._

### Cyberdecks and modules

**A cyberdeck is an item, and so is every module.** One column says which; two tables carry
what a bare `items` row cannot.

- **`items.hardware` is the only place the kind is written down** — `deck`, `cpu`, `npu`,
  `ram`, `nic`, `expansion`, `psu`, or NULL. `deck_modules` carries numbers and *not* the
  word.
- **`deck_slots` is one row per socket, not a count per kind** — a fitted module points at
  the socket it went into, and a count cannot be pointed at. `App\Decks\SlotLayout` is the
  same set as counts, and pure (the editor needs a layout for an unsaved deck).
- **`App\Decks\SlotType` is the whole vocabulary**: fourteen (kind, variant) pairs. A
  fifteenth socket is an enum case and a label.
- **A cap belongs to the kind, not each variant** — five expansion slots means five
  altogether. Not a CHECK constraint (sockets are separate rows);
  `ItemRequest::withValidator()` holds it, with "a deck needs a CPU socket" and "a deck
  needs a power socket".
- **`universal` is a socket and never a module.** The two sides validate against separate
  lists and fit is `Variant::accepts()`, never `===`.
- **An IO port seats nothing** — electrode and neuralink ports are rows like any socket.
- **At most one measure per module** (`chk_deck_modules_one_measure`): qFLOPS, quantabits,
  QB/s and watts do not convert. An expansion card fills none.
- **A supply gives power and draws none** (`chk_deck_modules_supply`).
- **`App\Decks\HardwareEditor` is the only writer**, and it always clears the side an item
  stopped being. **It diffs sockets, it does not replace them** — a wholesale replace would
  empty every player's deck whenever an admin touched an unrelated field. The diff is by
  type, and when there are too many it drops EMPTY sockets first; removing an occupied one
  returns the module to its owner.
- **A module's spec line never says what it is.** `hardwareSummary()` gives the numbers;
  `Item::kindName()` answers what it is — deck hardware says which piece, everything else
  falls back to its inventory (`items.kinds.*`).
- **A deck's board is a picture, and it lives on the item.** `items.board_layout` is a JSON
  map from `SlotType::key()` to `[x, y]` percentages; `App\Decks\BoardLayout` turns it into
  nodes and wires. **A node is a type, not a socket** — four QDRAM sockets are one box.
  **NULL means unarranged, not missing**: the automatic ring draws it and stored
  coordinates are an **override on top of that ring**, so a partial arrangement is normal.
  `<x-deck-board>` is the one renderer; the admin's drag editor is the same component with
  `editable` flipped.
- **A deck has no thermal rating and heat does not happen to it.** Power is measured
  against fitted supplies; heat is measured against nothing at deck level. Do not add a
  chassis cap. On screen heat's meter ends at the highest resolved LIMIT anything fitted
  carries (`ThermalExposure::scale()`) — a scale, not a rating — with every module's
  warning and limit ticked on it (`.meter__mark`). No module with a limit, no meter.
- **Every module makes heat** (`deck_modules.heat`) and a **radiator takes it away**
  (`Hardware::Thermal` in a `thermal` socket, measure `cooling`). A radiator cools and
  heats none, but still draws.
- **A module's heat tolerance is a pair of thresholds on the DECK'S heat** —
  `heat_level_warning` and `heat_level_limit`, nullable together, warning ≤ limit. **A
  deck's tolerance is its weakest part, not the sum**, which is why
  `App\Decks\ThermalExposure` is separate from `DeckTotals`. Both thresholds are resolved
  stats.
- **The two warnings are deliberately different volumes.** Over warning: an amber callout
  naming the parts. Past limit: `.hud-alert`, the only accent on the deck screen.
  `.slot--hot` pulses slowly on the board (a condition, not an event); `.slot--doomed` adds
  the crit fill.
- **Destroying modules in an overheating deck is NOT built**, nor the accumulation that
  would cause it. `DeckTotals::netThermalLoad()` is a RATE and nothing runs a deck over
  time. What exists is `ThermalExposure`'s static verdict (`ThermalVerdict::OverLimit`).
- **A module goes into a socket, and `deck_fittings` is that row** — `deck_id` (inventory:
  which deck), `deck_slot_id` (definition: which socket), `module_id` (inventory: what went
  in). **Only the pair is a place.** `App\Decks\ModuleFitter` is the only writer.
- **Fitting is not containment.** A container asks whether the bulk fits; a socket asks
  whether this is the thing this place takes. `parent_id` stays NULL for a fitted module —
  `InventoryItem::scopeLoose()` is what takes it out of the player's hands.
- **Destroying a deck frees its modules**, unlike a memory shard.
- **Modules do not stack** — a fitting points at an inventory row.
- **Equipping is not jacking in.** Jacking in is entering cyberspace; equipping is which
  deck you carry. Never one word for the other in a player-visible string.
  (`auth.login`/`auth.logout` still say "Jack in"/"Jack out" — pre-existing.)
- **An equipped deck IS in the stash.** `scopeLoose()` leaves out four things that really
  are elsewhere: inside a container, fitted into a socket, running, installed in a body.
- **`characters.equipped_deck_id` is which deck is carried**, NULL being ordinary. A column
  rather than a flag, so "one at a time" is not a rule anybody enforces.
  `App\Decks\DeckEquipper` is the only writer; equipping a second swaps rather than
  refusing.
- **Stripping destroys nothing**, so nothing confirms it. Unequipping DOES ask, because
  there are two right answers (leave it built, or strip on the way out). The two are
  independent in both directions.
- **`App\Decks\DeckTotals` is the ONE place a deck is totalled.** It sums by MEASURE, not
  socket — qFLOPS comes from a CPU or an NPU.
- **`App\Decks\DeckRequirement` says whether a deck will run** — compute, memory, link,
  power, draw within supply. One enum case plus one `match` arm each. **Nothing refuses a
  fitting because of it**, including one that busts the budget; the screen names what is
  missing.
- **A deck's measures are RESOLVED, not read off the row.** Five stat ids — `deck_qflops`,
  `deck_quantabits`, `deck_bandwidth`, `deck_power_draw`, `deck_power_supply`, all tagged
  `deck`. **The equipped deck resolves; every other deck shows bases**, and
  `DeckTotals::forCharacter()` is the one place that rule lives.
- **Readiness gates jacking in and starting a program, and nothing else.**
  `DeckTotals::isReady()`; callers are `Navigator::jackIn()` and `ProgramRunner::start()`.
  Fitting and equipping are never refused for it.
- **The deck is locked while you are jacked in.** Fitting, taking out, stripping,
  unequipping and destroying the deck or anything in it are refused — three writers
  (`ModuleFitter`, `DeckEquipper`, the destroy path) asking ONE question,
  `Character::isLockedInCyberspace()`. Only the EQUIPPED deck. **The lock guards what the
  PLAYER does and must never be asked by the game's own consequences** — a Surge or a
  burnout destroys fitted hardware precisely while somebody is jacked in.
- **Nothing a deck stores makes its owner better at anything yet** — the `ModifierSource`
  over fitted hardware that MECHANICS names is missing.
- **Every stored measure is a BASE.** Resolve through `App\Stats\Resolver`, never read the
  column; a deck's totals are per character and may never be cached.
- **A deck that cannot power every socket it presents is intentional.** Do not "fix" one.

### Programs

**Software is an item with a `programs` row**, as deck hardware is an item with a
`deck_modules` row. No column on `items` says which.

- **Three costs, paid at different times.** `storage_size` is paid by OWNING;
  `qflops_cost` and `memory_cost` only while it RUNS.
- **There is no "loaded".** A program runs or it does not. `background_process` says which
  it is ALLOWED to be; the refusal is `ProcessRefusal::RunOnly`.
- **Running is two columns on the inventory row** — `running_on_deck_id` and `started_at`,
  null exactly together — never a join table. It has to be on the row because `stack_key`
  is derived from the row and IDLE copies stack while RUNNING ones do not.
- **`App\Programs\ProgramRunner` is the only writer.** Starting takes one copy off the pile
  and gives it a row; stopping puts it back and MERGES (not optional — `stack_key` is
  unique).
- **`ProgramRunner::refusalFor()` is the one list of checks**, asked by `start()` and by `startable()`, so the Running
  modal's **Could run** list (every loose, idle, background-capable program the deck would accept, each with a *Run*
  button posting to `inventory.run`) offers exactly what a press would do. It is fetched when the modal opens
  (`programs.startable`, a fragment, `ui-kit.js`) rather than drawn in the layout, because working it out totals the
  deck and the layout is on every page.
- **Always the equipped deck**, which is why `start()` takes no deck argument. So
  unequipping stops everything, and so do fitting, stripping and destroying:
  `stopAll()` is called by `DeckEquipper`, `ModuleFitter` and the destroy path.
- **`running_on_deck_id` is RESTRICT, forced rather than chosen.** MySQL refuses SET NULL
  (error 3823) because the column is in a CHECK — rightly, since nulling the deck id would
  strand `started_at`. So the destroy path stops processes first, in the same transaction.
- **Compute and memory are measured against the deck's RESOLVED totals.**
- **The kind is asked about BY NAME, not derived from modifiers** — icebreaker, decryptor,
  evasion, analyser, daemon. A program is a gate as much as a modifier.
- **`programs.modifiers` is nullable JSON and null is ordinary.**
  `App\Stats\Sources\RunningPrograms` reads them, and **only the equipped deck's
  processes count**.
- **`ProgramEditor` is the only writer of the `programs` table** and clears the side an
  item stopped being. Nothing to diff — a process points at an inventory row.
- **How many copies of one program may run is `program_threads`** — one by default
  (`config/programs.php`), raised by Multithreading, and **a ceiling per program rather
  than a total**. `ProgramRunner` counts running rows of the same item across every deck.
- **Programs of one KIND diminish.** Every running program of one kind (two Surveyors, or a
  Surveyor and any other analyser) is a stack per op and target: the PROGRAMS are ranked
  strongest first (`Modifier::magnitude()` summed) and the Nth gives
  `stats.diminishing[N-1]` of what it does there — 100%, 86%, 57%, 26%, 10%, 3%, then nothing — via
  `Modifier::scaledBy()` (a multiplier scales its distance from one; an override is
  untouched; drawbacks scale too). The unit is the program, never the modifier — a
  program's five per-level grants do not penalise each other. Different kinds
  never discount each other. **This is the game's rule for things of one type stacking
  anywhere**: the next source that can hold two reads the same list.
- **`programs.multithreaded` decides whether more than one is a question at all** — a
  property of the software, false by default, exposed in the item editor.
- **Run-only software is the LOADOUT, chosen on the briefing.** `RunStarter::start()` is
  the only caller passing `$duringRun`, and it starts the loadout BEFORE the arrival roll
  (evasion that came up after the system looked hid nobody). A program that will not start
  refuses the whole run with its own reason and anything already started is put back.
  `RunStarter::end()` stops everything run-only (`ProgramRunner::stopRunOnly()`). **It
  cannot be changed inside a run.** Jacking in is NOT a run and must not pass `true`.
  **The last loadout that went in is remembered** in the `run_loadout` cookie, as ITEM ids
  (a row id does not survive its stack splitting and merging) and ticked on the next
  briefing. Per browser, deliberately — no column, no migration. A refused run is not
  remembered.
- **Scripts are ticked on the briefing too** (a *Scripts* box listing the loose Scripts held, their effects, cost and lifetime; `scripts[]`).
  They are started by `RunStarter::start()` with the loadout and before the arrival roll, and are spent by running. **A program that will not
  start ROLLS THE TRANSACTION BACK** (`ProcessRefused`) rather than stopping what already started, because stopping a Script destroys it. The page
  does the RAM/qFLOPS sum in `ui-kit.js` (`data-run-budget`, tenths) and disables what no longer fits; the runner's own checks are the guard.
- **One icebreaker at a time**, whichever ones — `ProcessRefusal::OneIcebreaker`.
  `icebreaker_damage` sums every running program, so two cheap ones would out-cut a dear
  one and *twenty turns is never enough* would stop being true.
- **Storage IS enforced, on the equipped deck.** `App\Decks\Storage` is the one measure:
  every loose data row counts (programs at `storage_size`, a file at its own QB, anything
  else at `items.size`, idle, running or listed alike), a row inside a shard does not.
  Capacity is the resolved `deck_storage`, which starts from the deck's own
  `items.onboard_storage` (8 QB on an Explorer, 64 on the top three) — NULL falls back to
  `decks.onboard_storage` (16), and only a deck may carry one. No deck holds nothing. **Every door that adds data asks `fits()`** —
  market, player market, loyalty store, scan export, taking out of a shard, extraction —
  and refuses with its own `NoStorage`; the archetype loadout does not. Extraction leaves
  what does not fit ON THE SYSTEM, and files are all-or-nothing because the pull was
  priced on their total. **Nothing is ever deleted for being over**: a smaller deck may
  be equipped, and `DeckRequirement::Storage` then keeps it from jacking in or starting
  programs until the player makes room.
- **A SCRIPT is a program with a lifetime** (`ProgramKind::Script`, `programs.lifetime_seconds`, NULL for an ordinary one; `Program::isTemporary()`): it is
  spent by running. *Run* takes one copy as for any program and it holds its RAM and qFLOPS on the deck; it ends at `started_at` + the lifetime
  (`App\Programs\Lifetime`, nothing stored about the end) and **stopping it early destroys it** (`ProgramRunner::putBack()`) — which includes every
  way of stopping all (unequip, fit, strip). Expiry is lazy and swept: `Lifetime::reap()` runs inside `Character::runningProcesses()` and
  `RunningPrograms::processesOn()` and before a start, and `programs:expire` runs every minute for characters nobody is looking at. One of a given
  Script at a time is not a new rule: it is `multithreaded = false`. `RunningPrograms` cannot look back at a Script that has been reaped, so none belongs on
  training stats. Current processes shows a bar for each.
- **Storage, memory (QB), compute (qFLOPS) and file sizes are carried to ONE DECIMAL PLACE** (`DECIMAL(10,1)` on `programs.storage_size / memory_cost /
  qflops_cost`, `items.size / capacity / onboard_storage`, `deck_modules.qflops / quantabits / storage`, `system_contents.size_qb`,
  `runs.extracting_qb`; the stats `deck_qflops / deck_quantabits / deck_storage` round to a `tenth`), so a lightweight script can cost 0.4 QB of RAM.
  PHP holds them as floats, so **every total goes through `App\Support\Qb::of()` and every "does it fit / can it start / is it over" comparison
  through `Qb::tenths()` or `Qb::fits()`** (`Storage::fits`, `ProgramRunner::refusalFor`, `DeckTotals::isOverfull`, `ItemPlacer`, the extractor): 0.1 + 0.2 is
  not 0.3 in a float. Models cast them `float` (never `decimal:1`, which is a string); `Qb::format()` prints `2` for 2.0. Bandwidth, watts, power
  draw and thermal figures stay whole numbers.
- Screens: topbar count + modal, deck screen's Running and Storage tabs (storage split
  into two grids), `inventory.run` in both directions.

### Locators

**A Locator is a background program (`ProgramKind::Locator`) that finds the nearest system you have MET; a Pathfinder (`ProgramKind::Pathfinder`) is one that also walks you there.** `App\Cyberspace\Finder` is the whole of it, and the map screen's *Locator* box (drawn only while one runs, tagged with the program's name) is its only screen.

- **One slot between them** (`ProcessRefusal::OneLocator`, in `ProgramRunner::refusalFor()`).
- **It reads the map's memory (`system_contacts`), never `systems`**, and a grade is known only from a live scan, so a grade term matches nothing unscanned. The **portal is always known** (`Grid::portal()`, added as an unsaved sighting) and has no owner or grade.
- **The query is one line of terms** (`exchange, lockjaw, grade >2`): a system type, a grade comparison, anything else is the owner. Result: nearest in steps (Chebyshev, then straight line), shown as one of eight arrows (`Finder::direction()`, north is a smaller y) and the distance. Measured from the portal when not jacked in.
- **Autotraverse** (Pathfinder only, jacked in): the page posts `cyberspace.traverse` every ten seconds (`data-autotraverse` in `ui-kit.js`), each post one ordinary `Navigator::step()` toward the target (so a sweep and a hunter roll as by hand). The overlay is the dialog's backdrop (`dialog.modal--traverse`). It lives in the open page: reloading or leaving cancels it, and a refusal or a hunter stops it.
- The two items (`azimuth`, `loco_motive`) arrive by migration `2026_10_09_320000_the_locators`.


### The Tracker

**A Tracker is a Utility with `programs.tracks`**, and `App\Cyberspace\Tracker` is the whole of it, with `tracker_searches` (one row per RUNNING copy: owner, type, grade, and `pass_started_at`, NULL until released) as its only table. The map screen's *Tracker* box (drawn only while one runs) is its only screen.

- **Filter, then release.** *Set filter* opens a modal of owner (any entity that owns a system), type and grade; at least one is required, and locking it in takes the Tracker back to waiting. *Release Tracker* starts the passes.
- **A pass is `programs.tracker.pass_seconds` (60).** It picks one random place on the character's grid and looks at it and the eight around it (real systems, not what is known; a locked-down one is skipped; an Exchange's grade is the desk's). The first fit stops the program and sends *Tracker: Node found* with the address.
- **Two costs.** Waiting, it costs its row's compute and memory. Running passes it holds `programs.tracker.active_*` (2 and 2) instead, which `ProgramRunner::spentOn()` reads; **a release the deck cannot hold is refused** (`ProgramRunner::hasRoomFor()`) and nothing starts. The deck is checked at release only.
- **Settling is lazy and swept** (`tracker:settle`, every minute): whole passes since `pass_started_at`, at most `max_catchup`. Stopping the program by any route deletes its search (`ProgramRunner::putBack()`).
- The item arrives by migration `2026_10_24_100000_the_tracker`.
