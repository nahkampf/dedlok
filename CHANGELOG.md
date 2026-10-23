# Changelog

## [Unreleased]

## [0.75.2] — 2026-10-24

### Fixed
- **A defeat could be resolved several times.** A lost run stays live until `Simulator::resolveEnding()`, so overlapping requests for the same turn (a double tap, a slow answer) each reached it with a copy of the run that still read as live, and each applied every defeat tag. Noodle lost a deck's files, six modules and several rounds of creds and max SI to one ICE XII. `resolveEnding()` now locks the run row and re-reads `ended_at` in a transaction; a caller that finds the run already ended resolves nothing (`ResolveEndingOnceTest`). The body moved unchanged to `settleEnding()`.

### Changed
- Migration `2026_10_27_110000_restore_noodles_double_defeat` makes Noodle whole: the six lost modules back in the stash (which fitting each sat in is not recorded), 1,934 creds through the wallet as `granted` (the one extra skim the balance actually lost; three read the same balance and wrote it back once), and 15 points of neural damage healed (four flatlines landed where one was owed, and the real one is not recorded, so he keeps the smallest the arithmetic allows). Does nothing without a character named Noodle. No schema change.

Known and not fixed: `Wallet::move()` reads creds off the in-memory character and writes them back, so two simultaneous writers on other paths can still overwrite each other.

Run `php artisan migrate`.


## [0.75.1] — 2026-10-27

### Fixed
- **Shop listings showed no effects for expansion cards, icebreakers or analysers.** `<x-program-spec>` now lists an expansion card's `items.modifiers` and a program's own `programs.modifiers` (an icebreaker's damage, an analyser's profiling bonus), worded as the stash card words them. The stash card (`item-detail`) now includes a program's modifiers under Effects too.
- **An expansion card's own number was never stated.** `DeckModule::measure()`/`measureLabel()` fall back to whichever measure column the row sets (qFLOPS, QB, cooling, storage) when the kind has none of its own, so the shop line reads "20 TU cooling · 8 W draw" (`DeckHardwareTest`).


## [0.75.0] — 2026-10-27

### Added
- **The runner dot on the cyberspace map opens a modal** listing everyone on that square: avatar, handle and what they are doing (`RunnerActivity`: Running for a live run, Scanning while a scan pass is pending, otherwise Idle). `MapView` groups runners by coordinate and hands `MapCell` a `runnerList`; one `<dialog id="runners-X-Y">` per occupied square (`WardenTest`). Strings `cyberspace.map.runners_here` (placeholder title) and `cyberspace.map.activity.*`. The dot does not yet open with Enter or Space.
- **Forty pieces of deck hardware**, migration `2026_10_27_100000_new_deck_hardware`: 13 storage modules, 4 CPUs, a QDRAM stick, a second tier-1 NPU, and XGate, PEI and Horizon cards, each with its `deck_modules` row and a Chrome Palace listing at list price (5 on the shelf, one back every two hours). Flavour is a placeholder. Skips anything that already exists by slug. Replaces the deleted local `DeckHardwareSeeder`.

### Changed
- **Loyalty from Extract, Transport and Denial jobs is ×3** (`config/missions.php` `actions.*.loyalty`: extract 1.5 to 3.0, transport and denial 1.5 to 3.0). Applies to jobs generated from now on.

### Fixed
- **An extraction job showed no tick in Tasks & Missions once the decrypted file was in hand**, though the exchange accepted it. Carried-evidence jobs are only satisfied at hand-in; the card now ticks the target as "in hand" (`MissionLedger::holdsDecryptedJobFile()`, `mission-card.blade.php`). The "N OF M DONE" count still counts only satisfied targets.

Run `php artisan migrate`.


## [0.74.0] — 2026-10-23

### Fixed
- **A subnet fortress's Tap and Wiretap swept main-grid squares.** `FortressProduction` now passes the fortress's `grid_id` to the range query, so only nodes on its own grid are in reach (`FortressHostTest`).
- **Installed fortress ICE and IDS lost a point in the first second.** `FortressDefence::effective()` floored the decayed value; it now rounds.

### Removed
- **The Electrode and NeuraLink IO ports.** `SlotKind::Io` and `Variant::Electrode`/`Neuralink` are gone, with their strings, the deck board's "seats nothing" branch, the one-of-each cap in `SlotType::max()` and the docs. `SlotKind::seats()` is no longer nullable. Migration `2026_10_26_150000_remove_the_io_ports` deletes the `io` rows from `deck_slots`, drops any `io_*` keys from `items.board_layout` and takes `io` out of `chk_deck_slots_kind` and `chk_deck_slots_variant` (MySQL only; the rollback restores the allowance, not the rows).

### Added
- `database/seeders/DeckHardwareSeeder` (run by hand, local only, not in `DatabaseSeeder`): 40 pieces of deck hardware (13 storage modules, 4 CPUs, a 4 QB QDRAM stick, a second tier-1 NPU, and XGate, PEI and Horizon cards), each listed at Chrome Palace at list price, 5 on the shelf, restocking one every two hours. Items are authored in the live admin, so this reaches production only when authored there or migrated.


## [0.73.1] — 2026-10-22

### Fixed
- **Study, Work out and Simstim showed no fatigue until somebody opened the City.** They are credited lazily, and the top-bar dial never settles them. `characters:regenerate` now settles every running session before it eases fatigue, so the dial moves each minute (`SafehouseSessionTest`).
- **The item editor hid the Module panel for Storage and Thermal items**, so a storage device's QB could not be edited. The panel's `data-hardware` list now names both.

### Added
- The fatigue card on the character sheet lists the Tired, Drained or Exhausted effect you have, one line per attribute (`character.vitals_effects`, `CharacterController::fatigueEffects()`).

### Changed
- Attribute rows on the character sheet read label, number, bar (`.vital--wide`, and the kit gallery's demo).

### Removed
- The `fortress_daemon` program kind: nothing was gated on it. The `programs.kind` check constraint still lists the value, which is harmless.


## [0.73.0] — 2026-10-22

### Added
- **Story messages from encounters**: `OutcomeKind::Message` (sender in `ref`, title in `text`, words in `entry_text`, delay in `amount` seconds, 0 or none for at once, at most 48 hours). `Notifier::story()` writes it straight to the inbox in the new `Category::Story`, or holds it in `scheduled_messages` (migration `2026_10_26_110000_encounter_messages`) for `messages:deliver`, scheduled every minute. The encounter editor has fields for it.
- **"A strange dataset"** sends a Non-Disclosure Agreement from Joint Signals Command's legal department twenty minutes after the ending (migration `2026_10_26_140000_nda_after_the_strange_dataset`); the encounter is `once`, and so is the message. Migration `2026_10_26_100000_njord_encounter_fixes` repairs the Njord encounters (the ghost process now sets `njord_encountered`, and the status file no longer loops back to the help file).
- **Netrunner** (achievement 21): a successful run on a grade 9 system. `Metric::RunGradeCompleted`, a high-water mark reported by `RunStarter::end()` when a run ends `Completed`.
- **Wormhole** (`tunnel`, Secure Tunnelling): program, Design and Design Copy, 1/1/1 to run, made under Utility Crafting (migration `2026_10_26_120000_wormhole_secure_tunnelling`).
- **Lockjaw sells** the Design and Design Copy of the Nomad, the Wormhole and every Leecher: 300 and 75 loyalty, standing 2, desks of grade 1 and up; the large Leechers (Poacher, Stripminer, Vampiir) from grade 4 and standing 4 (migration `2026_10_26_130000_lockjaw_sells_nomad_wormhole_leechers`).
- **Error pages**: every common HTTP error (400 to 503) is rendered in the kit with its own words from `errors.http.*` and a link back (`resources/views/errors/`, `ErrorPageTest`).
- The safehouse describes each action beside its button (`safehouse.blurb.*`).
- The inbox's tracker message carries the address it found, so it can be jumped to.

### Changed
- **The stash sorts by type, then by name**, each case-insensitively. `Character::inventoryIn()` handed `sortBy` a list of one-argument closures, which Laravel calls as comparators, so it never sorted at all and the stash came out in the order things arrived.
- Both stash tabs reopen where they were left (`data-tab-remember`, kept in `sessionStorage`), so an action that comes back to the page lands on the same tab.
- A Decryptor takes its first waiting file the moment it is started rather than at the next sweep (`ProgramRunner::start()` settles the queue).
- An achievement is worth 0.5 standing with every entity, down from 1 (`standing.reasons.achievement`).
- The safehouse buttons are disabled while any session is running, not only their own.
- A mission card's target name spans the icon column it has no icon for.

### Migrations
- `2026_10_26_100000_njord_encounter_fixes`, `2026_10_26_110000_encounter_messages` (new table `scheduled_messages`), `2026_10_26_120000_wormhole_secure_tunnelling`, `2026_10_26_130000_lockjaw_sells_nomad_wormhole_leechers`, `2026_10_26_140000_nda_after_the_strange_dataset`. The last three are content and skip themselves under test.


## [0.72.1] — 2026-10-21

### Added
- **Sudo** for admins: a card on the admin dashboard with a handle autocomplete signs the admin in as that character; an *Exit sudo* button in the side menu returns them (`Admin\SudoController`, `sudo.exit` outside the admin gate and on every lock's allow-list, so it works mid-run or mid-fight).
- **`FittedModules`, the eighth modifier source**: `items.modifiers` of every module fitted in the equipped deck. An expansion card's effect (the Hitachi MemCartridge III's +4 `deck_quantabits`) now applies; a module's own measure is still its `deck_modules` column.
- Work out and Study show a small progress bar toward the next attribute point.

### Changed
- Work out and Study never cross the collapse line: `HomeActions::settleSession()` credits only up to `safehouse.sessions.margin` (1) short of the fatigue maximum, ends there and starts a Rest from that moment (or just ends if resting is not possible), with an inbox message. The planned length now honours `fatigue_taken`.
- An Exchange's grade in Known systems and on the map is the desk's own (`exchanges.grade`), not the system's floor grade.


## [0.72.0] — 2026-10-21

### Added
- **Contracts**: a player offer with one named buyer. `market_offers.recipient_character_id` (migration `2026_10_25_100000_contracts`, cascades, so a deleted recipient takes the contract with it). The item card has a *Recipient* field (handle suggestions, the message screen's `data-suggest`, now delegated in `ui-kit.js` so it works in the inspector's cloned card) and an **Add contract** button beside *Put on market*; the market button refuses a typed name and Add contract refuses a blank one. `MarketOffer::visibleTo()` shows the recipient and the seller the offer and nobody else; `OfferBook::buy()` answers anybody else `Gone`; chat links never name one; the recipient gets an inbox row (`offer_contract`). The market screen has a collapsible *Contracts* accordion beside a now collapsible *Sales orders*.
- Overview map: clicking a cell copies the address of the system there, or `x,y` when there is none; the header says what was copied for three seconds.

### Changed
- Overview map: ground never looked at is the darker grey (`--bg-1`), swept ground with nothing on it a lighter one (`--bg-4`). `CyberspaceController::overview` also returns which ground is swept (rows of 1s and 0s, cached with the systems) and each cell's remembered address. Gateways share the exchanges' brass and ember wash, half a cycle behind.


## [0.71.2] — 2026-10-21

### Added
- The Known systems modal has an **Overview map** button: a full-screen canvas of the whole grid from the character's remembered systems (`system_contacts`), one 10px cell per place, titled with its grid ("Main Grid", or the subnet's name). `CyberspaceController::overview` returns it as JSON and caches the memory for a minute per character and grid; where they stand is read fresh. Cells use the map's node tokens; fortresses are ember, the character's own cell pulses white and black, exchanges and gateways pulse brass and ember (gateways half a cycle behind). The view scrolls toward whichever edge or corner the cursor is near (`ui-kit.js`).

### Changed
- The Running and Current processes modals say Close rather than Cancel (`ui.action.close`).
- Bloodhound fight lines reworded: the hunter's miss, its hit, and the player's hit and critical hit (`cyberspace.hunt.log.*`).

### Fixed
- The ICE meter on the Bloodhound fight (and the fills in the exchange, fight and cyberspace views) was an inline `<span>` and drew nothing; they are `<div>`s like the kit's other meters.


## [0.71.1] — 2026-10-21

### Changed
- Co-op runs: a runner who egresses, is ejected or is reduced to 0 no longer ends the run for the others. The node's lockdown is decided when the LAST runner of the party is out (`RunStarter::end`), as the party left it: a backdoor landed by any of them prevents it.
- Co-op runs: a landed backdoor, like a burn, completes every live run of the party (`PartyTurns`, `Simulator::cancelEveryRunOn`) instead of cancelling the others.
- Co-op runs: the party has one log. Every line is written to each runner's log with who it was about (`by`) and is told in the third person from `cyberspace.run.party_log.*`; lines without a wording there fall back to the runner's own line with their handle in front. `Simulator::line()` now keeps its `key` and `replace`.
- Co-op runs: after choosing an action the run screen says "Waiting for all runners to take action", and a runner who has not chosen yet gets the 5-second refresh once anybody else has.


## [0.71.0] — 2026-10-21

### Added

- **Path of Repentance** now does what its flavour claims, a little: `addiction_recovery +0.01` (a hundredth of a point of addiction a minute off every family, while it runs), beside its `fatigue_taken +10`. Migration `2026_10_24_110000_path_of_repentance_cures` adds the modifier only when the item has none for it.
- **Path of Repentance** leaves the market and is sold at Golden Path's loyalty shelf: 15 loyalty, minimum standing 0, any desk grade (`entity_offers`). Migration `2026_10_24_120000_path_of_repentance_at_golden_path`, which finds the entity and the item by name and slug and is a no-op when either is missing.

### Changed

- **Character sheet**: an attribute's effects (a dose, an implant, an injury) are on its label's tooltip instead of lines under the bar. The label is a dotted link only while something moves it. New kit classes `.tip-link` and `.tip--lines` (a newline in `data-tip` starts a line).
- `fatigue_taken` has a display name (*fatigue taken (%)*), so a card naming it no longer shows the raw key.


## [0.70.0] — 2026-10-21

### Added

- **The Tracker** (`App\Cyberspace\Tracker`): a Utility (`programs.tracks`, tier 2, rare, 500 ¤, off the market) that, given a filter (owner, type, grade) and released, rolls a pass a minute (`programs.tracker.pass_seconds`) at one random place on the grid and its eight neighbours for a system that fits. The first fit stops the program and sends *Tracker: Node found* with the address. Its box on the map screen (drawn while one runs) has *Set filter* (a modal of the same three choices as the known systems) and *Release Tracker*. Waiting it costs its row's compute and memory; running passes it holds `programs.tracker.active_qflops` / `active_memory` (2 and 2) instead, read by `ProgramRunner::spentOn()`, and a release the deck cannot hold is refused (`ProgramRunner::hasRoomFor()`): *Not enough RAM or CPU to run active Tracker*. Passes are settled lazily and swept by `tracker:settle` every minute. Migration `2026_10_24_100000_the_tracker` adds `programs.tracks`, the `tracker_searches` table and the item. The item editor gets a *Tracks* checkbox.

### Changed

- *Show on market* is now *Put on market*.


## [0.69.2] — 2026-10-21

### Added

- **Running modal**: a running decryptor that has a file in hand shows *Decrypting file…* under its name (`programs.decrypting`; the worker is named by `decryption_queue.worker_id`).

### Fixed

- **Running modal**: after running or stopping a program the reload reopened the modal by a synthetic click that fired before the handler fetching the *Could run* list was registered, so the list stayed on its loading line. The reopen click in `ui-kit.js` is now deferred a tick.


## [0.69.1] — 2026-10-20

### Added

- **Location pictures**: a wide banner on the district screen (between the description and *You are here*) and at the top of the safehouse's *What you can do here* panel. Found by convention in `public/images/locations/`: `<district slug>.png`, falling back to `_default.png`; the safehouse uses `safehouse.png`. Stamped through `Asset::url()`. New `.banner-img` in the kit: full width inside the padding, at the picture's own ratio.

### Changed

- **District screen**: *Places you know here* comes before the district map; it and *Get a safehouse here* are accordions (`details.panel`), closed by default. The description is no longer capped at 70ch.
- `CLAUDE.md` is split into `docs/` by subsystem.


## [0.69.0] — 2026-10-20

### Added

- **Safehouse training sessions**: *Work out*, *Study* and *Simstim* (`Action`, `ActivityKind`, `HomeActions`), open-ended `city_activities` like Rest, credited per minute whenever the City is looked at. Work out and Study add `fatigue` and fill a meter (`characters.workout_progress`, `study_progress`, 0–100); a full meter gains one point of a random attribute (Meat/Motor/Muscle, or Intellect/Memory/Focus) and resets. Simstim adds fatigue and mood, capped at `daily_seconds` a UTC day (`simstim_seconds`, `simstim_day`). Figures are placeholders in `config/safehouse.php`. Migration `2026_10_23_100000_safehouse_training_sessions`.
- **Damage report**: a defeat in cyberspace lists what it destroyed.
- **Clinics**: partial treatment; linked places and the safehouse show on the district card; a new Denial mission blurb.

### Changed

- **Inbox**: *Reply* opens the compose screen with the title `Re: <title>` and the original quoted below, each line prefixed `> ` (`?re=` names the message; only your own personal messages are quoted).
- Resting in a safehouse also mends hit points.

### Fixed

- **Inventory**: destroying from a pile destroys only the number asked for.
- **Programs**: the Running modal stays open after running or stopping a program.
- **Release**: `bin/release` no longer asks before committing, and prints the failing tests' output. `CharacterTest` lists the new `characters` columns.


## [0.68.1] — 2026-10-20

### Fixed

- **Skills list**: the rows on the Skills tab lost their icon in 0.68.0, which left the name and level meter squeezed into the 32px icon column of the `.item-row` grid. The icon is back.
- **Subnet editor**: validation refusals name the fields as the form does ("Entry across", not `entry_x`), and the entry coordinates carry a hint saying they count from the top-left corner.
- **CI**: long patch notes are sent to Discord in pieces under its 2000 character limit.


## [0.68.0] — 2026-10-20

Subnets: smaller grids behind gateways, with their own hunters, fortresses and a new kind of mission.

### Added

- **Subnets**: a `grids` row per grid (`grid_id` on the coordinate tables), prefixed addresses (`mil:3FCD`), paired gateways (`systems.links_to_system_id`) and a resume point (`characters.resume_x/y`). `Subnets` is the only writer of non-main grids and their gateway pair; `GridSeeder::forGrid()` fills one from its spec (`cyberspace:seed <slug>`); admin *Subnets* creates, edits and generates them. Systems relocate, restock and lock down inside their own grid.
- **Entry rules** on the main-grid gateway (`EntryRules`): a flag, or an Infiltrate run (`RunPurpose::Infiltrate`, `RunAction::Infiltrate`). Leaving through the subnet-side gateway is free and undefended.
- **Eject** ICE tag (levels I–III): ends the run and lands the runner on the main-grid gateway, still jacked in.
- **Virus** ICE tag (levels I–III) and `App\Cyberspace\Virus`: an infection kept on the character that tapers off over `virus_lifetime`, ticking per run turn in a run and per minute outside one. Two Antivirals, Condom 0.49.1 and Purge Pro, arrive by migration.
- **Denial missions**: `MissionAction::Denial`, `RunPurpose::Denial`, `RunAction::Flood`, `runs.pressure`. Per-grade target and random push-back, a 12-turn limit, one shared meter for a party. The run screen labels the meter "Node bandwidth capacity".
- **Subnet certificates** (`inventory_items.grid_id`, stamped when taken) needed to found a fortress in a subnet; the certificate is locked in the vault. Leechers in a subnet fortress draw only on that subnet's nodes.
- **Secure Tunnelling**: `ProgramKind::Tunnel`, `FortressTunnel` (`fortress_tunnels`), `DamageOrigin::Tunnel`. A running tunnel program on a fortress lets its owner and members tunnel onto it from any grid, even when not jacked in. Listed on the map under *Secure tunnels*.
- **Wardens**: `wardens`, `hunts.warden_id`, `App\Cyberspace\Wardens`, `wardens:move` every minute. Hunters that walk a subnet and attack runners who fail a Stealth check; beating one removes it. Shown as a pulsing ember dot on the map.
- **Other runners on the map**: jacked-in runners within sight show as a yellow dot (`MapCell::hasRunner`).
- **TAO**, a military entity (needs `cyberspace:exchanges` for its desks).

### Changed

- **Resting mends Synaptic Integrity** at `safehouse.rest.synaptic_per_minute` (0.083), counted exactly from the start of the rest.
- **Known systems**: names lose the "Type - " prefix, a ✓ marks fully scanned systems and a ◈ marks a backdoor, new *Fully scanned* filter, a wider modal, and one Jump button per row.
- **Chat addresses** accept the prefixed form.

### Migrations

- `2026_10_21_100000_the_tao_entity`
- `2026_10_21_200000_grids`
- `2026_10_22_100000_virus`
- `2026_10_22_110000_the_antivirals`
- `2026_10_22_120000_denial_missions`
- `2026_10_22_130000_certificates_and_tunnels`
- `2026_10_22_140000_wardens`

After deploying: `sudo -u dedlok php artisan cyberspace:exchanges` to place the TAO desks.


## [0.67.0] — 2026-10-19

ICE and icebreakers work in hundredths; pictures in the chat; fewer misclicks.

### Added

- **Achievements** Loyal Service (100 missions) and The Grind (2000), over a new `missions_completed` metric counted when a job is handed in or delivered.
- **Pictures in the chat**: paste an image into the chat box and it is uploaded (`chat.image`, `App\Chat\ChatImages`, the only writer of `public/images/chat/`), shrunk to 800px wide, re-encoded as a fresh JPEG and said as the line `img:<token>`; `Markup::images()` draws it responsively (`.chat__img`, fits the box, opens full size in a new tab). Ten pastes a minute. A picture lives a week: after that the line reads "[ image wiped by age filter ]" and the hourly `chat:sweep-images` deletes the file.
- **Two-press buttons**: `data-confirm` in `ui-kit.js`. Bookmarks' Forget, a mission's Give it up and a lead's Forget now need a second press ("Are you sure?").

### Changed

- **ICE and icebreakers carry two decimals** like Hit Points and Synaptic Integrity: `icebreaker_damage` rounds to a hundredth, `ice.integrity/attack`, `runs.ice_integrity/ice_attack/ice_damage` and `hunts.ice_integrity` are `DECIMAL(8,2)` through `PointsCast`, and critical hits and the fight code no longer round. A fortress's kernel integrity is still whole.
- **Hand it in** is disabled unless it would work (`MissionLedger::canHandIn()`).

### Fixed

- **Delivery coordinates** are hidden on the board until the job is taken (they could be visited and bookmarked for a free jump).
- **Placement runs** say "Place backdoor" / "Backdoor placed" in the run, as the planning screen does, instead of "Install siphon".
- **Cloaked systems** no longer show as a blank "NOT YET" row on scans: the row appears only once a cloak has been seen through.
- **Exchange**: the board and the Loyalty store fold (board open, store closed), "Buying" folds too, and "On offer" is "Mission board".
- The Jumper's button also appears beside the "Desk at" address on Tasks & Missions.


### Migrations

- `2026_10_20_130000_ice_in_decimals`


## [0.66.3] — 2026-10-19

Skill cards say what they change.

### Fixed

- **Bare "+4%" on skill cards**: fourteen stats that skills raise had no entry under `strings.character.names`, so `Modifier::effectText()` printed only the amount. Attack Vectors now reads "+4% icebreaker damage"; also named are the four decryption-chance stats, siphon yield, heat cooling, deck memory and power supply, recovery time, standing gained, trace hop chance, ensnare time and fortress slots.


## [0.66.2] — 2026-10-19

Jobs can only be taken at the desk; the mood card matches fatigue.

### Fixed

- **Taking a job from a stale tab**: `MissionLedger::accept()` now requires the character to be jacked in on the desk's own node, checked on every accept (`MissionRefusal::NotAtDesk`). Previously a board left open could be accepted after walking to the target elsewhere, so a delivery cost no risk.

### Changed

- **Mood card** on the character sheet is drawn like the fatigue one: a bar from -100 to +100 ticked at the band edges, the band word with the figure, and what the band does to attributes. `Mood::reading()` returns the bar's ratio, fill, marks and effects.


## [0.66.1] — 2026-10-19

The safehouse you live in appears on its district.

### Changed

- **District screen**: the home you hold shows as a hollow-diamond pin on the district map (a fixed spot; safehouses have no coordinates yet) and is listed first under "Places you know here". "Your safehouse" is now the green button. `<x-city-map>` nodes accept an optional `glyph`.


## [0.66.0] — 2026-10-19

Authored safehouses and places to eat reach the live game.

### Added

- **Content authored in the editors, carried by migration** (`2026_10_20_120000_authored_safehouses_and_food`): the cardboard box as tuned (2 slots, stealth 5, rest 1), four safehouses (Sleeper Coffin, a squat, a one-room apartment, Hard Loft) and three places to eat (Kakurega, Pink House, an Automata vending machine) with their menus. Skipped by slug where they already exist.

### Migrations

- `2026_10_20_120000_authored_safehouses_and_food`



## [0.65.0] — 2026-10-19

### Added

- **Fatigue** (`Fatigue`, `current_fatigue`, `fatigue_max` / `fatigue_recovery` / `fatigue_taken`, `config/fatigue.php`): a third resource that rises with grid steps, scans, run turns, fight rounds, travel and exploring and eases by the minute. Tired (75%) and Drained (90%) effects; a topbar dial and a sheet card with markers at both lines.
- **Collapse** (`Collapse`, `characters.collapse_pending`, `DamageOrigin::Exhaustion`): at the maximum the character is flagged and falls at the next request: ends whatever they were in, takes 20% damage, may be mugged, is moved home free, gains Exhausted and is down for two minutes, waking at 95%. `Fights::forfeit()`, `EncounterRunner::abandon()`, `Traveller::relocate()`.
- **Safehouses** (`safehouses`, `characters.safehouse_id`, `admin/safehouses`) with the cardboard box in Knightsbridge Projects as the fallback, and a per-district explore-pass tally (`character_district_passes`). 
- **Renting a safehouse** (`Renter`, `Reason::Rent`, `safehouses:rent`): five explore passes in the district, the first day paid up front, rent daily and never into debt, lost after a further day unpaid. A "Get a safehouse here" / "Your safehouse" panel on the district screen, and a `/safehouse` screen.
- **Safehouse actions** (`HomeActions`, `ActivityKind::Rest`, `safehouses.actions`, `character_safehouse_cooldowns`): Rest (a City activity easing fatigue at the type's rate), Patch yourself up, Meditate and Cold shower with cooldowns; Eat is listed but not built. The admin editor ticks which a type offers.
- **Fullness and food** (`Fullness`, `characters.current_fullness`, `UseEffect::Fullness` / `EaseFatigue`, `UseRefusal::TooFull`, `safehouses.eat_fullness` / `eat_fatigue`): a hidden bar to 50 that drains by the minute; Eat at a safehouse and food items from the stash fill it and ease fatigue.
- **Safehouse storage** (`SafehouseStorage`, `safehouses.storage_slots`, `inventory_items.safehouse_id` / `storage_expires_at`) with the 48-hour Collect message, and **`Meal`** as the one shared definition of a meal (`meal_fullness` / `meal_fatigue`). Fullness drains 0.07 a minute.
- **Food at locations** (`location_meals`, `Kitchen`, `Feature::Food`, `Reason::Meal`): a Menu in the location editor and an *Eat* panel on the place screen.
- **Stimulants block rest** (`drugs.wakefulness`, `HomeActions::tooWired()`, `Refusal::Wired`): doses in effect add up, an opiate or medical dose overrides, and taking one mid-rest ends it.
- **Mood** (`Mood`, `characters.mood`, `config/mood.php`): -100 to +100, moved by events (fights, collapse, injury, meals, doses, raids and the safehouse work below), drifting to 0 at 0.01 a minute and 0.03 beyond ±70, four small effect bands (Focus and Social), a word on the sheet. Everyone starts at 0.
- **Finding and raiding a home** (`Leads`, `safehouse_leads`, `LeadController`, `ActivityKind::Stakeout` / `ScopeOut`, `safehouse.leads.*`): exploring may sense a held home (an `exploration` check floored at 2%) as "Suspicious place N". Stakeouts and *Ask around* work out the owner against the home's stealth; *Scope out the security* works out its security; *Break in* is a stealth + Perception + Senses check (harder by the shortfall until the security is scouted). Inside: *Rob the place* (up to two stored rows, which puts the home on a 24h cooldown, `characters.raid_cooldown_until`) or *Burn it* (the holder loses the home and its storage at once). A failed break-in may bring the police (15%): a talking-to for now, and the scouted security is lost. The Tasks screen lists each lead with *Forget about this*. Attack is not built (no player combat).


### Changed

- **Fullness drains and mood drifts on the minute clock** (`characters:regenerate`).

### Migrations

- `2026_10_18_100000_fatigue`
- `2026_10_18_110000_district_explore_passes`
- `2026_10_18_120000_safehouses`
- `2026_10_18_130000_collapse_pending`
- `2026_10_19_100000_safehouse_renting`
- `2026_10_19_110000_fullness`
- `2026_10_19_120000_meals_and_storage`
- `2026_10_19_130000_location_meals`
- `2026_10_19_140000_drug_wakefulness`
- `2026_10_19_150000_safehouse_raids`
- `2026_10_19_160000_mood`
- `2026_10_20_100000_safehouse_leads`
- `2026_10_20_110000_raid_cooldown`



## [0.64.1] — 2026-10-18

Exchange extract jobs can be completed again.

### Fixed

- **A job file decrypted into a component and could never be handed in** (`DecryptionQueue::outcomeFor()`): decryption rolled every file over the grade's components, a job file included, but `MissionLedger::handInExtract()` takes a Memory Dump and nothing else. An extract job's file (one with a `mission_id`) now always becomes the Memory Dump, for the character's Decryptor and for a fortress Decryptor alike. Found on job #849, whose file came out a Stack Trace and which was then given up for the penalty. Rows already rolled into components are not repaired.

### Changed

- **A job file is named by its state** (`InventoryItem::label()`, `inventory.files.job_encrypted` / `job_decrypted`): `Job file: User database (Encrypted)` while sealed, `(Decrypted)` once broken.

### Tests

- `MissionTest` takes a job file through the real decryption queue and hands it in; the old test swapped the item by hand, which hid the bug.


## [0.64.0] — 2026-10-17

Data Fortresses get a production line, a way to steal, and their first helpers.

### Added

- **Fortress Decryptors and Assemblers** (`ProgramKind::FortressDecryptor` / `FortressAssembler`, `FortressProduction`): a Decryptor turns the oldest sealed Encrypted Data Dump in its input area into a component each cycle, deterministically and keeping the file's origin; an Assembler is given a Design and a number of runs (0 for ever), spends the recipe from its input area (only components matching the line's filters, lowest grade first) and delivers the program to its output area. Input and output storage areas per type are set by the owner and administrators (`systems.decryptor_in_area_id` and so on). `production_cycle_minutes.decryptor` / `.assembler` are the stats; Boosters shorten both. First items: Beurling (Decryptor) and gasm-2.6.1 (Assembler), gated by Fortress Decryptor Crafting and Assembler Crafting.
- **Network Taps and Transactional Wiretaps** (`FortressNetworkTap` / `FortressWiretap`): each cycle they roll their strength against the grade of every other non-fortress node in range; a Tap steals an Encrypted Data Dump into the Decryptors' input area, a Wiretap pays creds by grade (`cyberspace.fortress.wiretap_pay`, raised by the `wiretap_yield` stat) into the fortress wallet. First items: Spigot and Tithe, gated by Network Tap Crafting and Wiretap Crafting; Transactional Skimming raises the yield.
- **Boosters** (Pacemaker): speed up every producer; stamped by their maker, never decay. Skill Booster Crafting.
- **Cloaks** (Veil): a fortress shows a chosen false face (grade, type, owner, ICE, IDS, tracer, OS) to scans; each scan file contests the Cloak once (`system_scans.cloak_broken`). Per-item base stealth through `programs.produces.strength` (blank uses the default).
- **Utility programs** (`ProgramKind::Utility` with `locates`, `walks`, `jumps`): Locator and Pathfinder become Utilities, and the new **Jumper** puts Jump buttons beside addresses in messages, scans, the Locator result, the routing chain and the Tasks & Missions screen (`<x-jump>`, gated by a running Jumper).
- **Bazaar** (Market) and **Nomad** (Relocation): the fortress's public market and automatic relocation, with relocation settings (interval presets, who is told), an owner bookmark and a message with the new address (`FortressRelocation`).
- **Maintenance skills** (Hardening, Streamlining) and **Parasitic Concealment** with the `leech_discovery` stat (per-grade chance table, 0.5% floor).
- **Item editor**: fields for cycle, Assembler reduction, Booster boost, Tap range and strength (shared with Cloak stealth), and a Leecher's resource and amount.

### Changed

- **Producer is split** into the Decryptor and Assembler kinds; the credit output, the free component job and the job queue (`fortress_jobs`) are gone. Vault files keep their origin when deposited and withdrawn.
- **Leechers** have their own take (`programs.leech_resource`, `leech_amount`); `storage_size` now means only what carrying one costs on a deck, so a storage Leecher no longer fills the deck by its take.
- **Kernel demand** percentages round away from the base to a tenth, so a small percent still moves it.
- **`programs.kind`** CHECK rewritten for the new kinds; older migrations patched for the removed Locator and Pathfinder kinds.

### Migrations

Eighteen, from `2026_10_16_140000_fortress_skills_off_the_market` to `2026_10_17_130000_leecher_takes_its_own_columns`. The ones that author game content skip themselves in tests and only create what is missing, so existing rows are left as they are.


## [0.63.2] — 2026-10-16

### Changed

- **Effects editor target picker**: the Target dropdown is now a `<details>` picker (`data-target-picker`) with a filter box and one fold-out group per stat tag. It sets the hidden `<select>`, which is still what posts, so `ModifierField` and validation are untouched; the picker is delegated in `ui-kit.js`, so cloned and duplicated rows work.


## [0.63.1] — 2026-10-16

A cap on data transports, a standing bar on the exchange screen, and draggable pins in the district editor.

### Added

- **Centred standing bar** on the exchange screen (`.meter--centred`): zero in the middle, -100 left, +100 right, a tick at each `standing.bands` floor. Grey in the Neutral band, red below zero, green above.
- **District editor map**: an existing district's form shows every location as a pin, draggable (or arrow keys) with the board editor's code, saved through `DistrictController::movePins()` (clamped 25–75, own district only). `<x-city-map>` takes an `edit` field selector.

### Changed

- **`MissionBoard`** offers at most one Data transport per board.


## [0.63.0] — 2026-10-16

Four new exchange jobs, Agitation, Extraction, Heist and Data transport, bigger boards, and fixes since 0.62.1.

### Added

- **`MissionAction::Agitate`**: make a run on a system, wake its ICE (the run is noticed) and leave, so the machine locks down. Recorded where it happens, in `RunStarter::end()`, after `Lockdown::afterRun()`: the machine shut, the run was detected, the ending was an egress or a completed run, and the system has ICE. Only systems with ICE are drawn as targets. Rewards are a placeholder (`missions.actions.agitate`).
- **`MissionAction::Extract`**: one system whose run is guaranteed to hold a job file for the runner. `MissionLedger::placeJobFile()` seeds a `system_contents` row (`for_character_id`, `mission_id`) when a run starts; only that character sees or pulls it (`SystemContent::visibleTo`, `Extractor::totalFileQb($address, $character)`), and a party deal still hands it to its owner. The file carries `mission_id` (`ExtractedFile::$missionId`) through decryption; the decrypted file is handed in at the desk (`handInExtract()`), spent, and the target ticked. Job files are cleared when the job ends or its target is replaced; `NodeStocker` ignores them when deciding whether a node is empty.
- **`MissionAction::Heist`**: raid one to three systems for creds. Creds taken off a named system are held on the job (`missions.proceeds`) instead of the wallet (`MissionLedger::holdProceeds()`, called from `Extractor::payCreds()` with the system id), ticking that target. Handing in pays the base reward plus `missions.heist_cut` (10%) of the proceeds; a failed job loses them. Fortresses are never named. Extraction jobs' card carries a hazard-stripe warning that decryption is needed.
- **`MissionAction::Transport`**: accepting hands over a *Sealed data container* (new item `sealed-data-container`, refused with `MissionRefusal::NoRoom` without deck space), names the drop by COORDINATES, and raises heat to `missions.transport_heat` (100, undone when the job ends; `missions.heat_added`). Standing on the target with the container shows *Upload sealed data* in the Interact box (`missions.upload`, `MissionLedger::deliver()`), which finishes and pays at once; a desk cannot complete it. A hunter's defeat (`HunterCombat::lose()` → `transportLost()`) destroys the container and fails the job. The container cannot be listed, sold or stored (`InventoryItem::isJobContainer()`). It weighs twice the exchange's grade in QB (`containerSize()`, warned about on the offer card when the deck is short); the drop is `missions.transport_steps` Chebyshev steps from the desk by the desk's grade (10-15 at grade 1 to 50-75 at grade 5); and the hunter is graded by the desk's grade over the runner's own heat (`Hunters::spawn()`).
- **`php artisan missions:retarget`**, and the Tasks/desk re-target pass.
- **City map**: a pulsing ember ◆ before your district's name and "◆ YOU ARE HERE" under the map.
- Strings `missions.actions.agitate` and `missions.action_hint.agitate`.

### Changed

- **Boards are larger**: 4 to 8 offers per desk (`missions.board.size`, was 2 to 4).

### Fixed

- `/tasks` and the exchange desk returned 500 on MySQL when re-targeting a mission (unsigned `x`/`y` underflow in the distance sort).

### Migrations

`2026_10_16_100000_agitate_missions`, `2026_10_16_110000_extraction_missions` and `2026_10_16_120000_heist_missions` (adds `missions.proceeds`) and `2026_10_16_130000_transport_missions` (adds `missions.heat_added`, inserts the container item): widen the `missions.action` CHECK (MySQL only) and add `system_contents.for_character_id` and `mission_id`.


## [0.62.2] — 2026-10-16

Fixes the 500 on the Tasks screen, and marks where you are on the city map.

### Fixed

- **`/tasks` and the exchange desk returned 500 on MySQL.** Re-targeting a lost mission target ordered candidates by `ABS(x - ?)`; `systems.x` and `y` are UNSIGNED, so a candidate left of the old position underflowed (BIGINT UNSIGNED out of range). The distance now casts both columns to SIGNED (`MissionLedger::retarget()`). SQLite never raised it, which is why the suite passed.

### Added

- **City map**: a pulsing ember ◆ before the name of the district you are in, and a "◆ YOU ARE HERE" line beneath the map.
- **`php artisan missions:retarget`**: replaces the lost targets of every accepted mission without waiting for someone to open a screen.

### Migrations

None.


## [0.62.1] — 2026-10-15

Run invitations through chat, a better Known systems list, the character sheet explaining what moves an attribute, and exchange jobs that survive a target going dark.

### Added

- **`run:<id>` in chat** invites to a co-op run, like *Invite people* in the planning screen (`Parties::inviteFromChat`, `RunPartyController::join`). The planning panel has a copy button for the id; chat renders it as "Run: <address>". Only the owner's text invites; General and public rooms never do. Joining is refused when you are not at the system, and the response carries the address so the page copies it for *Jump*.
- **Known systems**: a green ✓ beside every address with a full scan, and a hover card with name, address, owner, type and the scan results. The inspector is now a `popover="manual"` top-layer element, so it shows above dialogs.
- **Character sheet**: each attribute lists what moved it (source and signed amount, losses in the warning colour), and each dose in effect lists its own effects (`Dose::effectLines()`), so a Rewire's penalties are visible.

### Changed

- **A mission target that relocates or is burned is replaced rather than voiding the job** (`MissionLedger::retarget()`): a system of the same grade and type, nearest the old position (the desk's band when the old row is gone), never a fortress, an abandoned system or one the job already names. Deadline kept; a Mission Update message names old and new addresses. Done targets are untouched; with nothing to swap in the job voids as before. Checked when the Tasks screen or the desk loads, at hand-in and in the expiry sweep.

### Migrations

None.


## [0.62.0] — 2026-10-15

Co-operative runs, and Data Fortresses: a player's own node, built on borrowed resources.

### Added

- **Co-op runs.** `coop_slots` is read (`Character::coopSlots()`, Coordinated Strike and Advanced Coordination feed it). A runner forms a party at the door of a system from the run briefing (`run_parties`, `run_party_members`, `Parties` the only writer), invites by handle up to their slots, sets *Share credits* and *Share extraction* (even, 50% cut, full cut, `ShareMode`), and on Start every joined member goes in with the loadout they chose (`runs.run_party_id`). Creds, catalogue items and files are dealt by the chosen mode (`PartyShare`, largest remainder, owner first). A round resolves when everyone has chosen or 30 seconds pass (`PartyTurns`, resolved by whoever's request arrives first, absent runners by a default policy); detection and the ICE are shared, and the ICE strikes once a round at the runner with the highest priority (damage dealt plus how little stealth they have). The IDS rolls for every participant.
- **Data Fortresses**, all of `CYBERSPACE.md`'s *Data Fortresses* section (the list of what is built is there): founding by spending a Kernel item; Leechers (a run action, nightly discovery) whose live totals are the fortress's resources; hosted programs and the shutdown order, the Kernel implicit and last, offline fortresses deleted after 48 hours; a vault with storage areas, per-audience permissions, custom roles and an audit log; the raid (a random file every five seconds, by itself, with the wallet taken too) and its alarms; the Kernel burn with System Integrity and resistances, and the Frozen Kernel; crafted ICE, IDS and cloak carrying their maker's numbers, decaying while installed, and maintenance; the public market with tax and the fortress wallet; the cloak and its decloaker contest; relocation.
- **New program kinds** `leecher`, `producer`, `utility`, `market`, `cloak`, `relocation`, `fortress_ids`, `fortress_ice`, `kernel`; stats `defence_quality`, `decloak_strength`, `maintenance_boost`, `maintenance_efficiency`, `fortress_slots`; wallet reasons `fortress_market`, `fortress_sale`, `fortress_wallet`; `fortress:settle` every five minutes and `cyberspace:find-leeches` nightly.
- **Item card effects**: the stash card lists an item's effects (its modifiers), with names for the older damage stats.

### Changed

- Fractional loyalty, the player market lists a chosen quantity, drug tolerance tools: see 0.61.x.

### Also in 0.62.0

- **Cloak as a disguise**: a running Cloak program lets the owner present the fortress as another grade, type and owner to everyone without access (no decloaker).
- **Producers and boosters**: per six-hour cycle (the targetable stat `production_cycle_minutes`, 360, shortened by boosters) a producer gives a rolled range of creds, a count of component files, or production towards a program job; a five-job queue; the vault keeps any data item.
- The fortress map glyph is ◈.

### Migrations (fourteen, all new tables or columns)

`run_parties`, `run_parties_launch`, `party_rounds`, `data_fortresses_found`, `leechers`, `fortress_hosting`, `fortress_raid`, `fortress_kernel_burn`, `fortress_access`, `fortress_defences`, `fortress_roles_areas_log`, `fortress_market_cloak_relocation`, `fortress_cloak_disguise`, `fortress_production`. The program-kind and system-type CHECK constraints are widened (MySQL only).

### Not built

A Design's component filters are not applied by a producer. Every number is a placeholder in `cyberspace.fortress.*`; balance is the last step.


## [0.61.1] — 2026-10-15

Listing part of a pile on the player market, links to listings in chat and bios, and a tidier clinic panel in the location editor.

### Added

- **Listing links**: a `#` button on every player offer copies `market:<id>`; `Markup::listings()` turns that text, in a chat line or a bio, into a button (`data-listing`) that fetches `market.listing` (the offer's card, `market/_offer.blade.php`, or a "gone" line) into the `#listing` modal in the app layout.

### Fixed

- **Listing one of a pile listed all of it.** `OfferBook::list()` takes a `quantity` (default the whole row for callers that pass none); a smaller number splits the copies off into their own row, which is listed. The stash card's listing form has a quantity box when more than one is held (`MarketController::list()` validates it). `withdraw()` merges the copies back into the loose pile.
- **A listed row has no stack key** (`InventoryItem::stackKey()` returns null for a row with an offer), so a pickup never merges into something on sale.
- **Location editor**: the clinic services are laid out as a grid of label and number rows instead of stacked fields.


## [0.61.0] — 2026-10-15

Drugs and clinics can reset a drug family's tolerance and addiction, and tolerance wears off faster.

### Added

- **`drug_<family>_tolerance` use effects** (`UseEffect`, six cases): a consumable, or a drug that is also one, takes points off that family's stored tolerance through `DrugLedger::lowerTolerance()`, floored at nothing; books left at zero on both figures are deleted. A pure consumable at zero tolerance is refused as `NothingToDo`. The item editor's Consumable panel gets a field per family (`ItemRequest` now loops `UseEffect::cases()`).
- **Clinic treatments for habits**: twelve `ClinicService` cases (`drug_<family>_addiction|tolerance`) priced per point of the stored figure, rounded up, through `DrugLedger::lowerAddiction()` / `lowerTolerance()`. Lowering addiction reconciles the withdrawal at once. Migration `2026_10_15_130000_clinics_treat_drug_habits` widens `chk_clinic_services_service` and refreshes the column comment.

### Changed

- **Tolerance decays 3 points a day** (`drugs.decay.tolerance`, was 2). Addiction is unchanged at 1.



## [0.60.0] — 2026-10-15

Four new achievements, hunters are Bloodhounds, loyalty can be fractional, and the Known systems browser shows an exchange's grade. Data fortresses are designed in full (docs only).

### Added

- **Achievements 15–18**: *Vampire* (100 siphons installed, `Metric::SiphonsInstalled`, recorded by `Simulator::install()` on a new siphon backdoor), *Headhunter* (25,000 in bounties collected from other players, `Metric::BountiesClaimed`, nothing records it until PvP exists), *Wanted* (10,000 bounty on your head, `Metric::BountyOnHead`, `reach()`ed by `Bounties::tonight()`), *Indomitable* (50 Bloodhounds defeated, `Metric::BloodhoundsDefeated`, recorded by `HunterCombat::win()`).
- **Fractional loyalty** (migration `2026_10_15_120000_loyalty_is_fractional`): `entity_books.loyalty` and `entity_offers.loyalty_price` are `DECIMAL(12,3)`, and `entity_ledger.delta` widens from `DECIMAL(6,3)` (it could not hold a 1000-loyalty spend) to `DECIMAL(12,3)`. `PointsCast` and `Points::of()` take a `places` argument; a whole figure is still an `int`. The shelf editor accepts `0.2`. `missions.loyalty_reward` stays whole.

### Changed

- **Hunters are called Bloodhounds**: the hunt screen reads *A Bloodhound daemon intercepted you* and the wallet reason is *Bloodhound bounty*.
- **Known systems** shows an exchange's grade (read off `systems.grade`; an exchange cannot be scanned).
- `CYBERSPACE.md`: *Data Fortresses* designed in full (Leechers and borrowed resources, crafted and maintained defences with decay, storage areas and roles, the audit log, shutdown order, the Kernel as the online test, abandonment, the public market and its tax, raiding and the frozen Kernel).



## [0.59.0] — 2026-10-14

Hunters can be escaped and are not drawn back to back, block art is drawn properly in chat and bios, and a system can be scanned again.

### Added

- **Egress from a hunter** (`HunterCombat::tryEgress()`, `hunt.act` with `action=egress`, an *Egress* button beside *Fight*): the `egress` stat against `cyberspace.egress.difficulty[hunt grade]` plus the hunter's ICE level, through `CheckRoller`. A pass closes the hunt unpaid (`won` false, heat unchanged), stops run-only programs and jacks out through `Navigator::jackOut()`; a miss costs the exchange and the hunter swings. `CYBERSPACE_RULES.md` updated.
- **Hunter cooldown** (`cyberspace.heat.hunt.cooldown_minutes`, 60): `Hunters::rolls()` is false while a `hunts` row of that character started inside the window. No column.
- **Rescan**: a read that is complete and filed (`system_scans.filed_at`) can be scanned again; the press replaces the row with a blank one and begins a pass (`Probe::scan()`, `blankFile()`), and the new file is filed on completion like any other. A complete read that was never filed still refuses (`AlreadyKnown`). The map's button reads *Rescan* (`cyberspace.scan.rescan`).
- **IBM VGA 9x16** ("Plus" woff, CC BY-SA 4.0, `LICENSE-IBM-VGA-9x16.txt`) is `--font-mono`, with Plex Mono behind it. `.ascii` (and `.input--mono`, `.textarea--mono`, the chat log) set it at 16px with 16px rows, no tracking/kerning/ligatures, greyscale rendering on its own layer (Firefox draws LCD fringes between cells otherwise). Used by the bio (profile and editor) and chat.
- **Chat input is a textarea**: Enter sends, Shift+Enter is a new line, grows to eight rows. A multi-line message is drawn verbatim (no markdown) except the address button; a block of nothing but `- ` items is still a list.
- **`App\Support\Trace`** (`config/trace.php`, `TRACE_HANDLES`): per-character verbose log at `storage/logs/trace-YYYY-MM-DD.log` for runs, scans and hunts (state, every roll and die, the hunter's decisions).

### Changed

- **A hunter's arrival stops everything running except Scripts before the best icebreaker is started** (`ProgramRunner::stopAllButScripts()`), so a scanner holding the deck's memory cannot leave the fight with nothing to hit with.
- **Chat lines are 2000 characters** (`Chats::BODY`, migration `2026_10_14_180000_chat_message_wider`: `chat_messages.message` 500 to 2000) and are **stored exactly as typed**: `PlainText::clean(verbatim: true)` keeps newlines, tabs, blank lines and leading and trailing spaces (tags and invisible characters still go), and `/chat/say` is excluded from `TrimStrings`. The inbox compose screen is still 500.
- The `.mono`/`code` size is 1em and `.num`/`.readout__value` no longer track negatively, so the bitmap font stays on its grid.


## [0.58.2] — 2026-10-14

A hunter's icebreaker is no longer starved by whatever else is running, and one character's runs, scans and fights can be logged in full.

### Changed

- **A hunter's arrival stops everything running except Scripts before the icebreaker is chosen** (`ProgramRunner::stopAllButScripts()`, called first by `Hunters::startBestIcebreaker()`). A scanner, locator or daemon holding the deck's qFLOPS or RAM could leave the best icebreaker "too big", and the fight opened with nothing running ("Nothing is running that can touch it."). What was stopped is not restarted afterwards; a stopped Decryptor settles its queue as for any stop. `CYBERSPACE_RULES.md` updated.

### Added

- **Trace log** (`App\Support\Trace`, `config/trace.php`, the `trace` channel): for the handles in `TRACE_HANDLES` (comma separated, empty is off), one file a day at `storage/logs/trace-YYYY-MM-DD.log` (`TRACE_DAYS`, 30). Wraps `RunStarter::start/end`, `Simulator::take`, `Probe::scan/settle`, `Hunters::spawn`, `HunterCombat::fight`: state in (vitals, resolved stats with their steps, every modifier, deck totals and fittings, running programs, owned icebreakers) and state out with the result, every `CheckRoller` roll, every integer die with its range and the function that asked, and the hunter's decisions (spawn chance, programs stopped, each icebreaker attempt and refusal). Never throws and writes nothing for anyone not named. Separate from `Trail`.


## [0.58.1] — 2026-10-14

Characters get a public profile, and the run briefing says what a Placement job is asking for.

### Added

- **Public profile** (`/characters/{handle}`, `ProfileController`, `profile.show`): portrait (a supporter's own picture when they have one), handle, Supporter and Tester tags, the bio as typed (monospace, no reflow, printed escaped), and the standings of that character with every interactable entity (band word and figure, zero hidden). *Start a chat* (`POST /chat/with/{handle}`, `chat.direct`: finds or makes the direct line and goes to it) and *Send a message* (the inbox compose screen with the To field filled in); neither is shown on one's own profile. Shows nothing else about a character. `chat.direct` and `characters.show` are in every lock's allow-list.
- **A handle in chat links to the profile**, in the page and in pushed lines (`chat/_line.blade.php`).

### Changed

- **The siphon button on the run briefing** reads *Place backdoor* and is `btn--go` while an accepted Placement job (`MissionAction::Plant`) still has this system open (`MissionLedger::hasOpenAgainst()`, the read-only twin of `recordAgainst()`). The press itself is unchanged.



## [0.58.0] — 2026-10-14

Chat arrives: a General channel, rooms and direct lines on a page of its own, pushed live over a websocket; achievements raise standing; and the grid's edge is hatched.

### Added

- **Chat** (`/chat`, `ChatController`, `App\Chat\Chats` the only writer, `App\Chat\Markup` the renderer): General for every character, named rooms (public or private) and direct lines. Lines are stored as plain text (`PlainText`) and drawn escaped with bold, italic, strikethrough, `- ` lists and `addr:XXXXXXXX` as a copy button. A character's avatar sits beside each line (a supporter's own picture when they have one), the timestamp is on hover, and the tab row carries *General (x)* active players (last 30 minutes, cached a minute) and an ember unread count per tab; the rail's *Chat* item carries the total. The page is never locked (`chat.*` and `broadcasting.auth` are in every lock's allow-list), and its refresh poll does not count as activity.
- **Commands**: `/msg handle words` (one direct line per pair, found from either side), `/invite handle` (an inbox message with *Join* and *No thanks*; joining writes an italic "X has joined the chat"), `/leave` (an italic "X has left the chat."; an empty room goes), `/ignore` and `/unignore` (their lines, direct lines, unread counts and invitations are hidden from you). A private room of exactly two reads as the other person.
- **Websockets** (Laravel Reverb, `ChatLine` event, private channels `chat.general` and `chat-user.{characterId}` in `routes/channels.php`): a line appears on every open page at once, drawn by the server (`chat/_line.blade.php`). Without a socket the page polls every five seconds, so a down server never costs a message. Dev gets a `reverb` compose service on `:8085`; production needs the process and an Apache `/app` proxy (see *Chat and websockets* in `CLAUDE.md`).
- **Achievements are worth +1 standing with every entity** that keeps books (`StandingReason::Achievement`, `standing.reasons.achievement`), on the ledger as "You are really getting your name out there!". Achievements unlocked before this release are not paid back.

### Changed

- **Beyond the edge of the grid** is a 45° hazard stripe in two greys (`.cell--void`) instead of a hole; the tile is half a cell, so the stripes run unbroken across cells.
- `composer.lock`: installing Reverb moved `guzzlehttp/guzzle` 8 → 7 (with `psr7` and `promises`), `laravel/framework` to 13.36 and a number of Symfony packages. Nothing in the app calls Guzzle directly. The Dockerfile gains `pcntl`.

### Migrations

- `2026_10_14_160000_chat`: `chats` (General seeded), `chat_messages`, `chat_members`.
- `2026_10_14_170000_chat_commands`: `chat_messages.is_event`, `chat_invites`, `chat_ignores`.



## [0.57.0] — 2026-10-14

Alpha testers are thanked, the quest runner arrives, Pathfinders take coordinates and move at their own speed, and a few screens say why they refused.

### Added

- **Alpha testers** (`User::isTester()`: a spent beta key is the fact, no column): a *Tester* tag beside the Supporter badge, and a one-off gift of 5,000 ¤ (`Reason::AlphaGift`, "A gift for alpha testing dedlok!"). `App\Beta\AlphaGift` pays once per character (the ledger line is the once) at character creation and, for everybody already registered, from `php artisan testers:gift` — run it once after deploying, as `dedlok`.
- **The quest runner** (`php artisan quests:run`, `App\Quests\QuestRunner`, `cadence.quest_runner`, every 30 minutes): the timed steps of quests. The first task sends the Joint Signals Command legal notice once to every character with `njord_jsc_files_read` (marked by the flag `njord_jsc_notice_sent`) and writes a line under the `ghost_process` journal note.
- **Tasks & Missions badge** on the rail: journal lines and accepted jobs newer than `characters.tasks_seen_at` (`Journal::unseenCount()`); opening the screen clears it (`Journal::markSeen()`). Migration `2026_10_14_130000_tasks_seen_at` stamps every existing character as seen.
- **Pathfinders take coordinates** (`5,102`, `FindQuery::$position`, `FindResult::$target`) and **move at their own pace**: `programs.traverse_seconds` (migration `2026_10_14_150000_pathfinder_traversal_speed`; blank is 10, the existing Pathfinder is set to 8, edited in the item editor).
- **Supporters page: a Testers box** listing accounts that spent a beta key, by the name they gave for the credits, else their handle.

- **Heat in the topbar**: a warning glyph coloured against the hunters' threshold (`cyberspace.heat.hunt.floor_below`, 5): green below it, brass at or above, crit at twice it; the number is the tooltip.

### Changed

- **Extraction takes data only.** `Extractor` skips any item that is not data (a stock rule may still place one) and the run log names what came out with amounts, for example "450 creds and 3 × Ghost script" (`ExtractionResult::$taken`).
- **A listed program cannot be run** (`ProcessRefusal::Listed`, asked in `ProgramRunner::refusalFor()`, and the briefing's loadout leaves listed rows out): the promise `scopeLoose()` made was not kept by `start()`.
- **The run, hunt, fight and encounter locks say why** when they turn away something pressed (a silent redirect read as a dead button).
- **`notifications.body` is 1000 wide** for the game's own messages (migration `2026_10_14_120000_notification_body_wider`); a player's own words stay at 500.
- The quest runner and the Pathfinder changes are covered by `QuestRunnerTest`, `PathfinderCoordinatesTest`, `AlphaGiftTest` and `LockedPressTest`.


## [0.56.1] — 2026-10-14

Shops share the market's look, the city map opens district cards, and a few screens say more.

### Changed

- **City shops and Exchange loyalty stores draw the market's cards** (`city/_ware.blade.php`, `exchange/_offer.blade.php`): picture, owned/injected corner tick, `<x-market-card-head>` (an imprint's header opens its skill detail), spec line and price, with search and kind accordions from the shared `market/_grouped.blade.php`. `Controller::shopContext()` supplies the owned items, injected skills and top level.
- **The City page is the map alone.** The Districts box is gone; hovering a district floats a card and a click opens it as a modal (`city/_district-card.blade.php`, the inspector): description, the locations the character knows, and Walk / Pod / Explore buttons from the same `Traveller::quote()` / `Explorer::duration()` calls. `<x-city-map>` takes an `inspect` key. The district you stand in has its hairline pulse slowly (`here-pulse`) and its card carries the "You are here" tag.
- **Stash slots show a picture in place of the glyph** (`Image::slot()`: the item's own, then its type's default, never the root `items/_default`), with the glyph over it in a 1px hard shadow. A scan file's slot shows its address, and the detail card carries the address as the copy button.

### Added

- **Cyberspace: View range** chip after the explored count, with a hover/click card of the working (base, every source and operation, total) and the 9 x 9 shape drawn. `CyberspaceController` keeps the `view_range` modifiers in pieces and builds the shape from the same resolve.


## [0.56.0] — 2026-10-13

### Changed
- Hit points and Synaptic Integrity are carried to two decimal places (`characters.current_hitpoints` and `current_synaptic_integrity` are `DECIMAL(8,2)`; migration `2026_10_13_130000_hit_points_to_hundredths`). Maximums, both regeneration rates and every damage stat round to a hundredth instead of a whole point, so a small percentage resistance or a fraction of a point an hour is no longer rounded away.
- `App\Support\Points` is the one rounding and `App\Casts\PointsCast` the column cast; a whole figure stays an `int`. `Character::takeDamage()`/`recover()`, `DamageResolver`, the fight engine's `Combatant`, `Mitigation`, `HitDamage`, `Clinic`, `UseEffect` and `characters:regenerate` carry fractions. A hit is no longer rounded to whole points.
- A clinic bills a fraction of a point rounded up to whole creds. `characters:regenerate` works in hundredths so an hourly rate is exact.
- The character sheet's bounty and heat boxes are the same height; the empty bounty line reads "No bounty on your head."


## [0.55.1] — 2026-10-13

A heat box on the character sheet.

### Added

- **Heat box beside the Bounty box** (`CharacterController::heat()`, `character/index.blade.php`): the figure, a bar on a scale of 100 (green under 24, brass from 24, crit from 62, the bands the bounty roll and hunters read), what cools it a day (the resolved `heat_decay`), and, on hover or focus, when it will be 0. The night it reaches zero is counted at the nightly rounding from the next run of `characters:cool`.
- **`cadence.heat_cool`** (`10 5 * * *`): the cooling job's time now lives with the other counted-down clocks, and `routes/console.php` reads it, so the sheet and the scheduler cannot disagree.
- `TODO.md`: a note for a "Utility" market category (Locators, Pathfinders).


## [0.55.0] — 2026-10-13

Critical hits in both realms, a siphon payout table by grade, two new attribute skills sold for loyalty, and a bigger starting purse.

### Added

- **Critical hits** (`critical_chance_physical`, `critical_chance_cyberspace` in `config/stats.php`, base 2 each in `stats.bases`, percent points 0 to 100 to a tenth): a hit a player lands is critical with that chance and does `combat.critical.multiplier` / `cyberspace.critical.multiplier` (5) times the damage. Both stats are named under `character.names`, so skills, scripts, implants and drugs can target them.
  - **Physical:** `FightEngine::attack()` rolls one extra die per shot, only for a side with a `Combatant::$critChance` (the player's, from `CombatantBuilder::forCharacter()`), and not for a near miss. Multiplied before resistance. Logged as `combat.fight.log.shot_critical`.
  - **Cyberspace:** `App\Cyberspace\CriticalHit` is rolled by `Simulator::attack()` and `HunterCombat::fight()` after a hit that does damage; the die is 1 to 1000 against the resolved stat in tenths.
- **Hyperfocus** (+1 Focus a level) and **Microexpressions** (+1 Social a level), migration `2026_10_13_100000_the_focus_and_social_skills`: rank 16, base cost 10,000, off the market, imprints made through `Imprint::ensureFor()`. Like the seven existing attribute skills.
- **The nine attribute skills are on every interactable exchange's loyalty shelf** (migration `2026_10_13_120000_the_attribute_skills_for_loyalty`): 35 loyalty each, standing 1.0, desk grades 1 to 5. A row already present is kept.
- **The playlist modal links to the playlist at YouTube**, and the embed is told to shuffle once the player is up (`data-embed-shuffle`, `enablejsapi=1`, a `setShuffle` message from `ui-kit.js`).

### Changed

- **Siphon payouts are a table by grade** (`cyberspace.backdoors.siphon.by_grade`, replacing `per_grade`): 45, 65, 95, 125, 160, 190, 220, 260, 290. Still resolved through `siphon_yield`.
- **The Deck Jockey starts with 3,500 creds** instead of 2,000 (migration `2026_10_13_110000_deck_jockeys_start_with_3500`, which only changes the row while it is still 2,000).

### Tests

- `tests/TestCase.php` sets both critical chances to 0, so pinned dice orders through a real character are unchanged; `CriticalHitTest` sets them back.
- `LoyaltyStoreTest` clears its entity's offers in setup, since migrations now stock the interactable exchanges.


## [0.54.0] — 2026-10-13

A new achievement, The Knowledge, for exploring and discovering ten locations.

### Added

- **Achievement 14, The Knowledge** (`config/achievements.php`): reached at ten locations discovered, not secret. Counted by the new `locations_discovered` metric (`Metric::LocationsDiscovered`), recorded in `Discovery::unlock()` only when a location is newly found — the one writer of `character_locations`, so every discovery path counts and a repeat or a non-explorable place adds nothing.
- **Migration `2026_10_12_230000_the_knowledge_counts_what_was_already_found`** backfills each account's `achievement_progress` from the locations its character has already found. The award itself lands on the player's next metric movement, as with every retroactive achievement.


## [0.53.2] — 2026-10-12

Item pictures are filed the way the market is, and enlarging one never shows somebody else's picture.

### Changed

- **Item picture folders follow the market** (`Item::imageType()`, `Item::imageTypes()`, `public/images/items/README.md`): a module sits under its variant (`ram/opto`, `cpu/socket_k`), weapons under `weapons/<class>`, ammunition under `ammunition/<calibre>` (slugged, made as met), and drugs and implants have folders of their own. The other types are unchanged. A default in a parent folder still stands in for the sections below it.
- **The shipped item pictures are 640×360**, the originals kept beside them as `_full`.

### Fixed

- **Clicking a picture opened the kind's empty default.** `Image::full()` fell back to `items/_default_full.jpg` for a thing that had a card picture and no full one. A thing with its own card picture and no full version now gets no enlarge dialog, and a type default's full version is looked for only in the folder its card came from.


## [0.53.1] — 2026-10-12

Market listings say what a thing costs to hold and run, and the shelf is split into sections.

### Added

- **Spec lines on every listing** (`<x-program-spec>`, `Item::programRequirements()`, `Item::kindLine()`): software shows qFLOPS, QB, storage, background or run-only and lifetime, plus what a Decryptor breaks and the one-at-a-time rules of Locators and Pathfinders (an icebreaker lists none, being run-only by nature). A deck module shows its defining figure (QB, qFLOPS, …), power draw and TU (`DeckModule::loadLabels()`, also used by `summary()`), and its variant under the title ("RAM · Opto-electrical"). On the market shelf and offers, the exchange store and city shops.
- **Market sections** (`Item::marketCategory()`, `Item::marketSection()`, `market/_cards.blade.php`): Ammunition (a section per calibre), Weapons (a section per weapon class), Drugs and Implants leave "Physical"; RAM is split by stick type and CPUs by socket. Sections nest as accordions and the search and sold-out handling in `ui-kit.js` work through them.
- **Hover card on the deck board**: a filled socket floats the module's card (`data-inspect` with `data-inspect-hover`); a click still opens the socket.


## [0.53.0] — 2026-10-12

An encounter can set its own chance, and the encounter editor stops losing work silently.

### Added

- **An encounter may set its own chance** (`encounters.chance`, percent, 0.01 to 100; migration `2026_10_12_220000_encounters_have_their_own_chance`). NULL is the door's default and its weighted draw. A number is the odds that THIS encounter opens at each opening of its door, rolled on its own in id order before the door's roll (`Encounters::rollOwn()`), adjusted by the resolved `encounter_chance` stat, and left out of the door's draw. The editor has the field, with the doors' defaults in its hint; it is refused on `place` and `spot`.

### Fixed

- **The encounter editor lost the tail of a large form without saying so.** A mid-sized encounter is about a thousand form fields, and PHP drops every variable past `max_input_vars` silently, so what came last (a journal outcome added to it, later screens) was not saved and looked as if it had been. `EncounterRequest` now refuses a form that reached the limit, with a message naming it; the journal's three inputs are only submitted for a journal outcome (disabled otherwise, `ui-kit.js`); the dev limit is raised to 4000. Production needs `php_admin_value[max_input_vars]` raised in its FPM pool.
- **An outcome with text in it and no kind was skipped as if it were a blank padding row.** It is refused now, with the screen named.

### Migrations

- `2026_10_12_220000_encounters_have_their_own_chance`: `encounters.chance` and its CHECK.


## [0.52.0] — 2026-10-12

A journal on the Tasks & Missions screen that encounters write to, and a browser for the systems you know.

### Added

- **The journal** (`journal_notes`, `journal_entries`, `App\Journal\Journal`): a level 0 note is a heading with a slug, and a line is a row under one with a slug of its own, per character. Listed on Tasks & Missions, newest first at both levels; a completed note is not shown and a completed line is struck through. Two outcome kinds write it: `journal` (a note made with its heading if it is new, a line under it, or both; idempotent by slug) and `journal_done` (marks a note or line done by slug). `encounter_outcomes` gains `text`, `entry_slug` and `entry_text`, and the editor's outcome row gains the three inputs.
- **Browse known systems** (`GET cyberspace/systems`, `cyberspace/_systems.blade.php`): a modal in the Jump box listing every system you have seen that is still at the address and position you saw it and is not in lockdown, sorted by owner (the unowned last) then name, with owner, type and grade filters that combine. Grade is only what a live scan found out. A filter change refetches the fragment (`form[data-fragment-filter]`, `ui-kit.js`). Capped at 200 rows.

### Changed

- **The Jump and Routing chain boxes are accordions**, closed by default like Thresholds.
- **The run_end refusal in the encounter editor** says which rule it broke (the encounter does not start in a run, or the screen is not an ending).

### Migrations

- `2026_10_12_210000_the_journal`: the two journal tables and the three outcome columns.


## [0.51.0] — 2026-10-12

Encounters come to cyberspace, view range becomes a shape, and a completed run no longer throws the player off the grid.

### Added

- **Cyberspace encounters** (`ENCOUNTERS_GRID.md`). Four new doors on `encounters.starts_on`, the realm being the door's (`EncounterStart::isGrid()`):
  - `grid_step`: an arrival by a step, a jump or a bookmark (never the jack-in); `Navigator::arrive()` rolls it after the hunter, in its own write on the locked character.
  - `system`: the position has a system; filters `system_type`, `grade_min`/`grade_max`, `owner_entity_id`.
  - `run`: after a turn of a live run, from `RunController::resolve()`; filters grade range and `run_purpose`. Every ending carries `encounter_nodes.run_end` (`kill_run` or `continue_run`), required on a run encounter and refused elsewhere. `kill_run` ends the run through `RunStarter::end()` as the new `RunEnding::Encounter`.
  - `spot`: a button in the grid screen's Interact box, not rolled, pinned to a system by `spot_system_id` (a plain id, so it follows its machine and goes dormant when it is burned), with a per-character `cooldown_hours` held in `encounter_cooldowns`. `POST cyberspace/spot/{encounter}`.
  - Base chances in `cyberspace.encounter.*_chance`. The run lock stands down for an open encounter.
- **Prerequisites** (`encounter_prerequisites`, `PrerequisiteKind`, `PrerequisiteJudge`): flag, items, scan files, creds, skill level, standing, district; each negatable; all must hold, none being no condition; held and never spent. Judged where the pool is built. An editor panel writes them.
- **The player's clocks stop while an encounter is open on the grid** (`App\Characters\EncounterPause`): the regeneration, withdrawal, scan, decryption and crafting sweeps skip a held character, scripts and doses are read as at the moment it opened, and every stored moment moves forward by its length when it closes.
- **Grid outcome kinds** through the writers that own them: `trace` (points, clamped 0 to 99 so it never lands), `wake_ice`, `detected` (the owner minds) and `grid_move` (`ref` = `x,y`). The run kinds are refused outside a run encounter.
- **`loa_intro_encounter`**: the first authored one, by migration, with placeholder text. Once per character; both endings set `loa_introduced`.
- **Cyberspace trail** (`App\Cyberspace\Trail`, `storage/logs/cyberspace.log`): the deck, stash and last run's state and the callers of every `Navigator::jackOut()` and run end. On except in production (`CYBERSPACE_TRAIL`).

### Changed

- **View range is a level from 0 to 9, and each level is a shape** (`cyberspace.view_shapes`, `App\Cyberspace\ViewShape`). The level is the resolved stat `view_range` (base `cyberspace.view_range` = 2). The sweep explores exactly the shape and forgets or re-sees only what stands inside it; the map's lit cells follow it. A bare character now sees 11 cells rather than a 5 x 5 square. Network Topology adds one level per level trained.
- **Standing and loyalty a job pays are doubled**, at every grade (`missions.grades`), and **grade 1 is then cut by about 30%** (standing 0.014 to 0.042, loyalty 1 to 3). Penalties are unchanged.

### Fixed

- **A completed run ejected the player.** `RunController::resolve()` sent every ending but an egress through `resolveEnding()`, which always jacks out, so egressing after an extraction, a backdoor or a burn threw the player off the grid. Only a bad ending (`RunEnding::isBad()`) ejects.

### Migrations

- `2026_10_12_150000_encounters_can_start_on_the_grid`: `starts_on` gains `grid_step`.
- `2026_10_12_160000_the_loa_introduction`: authors the first encounter.
- `2026_10_12_170000_network_topology_widens_sight`: +1 `view_range` per level on Network Topology.
- `2026_10_12_180000_encounter_prerequisites`: the prerequisites table.
- `2026_10_12_190000_encounters_on_systems_and_in_runs`: the system and run doors' filters, `run_end` and `character_encounters.run_id`.
- `2026_10_12_200000_encounter_spots`: the spot door's pin, cooldown and `encounter_cooldowns`.


## [0.50.0] — 2026-10-12

Skills leave the market's shelf and are sold by shops, and a mislabelled operating system is put right.

### Added

- **`city:stock-skills [location]`** (`StockSkillsShop`): lists the imprint of every skill with `on_market` in a shop, unlimited and at list price. It only adds, puts any skill listing that has a price of its own back to list price, and hands the place's clinic, buy list and surgery back unchanged through `FeatureEditor`. Run nightly at 04:20 for `city.skills_shop` (`skillsrus`); `--dry-run` and `--quiet-when-idle`.
- **Skills R Us** is in the City (authored in the live admin); this is what stocks it.

### Changed

- **Skill imprints are never on the market's own shelf** (`Item::scopeRestockable()`, `Purchaser`, `MarketController`). Players may still sell them to one another. Loot and loyalty stores are unchanged, and `on_market` on an imprint still decides loot and what a shop lists.
- **An imprint is named for its skill** ("Hacking", no "Eidetic Imprint:" prefix) and its kind reads *Eidetic Imprints* rather than *Data* (`items.imprint.name`, `items.kinds.imprint`).
- **The admin skills list has a *Price* column**, showing the imprint's price (what a shop charges). The skill's *on market* field is labelled *Imprint for sale*.
- **Every slug may use hyphens and underscores** (district, location, item, skill, encounter). A once-only encounter writes any hyphen of its slug as an underscore in its seen flag.
- **Close Quarter Combat 500, Neural Sheathing 2,000, Efficient Cooling 1,000**, on the skill and its imprint.

### Fixed

- **Kernel Exploitation is not an operating system.** It was filed under *Operating Systems*, which the grid seeder reads as the OS ladder, so 491 systems ran it and a scan read "Operating System: Kernel Exploitation". It is under *Intrusion* now, and systems and scans that carried it get the OS their grade would have had.
- **The run briefing** marks programs that will not fit beside what is already running, and disables the start buttons while the ticked selection is over the deck.

### Migrations

- `2026_10_12_120000_skills_leave_the_market`: clears skill imprints from the market's shelf rows.
- `2026_10_12_130000_kernel_exploitation_is_not_an_operating_system`: moves the skill to Intrusion and reassigns the systems and scans that carried it.
- `2026_10_12_140000_three_skills_get_a_price`: sets the three prices above.


## [0.49.2] — 2026-10-12

Scripts can be started from the run briefing, and the deck's bars show what a loadout will cost before you go in.

### Added

- **A *Scripts* box on the run briefing** (`RunController::setup()`, `run/setup.blade.php`): every loose Script held, with its effects, qFLOPS and QB cost and its lifetime, each with a checkbox (`scripts[]`). Ticked scripts are started by `RunStarter::start()` after the loadout and before the arrival roll. Only a Script is accepted through the field; any other row id is ignored.
- **The page does the fit sum** (`ui-kit.js`, `data-run-budget`): what the deck has free less everything ticked (icebreaker, run-only programs, scripts), in tenths. A script that would not fit is disabled and marked *Unable: Not enough CPU / RAM*; a ticked one that a later tick pushes over is marked too, and stays enabled to be unticked. The server's checks remain the guard.
- **The deck's compute and memory bars move with the selection** on the briefing (`data-vital`, `data-format` in `deck-vitals.blade.php`).

### Changed

- **A program that will not start rolls the run's start back** (`App\Programs\ProcessRefused`) instead of stopping what had already started. Stopping a Script destroys it, so a refused run must not spend one.
- **Deck bars are brass within a tenth of their limit and ember at it or over** (compute, memory, storage, power draw; `deck-vitals.blade.php`). Heat keeps its own warning and limit marks. Previously only *over* turned a bar red.

- **Every slug may use hyphens and underscores** (district, location, item, skill, encounter; NPCs already did). A once-only encounter's seen flag writes any hyphen as an underscore (`Encounter::seenFlag()`), since a flag may not carry one.
- **The admin skills list has a *Price* column** (`skills.base_cost`, what the imprint sells for).

### Removed

- **The `/help` page** (`HelpController`, `help/index.blade.php`, `strings.help`, `HelpPageTest`, the front-page link): the help is to be written by hand. `HELP.md` is untouched.


## [0.49.1] — 2026-10-11

Empty sockets show what they take, files stop wearing their own colours, and a job's systems are ticked off as you do them.

### Added

- **A green tick beside a mission's system** once its part is done (`mission-card.blade.php`, `.mark--ok`): the act is recorded for a burn or plant, and for a survey the scan file it needs is in hand. Offers show none.
- **Prerequisites for encounters, designed** (`CITY.md`, *Prerequisites*; `ENCOUNTERS_GRID.md`): a list of conditions that must all hold before an encounter is drawn, empty by default. Nothing built.

### Changed

- **An empty deck socket shows its kind's glyph**, faint (`.slot--ghost`, `Item::GLYPHS` now public), instead of the diamond. IO ports keep the diamond.
- **The stash loads the one-to-one rows `Item::glyph()` asks about** (`program`, `drug`, `consumable`, `armour`, `weapon`, `ammunition`, on items and on what is in containers), one query each rather than one per item.

### Removed

- **File colours.** `.f-scan`, `.f-sealed`, `.f-component` and `InventoryItem::tierClass()` are gone; a file wears its item's tier again. The ▧ ⧈ ⬢ glyphs stay.


## [0.49.0] — 2026-10-10

A sound switch and a playlist in the bar, a glyph and a colour for every kind of thing in the stash, and scan passes that no longer give the grade away.

### Added

- **Sound toggle** in the topbar (`users.sound_enabled`, migration `2026_10_10_100000_a_user_may_mute_the_interface`, default on). `SoundController` saves it (`sound.update`, 204, named in every lock's allow-list so a player under a run, hunt, fight, encounter or the floor can still mute); `ui-kit.js` swaps the glyph and `soundOn()` gates the inbox chime. The glyph is `◁))` / `◁×`.
- **Playlist modal** (♪) headed *Official dedlok playlist*, embedding the YouTube playlist through `youtube-nocookie.com`. The iframe has no `src` until the dialog opens and loses it on close (`data-embed`), so nothing is requested unasked and closing stops the music. `.embed` is the kit's 16:9 slot, with a gallery demo.
- **`ENCOUNTERS_GRID.md`**: the design for encounters in cyberspace (realm, four doors, the clock stop, `kill_run` / `continue_run`, avatars). Nothing built.

### Changed

- **Every scan pass takes one minute at every grade** (`cyberspace.scan.minutes`, was 1 to 16 by grade). The countdown on screen told a player the grade before the read had; the grade now shows as a lower chance per roll, so a hard system takes more passes. Skill and software still cut it (`scan_duration`).
- **Glyphs by kind** (`Item::GLYPHS`, `InventoryItem::glyph()`): one per program kind, hardware kind, implant, drug, consumable, armour, weapon, ammunition and container, and ▧ / ⧈ / ⬢ for an encrypted dump, a memory dump and every component.
- **Files are coloured by what they are** (`.f-scan` cyan, `.f-sealed` violet, `.f-component` moss) on the slot's glyph and the item card, through `InventoryItem::tierClass()`. Declared after `.slot`, which sets its own `--tier`.
- **The run log names what was pulled**: "You pull one Encrypted Data Dump out" rather than "a file" (`run.log.took.files`).


## [0.48.0] — 2026-10-09

The grid is four times the size, and the portal and every exchange move with it.

### Changed

- **The grid is 128 × 128** (`cyberspace.size`, was 64). Migration `2026_10_09_330000_the_grid_is_128`: the portal, derived as the middle, moves from (32,32) to (64,64);
  every exchange is moved to rings round it (the placer's own rule, grade 1 innermost); each player's sighting of a moved system, saved waypoints on its old position and scan files follow it
  (a sighting or waypoint already on the destination gives way to the newer); then `GridSeeder` tops the world up to its 70% occupancy over the new area, at the same ratios.
  New systems get their contents at the next nightly `cyberspace:restock`. Not reversible. Does nothing on an empty grid.
- **Locators and Pathfinders take an address** as a term (`FindQuery::$address`): an exact match on a system you have met, in any case.

### Added

- **Tasks & Missions**: *Recently worked for:* under Missions lists each exchange you accepted a job from in the last seven days, once each, with its grade and address.


## [0.47.0] — 2026-10-09

Locators, a help page, richer survey pay, and an item editor that folds.

### Added

- **Locator and Pathfinder** (`ProgramKind::Locator` / `::Pathfinder`, migrations `..300000` and `..310000` widen `chk_programs_kind`): background programs that find the nearest system you have met
  (`App\Cyberspace\Finder`). The map screen's *Locator* box takes `exchange, lockjaw, grade >2`, answers with the nearest match as one of eight arrows and a distance, and names the program
  that is running. It reads `system_contacts` only, a grade only from a live scan, and the portal is always findable. One locator at a time (`ProcessRefusal::OneLocator`).
- **Autotraverse** (Pathfinder only): the page takes one `Navigator::step()` toward the target every ten seconds (`cyberspace.traverse`), behind a brass overlay (the dialog's backdrop) with steps left and a bar.
  Cancel stops where you stand.
- **The two items**, Azimuth and lOCO_mOTiVE 0.9.1, by `2026_10_09_320000_the_locators` (skipped when the slug already exists).
- **A help page** at `/help`: one page of accordions with anchors per group and topic, and a glossary. Linked from the front page.
- **Crafting**: the deck's "You do not have the skill to build this." opens a *Skills missing* dialog (released in 0.46.1).

### Changed

- **Survey missions pay more creds at the low grades**: ×3 at grade 1, ×2.5, ×2, ×1.5, ×1 at grade 5 (`missions.actions.scan.creds` may now be a table by grade). Standing and loyalty are unchanged; jobs already offered keep their pay.
- **The item editor's boxes are accordions, closed by default**; all open on validation errors, and Identity is open on the creator.


## [0.46.1] — 2026-10-09

### Changed

- **The deck's Crafting box**: "You do not have the skill to build this." now opens a *Skills missing* dialog listing each skill the build still needs and the level wanted
  (`Crafting::missingSkills()`, carried on `CraftPlan`; `blockedBy()` asks the same list, so the message and the dialog cannot disagree).


## [0.46.0] — 2026-10-09

An admin Characters screen for grants, achievements that show how rare they are, and fixes.

### Added

- **Admin Characters screen** (`admin/characters`): *Give creds* (1 to 1,000,000, through `Wallet::move()` as the new `Reason::Granted`, so the ledger has the line),
  *Full HP* and *Full SI* (`Character::recover()` to the resolved maximum).
- **Achievements show the share of players who have each** (two decimals, `Achievements::percentages()`; a player is an account with a character).
- **The unlock message** is titled "Achievement unlocked!" and reads "You unlocked the achievement <name>!", the word *achievement* linking to the Achievements tab
  (`details['link']`, drawn by the inbox).

### Changed

- **An achievement's name and description live in `config/achievements.php`** with its metric and threshold, and are gone from `config/strings.php`.
  The thirteen names are written (The Two Comma Club to Panopticon).

### Fixed

- The item editor's checkboxes (Design copy, Fight only, Runs in the background, Multithreaded, each decryptor encryption) drew no box.
- The deck's Crafting panel printed its own open/closed condition as text.

### Operations

- **Deploying pays ACiD 3000 creds** (migration `2026_10_09_100000_acid_is_given_3000_creds`, once, through the wallet). Do not also give them by hand.

### Migrations

- `2026_10_09_100000_acid_is_given_3000_creds`: data only, no schema change.


## [0.45.0] — 2026-10-08

Code Crafting, Scripts, components from decryption, decimal storage and compute, and Supporters with Ko-fi.

### Added

- **Code Crafting** (`CRAFTING.md`, `App\Crafting`): designs (an item with a recipe: a Design Copy is spent by a craft, a Design Original is kept), builds in
  `crafting_slots` slots (base 1) that run only while the equipped deck is ready, components spent when a build starts (lowest qualifying grade first,
  asking before a higher one), the product delivered when it fits. Skills: Code Crafting, Script Crafting, Icebreaker / Analyser / Decryptor Crafting
  (category *Code Crafting*). The deck screen has a *Crafting* panel, Current processes a bar, the item editor a *Design* panel, `crafting:settle` runs
  every minute. The first Script, DUMMY_TEMPORARY_PROGRAM (+10% stealth for ten minutes), and its two designs (placeholders to rename and tune).
- **Scripts**: `ProgramKind::Script` and `programs.lifetime_seconds`. A running copy is spent after its lifetime; stopping it early destroys it.
  `App\Programs\Lifetime` reaps lazily and `programs:expire` sweeps every minute. `chk_programs_kind` admits `script`.
- **Components**: decrypting a file is a weighted roll by the grade of the system it came off, over twenty components (`cyberspace.decryption.components`,
  each at least 5% wherever it appears). **Files record where they came from** (`App\Cyberspace\FileOrigin`) and decryption keeps it; the stash draws
  components of one kind and origin as one slot (`InventoryItem::groupKey()`, `<x-item-group>`).
- **Supporters** (`App\Supporters\Supporters`): an account flag earning an ember badge by the character's name, an unlimited training queue, a place on
  the public `/supporters` page, a thank-you message with the Discord invitation, and **a picture of their own** (`App\Characters\Avatar`: centre-cropped to
  300x400, kept on disk, shown only while a supporter). Granted from `admin/supporters` or `supporter:grant`.
- **Ko-fi memberships**: `POST /kofi_webhook` (CSRF-exempt, trusted by `verification_token`, `KOFI_VERIFICATION_TOKEN`) records a subscription payment once
  (`kofi_payments`, by `message_id`) and makes the payer, matched by email, a supporter for one month (`users.supporter_until`; NULL is for good).
  `supporters:expire` runs hourly. The admin lists payments that matched no account and connects them to one, and has a history of every supporter with
  tiers, payments and totals per currency. Backfills for andreas@anderssons.email ($2 Supporter, 2026-10-07) and ACiD (Hyperfan, for good).

### Changed

- **Storage, memory and compute are carried to one decimal place**: program costs, item size and capacity, deck onboard storage, hardware measures, file
  sizes and a transfer's remaining QB. `App\Support\Qb` rounds totals and compares in whole tenths; the item editor takes `step="0.1"`.
- `ItemGranter` no longer adds to a copy that is running or belted.

### Operations

- Set `KOFI_VERIFICATION_TOKEN` in the production `.env`, and the webhook URL on Ko-fi to `https://dedlok.online/kofi_webhook`.
- `public/images/characters/avatars/` must be writable by `dedlok`.
- `php artisan scans:file Noodle` once deployed (needs the PHP 8.4 binary).

### Migrations

- `2026_10_08_180000_storage_memory_and_compute_have_a_decimal`, `..190000_a_program_may_have_a_lifetime`, `..195000_a_program_kind_script`,
  `..200000_the_components_a_decrypted_file_becomes`, `..210000_designs_and_crafts`, `..220000_the_code_crafting_skills`,
  `..230000_the_first_script_and_its_design`, `..240000_supporters`, `..250000_kofi_memberships`, `..260000_andreas_signed_up_as_a_supporter`,
  `..270000_acid_is_a_hyperfan`.


### Added

- **Ko-fi memberships.** `POST /kofi_webhook` makes a subscription payer a supporter for one month from the payment (`users.supporter_until`, `kofi_payments`, `supporters:expire` hourly). Needs `KOFI_VERIFICATION_TOKEN`.
- **Scripts**: `ProgramKind::Script` and `programs.lifetime_seconds` (migration `2026_10_08_190000_a_program_may_have_a_lifetime`). A running copy is
  spent after its lifetime, and stopping it early destroys it. `App\Programs\Lifetime` reaps lazily (running-process reads and starts) and
  `programs:expire` sweeps every minute; the item editor has a *Lifetime (seconds)* field, the Running modal shows time left, Current processes a
  bar. Step 2 of Code Crafting (`CRAFTING.md`).

- **Components** (migration `2026_10_08_200000_the_components_a_decrypted_file_becomes`, `cyberspace.decryption.components`): decrypting a file is
  now a weighted roll by the grade of the system it came off, over twenty components (the Memory Dump and nineteen new items), each at least 5% wherever it
  can appear, every grade summing to 100 (a config test holds both). The row still converts in place. **Files now record where they came from**
  (`App\Cyberspace\FileOrigin`: system type, owner, OS, IDS and ICE level, encryption) and decryption keeps it; a component is named by one origin field,
  "Kernel Log (AuraOS)" (`InventoryItem::label()`). Files taken before this have no origin. Step 3 of Code Crafting (`CRAFTING.md`).

- **Code Crafting** (`CRAFTING.md`): designs (an item with a recipe: a Design Copy is spent by a craft, a Design Original is kept), builds in
  `crafting_slots` slots (base 1) that run only while the equipped deck is ready, components spent when a build starts (lowest qualifying grade
  first, asking before a higher one), and the product delivered when it fits (migrations `2026_10_08_210000` to `..230000`, `App\Crafting`). Skills:
  Code Crafting, Script Crafting, Icebreaker / Analyser / Decryptor Crafting (category *Code Crafting*, gates and crafting-time cuts). The first Script,
  DUMMY_TEMPORARY_PROGRAM (+10% stealth for ten minutes), and its two designs. The deck screen has a *Crafting* panel, Current processes a bar, the item
  editor a *Design* panel, and `crafting:settle` runs every minute. Also: `chk_programs_kind` admits `script` (migration `..195000`), and
  `ItemGranter` no longer adds to a copy that is running or belted.

- **Supporters** (migration `2026_10_08_240000_supporters`, `App\Supporters\Supporters`): an account flag that earns an ember badge by the character's
  name, an unlimited training queue, a place on the public Supporters page, and a thank-you message with the Discord invitation. Granted from the new
  `admin/supporters` screen or `supporter:grant`.
- **The stash draws components of one kind and origin as one slot** (`InventoryItem::groupKey()`, `<x-item-group>`); each stays its own row.

### Changed

- **Storage, memory and compute are carried to one decimal place** (migration `2026_10_08_180000_storage_memory_and_compute_have_a_decimal`):
  program costs, item size and capacity, deck onboard storage, hardware measures, file sizes and a transfer's remaining QB. `App\Support\Qb` rounds
  totals and compares in whole tenths, so a deck exactly full is full and not over; the resolved deck stats round to a tenth; the item editor takes
  one decimal place (`step="0.1"`) and refuses two. Existing values convert exactly. First step of Code Crafting (`CRAFTING.md`).

## [0.44.0] — 2026-10-08

Scan files make themselves, a Current processes modal, auto-egress explained.

### Added

- **A finished read files itself** (`Probe` calls `ScanExporter` after the last pass). Filing no longer spends the read: the system stays
  on the map and in the briefing. `system_scans.filed_at` (migration `2026_10_08_170000`) makes it once per read
  (`ScanExportRefusal::Filed`). With no room on the deck nothing is made and the dialog's *Save to stash* remains as the fallback; the poll
  sends `filed` so the button hides once the file exists.
- **Current processes** (`App\Characters\CurrentProcesses`, fragment `processes.current`, a chip and modal in the top bar): scans, travel /
  exploring / surgery, decryption (cycle and files waiting), training, drugs in effect, withdrawals, accepted jobs' deadlines, and a *Clocks*
  group (market restock, next point of SI, trained regeneration). The next-run moments come from the new `config/cadence.php`, which
  `routes/console.php` reads too. Lockdowns and relocations are deliberately not in it.
- **The briefing warns of an auto-egress** (`Simulator::siBelowThreshold()`, the same test the run makes) when SI is already under the egress mark.
- **Wide screens put the map's side boxes beside the map** (`.map-split` container query, `@section('wide')` page column).

### Changed

- **A run that succeeded ends as Completed**: extracting, installing a backdoor (including a mission plant) or burning. Leaving otherwise is Egressed.
- **An auto-egress says which threshold did it** (trace or SI), in the run log and as a notice that survives the redirect to the map. The turn
  that ends a run is now written into its log.
- SI regeneration is one point per two hours (`synaptic_mend_minutes` 120).

### Operations

- `php artisan scans:file <handle>` files a character's finished, unfiled reads (for the ones that finished before reads filed themselves).

### Migrations

- `2026_10_08_170000_a_finished_read_is_filed_by_itself`: `system_scans.filed_at`.


## [0.43.0] — 2026-10-08

Decryption: stolen files can be broken, sold, and used for supply jobs.

### Added

- **Decryption** (`App\Decryption\DecryptionQueue`, the only writer of `decryption_queue`). A reorderable queue of stolen files, worked by
  every Decryptor program running on the equipped deck, one file apiece (`decryption_queue.worker_id` is the running copy; one copy never
  works two files and one file is never worked by two copies, so two Ghostkeys break two files at once, within `program_threads`). A free
  decryptor takes the first waiting file it can break. A cycle (`decryption_cycle_seconds`, base 300) ends in ONE `CheckRoller` roll of
  `check.<encryption>` against `cyberspace.decryption.difficulty.<slug>` (kind `decryption`, floor 0.02 in `checks.floors`), scaled by
  `decryption_chance.<slug>`; a pass converts the SAME inventory row to a Memory Dump, a fail costs the cycle and nothing else. Queueing
  needs the encryption's skill at its gate level (`cyberspace.decryption.gate`, 1). Settling is lazy and catches up, with
  `decryption:settle` every minute for the inbox message (`notifications.messages.decrypted`); every way of stopping a decryptor goes
  through `ProgramRunner::putBack()`, which settles first and frees that copy's file. It works during a run.
- **The Decryption panel on the equipped deck's screen** (drag or arrow-key reordering through the kit's `data-queue-sort`, odds and
  expected time per file, Working / Waiting) and a **Decrypt button and status on the stash card** (`inventory_items.decryptionEntry`).
- **Stats:** `decryption_cycle_seconds` (an interval, so skills shorten it), and a generated `decryption_chance.<slug>` per Encryption
  skill, all tagged `decryption_chance` so one modifier on that tag set reaches every encryption. A chance bonus is a multiplier on a
  cycle's chance (the roller's own), not percentage points.
- **Programs can be limited to some encryptions** (`programs.decrypts`, slugs, NULL is any), edited as ticks in the item editor. **Ghostkey**
  (Cinder only, common, shelf 25, free, 2 QB to own, 2 QB RAM and 3 qFLOPS to run, multithreaded) is the first.
- **Skills:** Cryptology and Precomputation (category `Decryption`, rank 8, uncommon, shelf 10: +2% chance and -2% cycle time a level, for
  every encryption), and each encryption skill grants +2% chance a level for its own. Ouroboros is off the market (ultra-rare, gained
  through exchanges, quests and drops).
- **Buying from players is one rule for locations and exchanges** (`App\Trade\Buying`; `LocationBuy`, the new `EntityBuy` and the
  `BuysFromPlayers` trait): a listing is an item, a percent of its stock price (default 100; a file's stock price is its own value, the
  broken one on a Memory Dump) and a limit per window (`city.sell.reset_hours`, 8) shared by every player, refused rather than reduced
  (`CityRefusal::WontTakeMore`). `BuyListEditor` is the only writer and diffs, so saving never refills a limit. Exchanges buy per entity
  over a range of desk grades (`entity_buys`, `DeskBuyer`, `exchange.sell`), edited on the entity shelf page.
- **Supply jobs take sealed data dumps** as files (`MissionLedger::suppliable()`): whole scan files first, then loose Encrypted Data
  Dumps. A Memory Dump does not count.
- **The scan-complete message carries the address** of the system scanned, in the sentence and with the copy button.

### Changed

- **File values follow the system's grade** (`cyberspace.contents.data.value` sealed and `.value_broken` decrypted, each rolled on its own,
  25-50 / 50-125 at grade 1 up to 1000-2000 / 2000-5400 at grade 9; they were 10-60 times higher). A file's encryption follows bands by
  grade (Cinder 1-4, Halcyon-3 3-6, BLACKGLASS 6-8, Ouroboros 8-9) and it remembers the grade it came off (`ExtractedFile::$grade`).
- **Everybody mends a point of Synaptic Integrity every two hours** (`synaptic_mend_minutes`, base 120; it was 240).
- **A location's buy list is a percent and a limit**, not a price (`location_buys.price` is gone; a blank price became 40, the fraction it
  used, and a flat one the percent that pays it).

### Migrations

- `2026_10_08_100000_files_are_worth_what_their_grade_says`: the Memory Dump item, and **every file on a system and every dump a player
  holds is re-rolled** from the new bands (the old values are not recoverable; `down()` does nothing).
- `2026_10_08_110000_places_buy_by_percentage_up_to_a_limit`: `location_buys` percent / limit columns (price dropped) and `entity_buys`.
- `2026_10_08_120000_the_decryption_queue`: `decryption_queue`.
- `2026_10_08_130000_the_decryption_skills`: Cryptology, Precomputation, and the four encryption skills' effects.
- `2026_10_08_140000_decryption_imprints_are_stocked_by_hand`: those imprints' rarity and shelf, and Ouroboros off the market.
- `2026_10_08_150000_a_decryptor_may_be_limited_to_some_encryptions`: `programs.decrypts` and Ghostkey.
- `2026_10_08_160000_a_decryption_has_a_worker`: `decryption_queue.worker_id`; Ghostkey multithreaded.

**After deploying:** the schedule picks up `decryption:settle` by itself (the crontab already runs `schedule:run`). Add the Encrypted Data
Dump and the Memory Dump to the buy lists of the places that should buy them, and author Decryptors for the other encryptions.


## [0.42.3] — 2026-10-07

A Tasks & Missions screen.

### Added

- **A *Tasks & Missions* rail item and screen** (`/tasks`, `TasksController`, `tasks.index`) with three headings. *Missions* lists the
  exchange jobs the character has accepted and not yet finished (`Mission::accepted()`: failed, completed and voided jobs are history, an
  unaccepted offer is the desk's to show), soonest deadline first, each as the desk's own `<x-mission-card>`: the task, its systems by
  address with grade, pay and penalty, time left, a survey's per-system *File in hand* / *Needs a scan file* tags, and a supply job's
  meter. The card gained three props: `:actions="false"` (no Complete or Accept: a job is handed in at the desk it came from),
  `:desk="true"` (who it is for, the agent, the desk's address) and `:abandon="true"` (Abandon alone, since giving a job up needs no
  desk; its tooltip is *Costs -X standing*, the job's penalty). *Journal* and *Tasks* (quests) read "Not yet implemented." The rail
  glyph is U+25C7, one already proved to render.

No migrations.


## [0.42.2] — 2026-10-08

A survey job can be completed, and accepting any job writes it down in the inbox.

### Added

- **Accepting a job sends its details to the inbox** (`MissionLedger::briefing()`, `notifications.messages.mission_accepted`): the task and
  who it is for, the systems by address (in the sentence and in `details['targets']`, drawn with the copy-an-address button), what it
  pays and costs, and the due date as an absolute `Y-m-d H:i UTC` (a stored message is never recomposed). A supply job names a file
  count. A refused acceptance writes nothing. This is the inbox's one exception to "while nobody was looking", recorded in CLAUDE.md.
- **A survey's card tags each system *File in hand* or *Needs a scan file*** (`MissionLedger::heldAddresses()`; `heldFiles()` is the
  one definition of what counts, shared with supply jobs).

### Fixed

- **A survey (`MissionAction::Scan`) could never be completed.** The design says it is handed in as scan files, but `complete()` only
  looked at `satisfied_at` on the targets and nothing ever wrote it for a survey (only Burn and Plant record where they happen). It now
  takes one whole, unexpired, loose scan file of each named system, all or nothing, spends the files and writes `satisfied_at` as the
  receipt (`handInSurvey()`). Scanning alone still does not tick a survey: the files have to be exported first. The hint now says
  "one for each".

No migrations.


## [0.42.1] — 2026-10-07

A routing chain box on the map screen.

### Added

- **A *Routing chain* box on the cyberspace screen**, beside Jump, Thresholds and Bookmarks: the same `ChainBuilder::for()` call the
  run briefing makes (no target), so it lists every system you hold a routing backdoor on, highest grade first, up to
  `routing_hops`, each with its order, name, copyable address and grade. The header's count (`used/cap hops`) has the hover text
  *X out of N maximum hops*. The briefing's own empty states are reused (cannot route / nothing held). Read only: there is nothing
  to pick. `CyberspaceController::index()` passes `chain` and `chainCap`.


## [0.42.0] — 2026-10-07

Players can write to each other, the front page counts players, and the encounter editor can remove rows.

### Added

- **Messages between players**: *New message* in the inbox (`MessageController`: `messages.create`, `.store`, `.recipients`). To (a
  character's handle, suggested as you type through a `<datalist>` filled by `data-suggest` in `ui-kit.js`: handles only, at most
  eight, never your own), Title (100) and Message (500, the inbox column's width). It arrives as an ordinary notification
  (`Category::Personal`, `Notifier::sendFrom()`) with the sender in `details['from']`; the inbox shows *From:* and a *Reply* link
  (`messages.create?to=`) in place of *Go to*. Sending is throttled (15 in 10 minutes) and to yourself is refused.
- **Plain text only** (`App\Support\PlainText`): everything typed is decoded of entities, stripped of script/style blocks and every
  tag (in a loop, so a tag rebuilt from the pieces of another goes too) and of control and invisible formatting characters (bidi
  overrides), before it is judged and stored, so `<b></b>` is an empty title. A player's words are written as typed and never
  through `Text::get()`, and every view prints them escaped.
- **Player counts on the front page**, under the version number: *Total players* (accounts with a character) and *Active players
  last 7 days*, cached five minutes under `home.player_counts`. Active is `users.last_active_at`, written by
  `TouchLastActive` at most once every five minutes and never by the inbox poll. Accounts that existed before the column are NULL
  until their next visit, so the active figure starts low and fills in.
- **The encounter editor can remove a screen, a choice or an outcome** (`data-remove-row`): it leaves the form and nothing is
  deleted until save. Rows are not renumbered (the request reads them by key), and `data-add-row` takes the next index past every
  one ever used, so a "+" after a ✕ cannot reuse an index a row still has.

### Migrations

- `2026_10_07_200000_a_user_remembers_when_they_were_last_active` (adds `users.last_active_at`)


## [0.41.0] — 2026-10-07

Link unfurls with a picture and a description, a credits name on registration, and two location descriptions.

### Added

- **Link unfurls**: `layouts/social-meta.blade.php`, included in both layouts' `<head>`, gives a shared link its Open Graph and
  Twitter tags. `og:title` is the page's own `<title>` plus ` - A cyberpunk browser game`; `og:image` and `twitter:image` are
  `public/images/square.jpg` (640×640, `twitter:card` `summary`) through `Asset::url()`, absolute; `og:description` is
  `strings.unfurl.description`, with the tagline in `strings.unfurl.tagline`.
- **An optional credits name on registration, while `alpha_testing` is on**: *What do we call you (for credits, on the thank you
  page)*. `users.credit_name` is nullable, trimmed (blank is NULL), 100 characters, validated before the key is spent, stored only
  while the flag is on, and shown beside the email in the admin's Beta keys list. Nothing in the game reads it yet. Documented
  in the flag's table in CLAUDE.md.

### Changed

- The descriptions of two locations (Pier Nine Warehouse, Server Farm Annex) are carried to the live database, written only where
  the description is still the starter city's.

### Migrations

- `2026_10_07_180000_two_locations_get_their_descriptions` (data: two descriptions; guarded, so a description written in the live
  admin is left alone; reversible)
- `2026_10_07_190000_a_user_may_say_what_to_call_them_in_the_credits` (adds `users.credit_name`)

The credits field appears only if `FEATURE_ALPHA_TESTING=true` is set, so a deploy changes nothing about registration otherwise.


## [0.40.0] — 2026-10-07

An invitation to the Discord, shown once to a new account.

### Added

- **The Discord invitation**: a modal (`<x-discord-invite>`, in `layouts/app.blade.php`) that opens by itself on a new account's
  first signed-in page, with the heading, text and link in `strings.discord.*`. Shown while `users.seen_discord_at` is NULL and
  somebody is signed in; closing it however (button, Esc, backdrop) posts `discord.seen` (`DiscordInviteController`, idempotent),
  which is in `RequireCharacter`'s allow-list because a new account meets it on the character creation screen. Same shape as the
  patch notes modal (`data-show-on-load`, `data-post-on-close`).
- `UserFactory` makes test users seen, so only a test that sets `seen_discord_at` to NULL meets the modal.

### Migrations

- `2026_10_07_170000_a_user_remembers_the_discord_invite` (adds `users.seen_discord_at`; every existing account is marked seen, so
  the invitation is for new accounts only)


## [0.39.0] — 2026-10-07

Consumables, hit point regeneration, a Medical drug family, drugs that work only in a fight, and a clock that stops while one is open.

### Added

- **Consumables**: an item with a `consumables` row does something once, at once, when a copy is used (`App\Items\UseEffect`,
  `heal_hitpoints` first; `Consumer` is the only user, `ConsumableEditor` the only writer). A use that would do nothing is
  refused and the copy kept; it is refused in a fight. The item editor has a Consumable panel; the stash card has *Use*.
- **`hitpoints_regen`**: a stat in hit points per HOUR, zero for everybody, carried by any effect row. `characters:regenerate`
  credits each minute's exact share (`floor(rate × (m+1) / 60) − floor(rate × m / 60)`), so a rate of 1 is one point an hour
  with no stored remainder. No baseline mend for hit points.
- **The Medical drug family**: overdoses like a stimulant, never addictive (`Family::addicts()`; the ledger never raises its
  addiction and the editor refuses a non-zero one), tolerance per drug.
- **A drug may also be a consumable** (any family): taking the dose also does the instant half, before any overdose harm and
  untouched by tolerance. The stash card offers only *Take*.
- **Fight-only drugs** (`drugs.fight_only`, `duration_turns`, `turn_hitpoints`; `character_doses.turns_left`): used from the belt
  as the round's whole action (`ActionKind::Use`), working from the next round for N rounds, with a signed per-round hit point
  tick (restores are scaled by potency). They keep the family, books, tolerance and overdose; a body that does not survive the
  dose ends the fight as a loss. The player's side of a fight is now rebuilt from the character every round, which is how a
  dose's stat effects reach a fight already open.
- **An overdose warning** on the character sheet: a brass triangle on a drug family one dose from overdosing (`.warn-icon`,
  `.tip--start`).
- **The items list has one Type filter** covering every kind of item (hardware and each piece, software and each kind, drugs and
  each family or fight-only, armour, weapon, ammunition, consumable, implant, imprint, plain). `?hardware=` and `?program=` still work.

### Changed

- **The clock stops in a fight**: `characters:regenerate` and `drugs:withdraw` skip a character with a live fight; an ordinary dose
  is judged as at the moment the fight opened and gets the fight's length back when it ends. Cyberspace runs are not paused.
- A close weapon (unarmed, blade, blunt) may leave *Shots a round* blank in the weapon editor; it is stored as 1.

### Migrations

- `2026_10_07_120000_consumables` (new `consumables` table)
- `2026_10_07_150000_drugs_for_fights` (adds `drugs.fight_only`, `drugs.duration_turns`, `drugs.turn_hitpoints`, `character_doses.turns_left`)


## [0.38.0] — 2026-10-07

An Equipment tab and a belt, combat skills with effects and a weapon gate, a live inbox, a rebuilt Interact box on the map, and an alpha-testing flag.

### Added

- **The Equipment tab** (`/equipment`, read only): armour in four places (`WearSlot`: head, torso, arms, legs), a belt of three
  (`config/equipment.php`, `inventory_items.belt_slot`, `App\Equipment\BeltEquipper`, drugs only) and a weapon in each hand
  (Primary, right, is the ranged weapon; Secondary, left, the close one), on the Implants wireframe (`<x-equipment-map>`).
  Every slot line has an *Equip* button opening `Equip: <place>`: a table of what the stash holds that would go there. The
  sheet no longer carries an equipment panel. Arms and legs are ONE slot each covering both sides (`Armour::covers()`
  expands any stored coverage to whole groups; the item editor ticks four boxes); hit locations in combat are still six.
- **Skill gates on weapons** (`combat.skill_gates`, `combat.gate_level`): pistol, SMG, rifle and shotgun need level 1 in the
  matching skill to equip (`WieldRefusal::RequiresSkill`); the stash card is tagged *Requires <skill> <level>*.
- **Combat skills have effects** and four new ones (Shotguns, Physiology, Combat Training, Tactical Movement) arrive by
  migration, with prerequisites. Existing skills only gain effects and prerequisites; their titles, descriptions, ranks and
  prices are left alone.
- **Drag to reorder the training queue** (`TrainingQueue::reorder()`, `training.reorder`): refused when the new order has a
  problem the old one did not. The plan picker's level list follows the chosen skill and only offers levels above what is
  reached or queued.
- **The inbox is polled** every five seconds from every page (`notifications.unread`, in every lock's allow-list): the rail's
  badge is redrawn and `public/assets/sound/message.wav` plays for a new message, at most once in ten seconds.
- **Inbox titles, deletion and scan data**: an accordion of titles; select-all and delete; a finished read's message carries
  its facts; old messages are given the title they would have had.
- **The map's Interact box**: Plan a run (green when the scan is whole, brass when not), Scan / Show scan, *Installed here*
  for a backdoor you hold, and the scan's facts beneath. The reading chip ticks between loads.
- **The Running modal lists what the deck could run**, each with a Run button (`ProgramRunner::refusalFor()` is the one list of
  checks; `startable()`).
- **A wallet pager**: every entry, 50 a page.
- **Feature flag `alpha_testing`** (`config/features.php`, `FEATURE_ALPHA_TESTING`, off by default): registration needs a
  beta key; the admin has a *Beta keys* section (generate, copy-and-mark-given-out, who used it). Documented in CLAUDE.md,
  *Feature flags*, with everything it touches.
- A duplicate (+) button on every effect row of the effects editor.

### Changed

- **The run log is newest first** (so is the hunt's), its turn tag is quiet, and its ICE and icebreaker lines are reworded.
  The core-touch button (Extract, Install) turns green once the core is open. Install's lines are reworded and a landed one
  says to egress.
- **`/down`**: *Incapacitated!*, an ember hazard stripe across its box and bone-and-ember stripes behind it.
- The sheet's "What is in you" is *Drugs*; the bio is a monospace, no-wrap field that keeps its leading spaces.
- "Cyberspace" messages read as "Siphon report" etc. Scan alerts name the system and address.
- No dodge, by decision: `defence` and `pursuit` are flat stats. MECHANICS.md's status table is brought up to date.

### Fixed

- Run in background on the scan window closed it and reopened it at once.
- A scanner busy reading was reported as "no scanner running".
- A siphon's button and the run header showed `cyberspace.backdoor.kind.siphon` (a label read a key under the wrong group).
- Reordering the queue overflowed `training_queue.position` (a tinyint) on MySQL while parking rows.

### Migrations

- `2026_10_06_120000_combat_skills_get_their_effects` (data: effects, prerequisites, four new skills with imprints; reversible)
- `2026_10_06_130000_old_messages_get_titles` (data: fills `notifications.title` from stored sentences)
- `2026_10_06_140000_a_belt_for_consumables` (adds `inventory_items.belt_slot`, unique with the owner)
- `2026_10_07_100000_beta_keys` (new `beta_keys` table)

The alpha flag is off unless `FEATURE_ALPHA_TESTING=true` is set, so a deploy changes nothing about registration.


## [0.37.0] — 2026-10-06

The inbox becomes an accordion of titles, a weapon can require a skill to equip, and the wallet ledger pages.

### Added

- **Inbox titles.** A message has a title and a body. `notifications.title` is composed when the message is sent, from
  `notifications.titles.<key>` (one per message key, held by a test), and never recomposed. NULL, for a message from
  before this, shows its category label.
- **Skill gates on weapons.** `combat.skill_gates` maps a weapon class to a skill slug and `combat.gate_level` (1) is
  the level; pistol, SMG, rifle and shotgun are gated. `WeaponWielder::wield()` is the one place it is asked, on the
  RESOLVED `skill.<slug>`, and refuses with `WieldRefusal::RequiresSkill`. A class left out is free, a slug with no skill
  row gates nothing, and a weapon already equipped is never taken away. A gated weapon's stash card is tagged
  *Requires <skill> <level>*.
- A **Duplicate** (+) button on every effect row of the shared effects editor, so a grant for levels 1–5 is one row and
  four clicks. (Shipped in 0.36.1.)

### Changed

- **The inbox is an accordion** (`<details>` per message, `.accordion--quiet` for an ink marker). The closed row is the
  title; opened it shows the sentence, any details list and a *Go to <category>* button (the old
  `notifications.open`). Unfolding a message reads it, by a POST to `notifications.mark` (204) from `ui-kit.js`, which
  also lowers the rail's count. Arriving on the screen still reads nothing.
- **The wallet ledger shows every entry**, newest first, 50 to a page (`pagination.ui-kit`), instead of the newest 100.
- **There is no dodge, by decision.** `defence` and `pursuit` are permanent flat stats; their comments and `COMBAT.md`,
  `CLAUDE.md`, `MECHANICS.md` and `TODO.md` now say so, and MECHANICS's status table is brought up to date.
- Bounty collection and player-versus-player are parked in `TODO.md` with what was decided (full payout, a per-pair
  cooldown, safe houses mirroring data fortresses).

### Migrations

- `2026_10_06_100000_messages_have_titles` (adds a nullable `notifications.title`; nothing is backfilled; reversible)

Combat skills and weapons are authored in the live admin, so a deploy changes nothing for a player there until they exist.


## [0.36.1] — 2026-10-05

Effects in the admin editors can be duplicated. No schema change.

### Added

- **A "+" button on every effect row** in the shared effects editor (`<x-modifier-row>`, `modifierRows()` in
  `ui-kit.js`). It inserts a copy of the row directly beneath it, carrying whatever has been typed or chosen,
  its hidden carried keys included, and focuses its level (or amount) field. Indexes are rewritten as for any add or
  remove. It is the same component for items, programs and skills, so all three editors get it. A skill that grants
  the same effect at levels 1 to 5 no longer needs five rows built by hand. String: `admin.modifiers.duplicate`.


## [0.36.0] — 2026-10-05

Physical combat gets its endings, a firearm's damage moves onto its round, and distance becomes a number.

### Added

- **What a fight pays and costs** (`App\Combat\FightEnding`, `CombatTag`, `NpcEditor`). A win pays the grade's band
  (`combat.pay`, through `Wallet` as `Reason::Fight`), rolls the opponent's drops (`npc_loot`, physical items only) and
  lowers its owner's standing by grade (`StandingReason::Defeated`). A loss applies the opponent's tags: `mugged` (a
  share of all creds carried) and/or `maim` (deepens `physical_trauma`). Getting away pays and costs nothing. A fight
  copies its terms when it opens, and the ending has its own die so the fight's pinned dice are untouched.
- **Opponents have an owner, tags and drops**, authored in a new Spoils and defeat panel at `admin/npcs`.
- **Close in**, a new fight action: a chance (the closer's pursuit against the target's defence) to win one step of
  distance.

### Changed

- **A firearm has no damage of its own — its round does.** A weapon with a calibre stores no `damage` or
  `damage_profile`, and the item editor refuses both on one. Ammunition's `damage` is now the whole figure of a hit
  (1–1000, an absolute base, not a signed percent) and its profile is required. Blades, clubs and grenades keep both on
  the weapon. An opponent with a firearm must have a standing round. CHECKs `chk_weapons_damage_or_calibre` and
  `chk_ammunition_damage` hold it in the schema.
- **Distance is a number.** A fight opens at 2 (`combat.distance.start`), 0 is close combat and 6 is the most. Nothing
  closes it by itself: only an action moves it. Create distance wins one or two steps (the luckiest
  `combat.distance.far_share` of its successes win two), takes effect at once and is offered below 6. **Disengage works
  only from beyond 2**, so a player who wants out backs off first. `Range` is now derived from the distance.
- **Who closes in and who holds range.** `FightEngine::prefersClose()` compares a side's melee weapon with its ranged
  one (best figure times shots). The better-at-close side closes in; the other shoots and backs off when closed on. An
  opponent hurt past its `flees_at` backs off to beyond 2 and then runs. The simulated player plays by `choose()`, the
  same rules as an opponent.
- The fight screen shows `Distance N` and offers Close in, Create distance and Disengage by the distance. A fight stored
  before this release (it carries a `range`) still opens, at the opening distance or at 0.
- `COMBAT.md`, `CLAUDE.md` and `TODO.md` describe all of the above.

### Fixed

- Saving ammunition from the item form failed with "The weapon rate field is prohibited": the hidden Weapon panel's
  default rate of 1 was posted as a value. (`ItemRequest`.)
- Two clock-sensitive City tests (`CityTravelTest`, `CityExploreTest`) that flaked in a loaded full run now freeze time.

### Migrations

- `2026_10_05_120000_fight_spoils_and_defeat`
- `2026_10_05_130000_a_firearms_damage_lives_on_its_round` (converts existing rows: a round's new damage is the average
  damage of the weapons taking its calibre with its old percent applied, then firearms are emptied; reversible)

Production has no weapons or ammunition yet, so the conversion is a no-op there. Nothing is seeded, so a deploy changes
nothing a player sees until an encounter with a Fight button is written. The captcha is switched off in local
development only (`CAP_ENABLED=false`, Cap lost its site key); production is untouched.



## [0.35.0] — 2026-10-05

Narrative encounters, and physical combat from the item to the fight screen.

### Added

- **Narrative encounters** (City phase 4). A graph of screens and buttons authored at `admin/encounters`, spawned by
  travel, exploring or a place, with outcomes as rows (creds, items, standing, heat, flags, injuries, damage, doses).
  A button may roll a stat, cost creds or need a flag; a screen may apply outcomes on arrival. The editor has a Help
  accordion, a live graph of the screens, a weight slider with a word for how rare it is, a stat dropdown, "+" buttons
  for screens, buttons and outcomes, and names every missing screen on save. `EnsureEncounterIsResolved` owns the
  screen while one is open; you cannot jack in during any City activity; exploring is silent when it finds nothing.
- **Damage types, armour and weapons.** Eight damage types in two families, armour worn a body part at a time
  (`WornArmour`, the seventh modifier source, counts only for the part a piece covers), weapons with ONE fixed damage
  and a profile that splits it across types, and a signed **penetration** on a hit that ignores part of a resistance
  (`Damage::$penetration`; ICE passes none). Item editor panels for Armour, Weapon and Ammunition; Wear, Equip and
  Load actions on the stash card; an Equipment panel on the character sheet.
- **Ammunition.** One item per kind of round, unique on calibre and a type (standard, full metal jacket, hollow
  point, armour piercing, incendiary, shock); stackable in the stash. Rounds loaded into a weapon are two columns on
  that copy's own inventory row, written only by `WeaponLoader`; a loaded weapon cannot be sold, listed or put away,
  and destroying one returns its rounds.
- **Riders** (stun, burning, poisoned) on weapons and rounds, authored in the editor.
- **The fight engine and `fight:simulate`.** `App\Combat\Fight`: five hit-quality tiers, burst fire where each shot is
  7% worse than the last, two ranges, Create distance, Disengage, Load, riders and their ticks, NPCs that flee below a
  share of their hit points. Pure and never writing. `php artisan fight:simulate <handle> [--foe=<slug>]` plays many
  fights of a character's real loadout, seeded, and writes nothing.
- **Opponents** at `admin/npcs`: numbers, three pointers at weapon and ammunition items, resistances by damage type and
  by body part, a picture by convention (`public/images/npcs/<id>.jpg`, 3:4).
- **The fight itself.** A stored fight (`fights`, `characters.current_fight_id`) written only by `App\Combat\Fights`,
  its own screen at `/fight` with every chance quoted, `EnsureFightIsResolved`, and `fight:start <handle> <slug>`.
  Everything that could start something (jacking in, runs, travel, exploring, shops, clinics, surgery, an encounter
  from the sweep) refuses during a fight.
- **Encounters can start fights.** A choice may be a Fight: an opponent and a win, a loss and a nobody-won destination.
  Rewards are the win screen's arrival outcomes; a loss is read after the player gets up.
- **Combat stats**: `aim.<class>`, `weapon_damage.<class>`, `defence` and `pursuit`.
- **Content**: the Acclimatization and Observation skills, and the district descriptions.

### Changed

- The run briefing and the live run screen are reworked (run overview with thresholds, an Opposition panel, the
  evasion card on the egress button).
- "Walk" is "Travel" throughout, and a trip can be stopped with a "Stop travelling" button.
- `CLAUDE.md` is compacted; the cyberspace rules moved to `CYBERSPACE_RULES.md`. `COMBAT.md` is new.
- The kit gains a card-select dropdown and the encounter node graph.

### Fixed

- A `prohibited` slug rule let an empty value through on update, which wrote a NULL slug (a 500) in the encounter,
  location and district editors.

### Migrations

- `2026_10_03_190000_exploration_skills_and_district_descriptions`
- `2026_10_03_200000_narrative_encounters`
- `2026_10_03_210000_encounter_screens_have_outcomes`
- `2026_10_03_220000_armour`
- `2026_10_03_230000_weapons_and_ammunition` (also adds `characters.equipped_ranged_id`, `equipped_melee_id`)
- `2026_10_04_100000_ammunition_has_a_type`
- `2026_10_04_110000_weapons_and_rounds_carry_riders`
- `2026_10_04_120000_weapons_hold_rounds` (adds `inventory_items.loaded_ammo_id`, `loaded_rounds`)
- `2026_10_04_130000_one_item_per_kind_of_round`
- `2026_10_04_140000_npcs`
- `2026_10_04_150000_fights` (creates `fights`, adds `characters.current_fight_id`)
- `2026_10_04_160000_encounters_can_start_fights`

Nothing is seeded: no weapon, armour, round or opponent exists until one is authored in the admin, so a deploy changes
nothing a player sees until an encounter with a Fight button is written. What a win pays and a loss costs is next
(`COMBAT.md`, step 6).


## [0.34.1] — 2026-10-04

Development fix: the composer sidecar builds with the web image's extensions.

### Fixed

- **`docker compose up` failed in `cyberpunk-composer`.** The sidecar used the
  stock `composer:latest` image, which has no `ext-intl`, so `composer install`
  refused `composer.json`'s platform requirement. The sidecar now builds from the
  same `Dockerfile` as `web`.

No schema changes. Patch release: a deploy is a `git pull` and nothing more.


## [0.34.0] — 2026-10-03

The City: a body that stands in a district, places to go, and clinics that fit implants.

### Added

- **Districts and places.** Every character now has a body in a district
  (`characters.district_id`, filled from `city.start`). The old Street screen
  is the City: a city map, a map per district, and places on it shown as icons
  with a tooltip. Districts carry a decoration colour. Seven districts and ten
  placeholder places arrive by migration. A place can be open, found by exploring,
  or unlocked by a flag (`Location::scopeVisibleTo()`).
- **Travel and exploring.** Timed activities, one at a time (`city_activities`).
  Travel by walking (free) or hailing a pod (25 creds per district on the route);
  a pass takes time in every district on the route but the destination, and
  cancelling waits for the pass in progress. Exploring is a roll on the new
  `exploration` pool and may turn up hidden places. Settling is lazy and swept
  every five minutes by `city:settle`. A jacked-in character cannot travel.
- **Shops.** A place with `shop_items` sells to you, with an optional price,
  optional stock and a lazy restock clock. A place with `location_buys` buys
  from you. Both go through `ShopKeeper` and the wallet.
- **Clinics.** A place with `clinic_services` restores HP, SI and treats
  injuries, priced per point (`Clinic`).
- **Implant surgery.** Implants are fitted and taken out at a clinic, not on the
  Implants screen, which is now read-only. A surgery is a timed activity charged
  up front; fitting is a roll of the clinic's rating against a difficulty set
  by the implant's tier, with the chance quoted beforehand. A failure injures you
  and the implant stays in your stash, never destroyed. Taking one out is a fee
  and time, no roll. Nobody leaves the table, and a body on it cannot jack in.
  A clinic fits any implant; its risk is its rating.
- **Buy and fit.** An implant on a clinic's shelf has a second button that buys
  it and goes straight onto the table, shelf price plus fitting fee in one
  transaction. Any refusal, no room left in that part of the body included,
  rolls the purchase back.
- **Admin editors** for districts (with their links), locations, and a
  location's shop, buy list, clinic services and surgery. The surgery panel
  explains what a rating means at each tier.
- **Standing.** A completed trace costs standing with the system's owner,
  0.05 per grade of the tracing system.

### Changed

- Notification category `Street` is now `City`; stored rows are moved by migration.
- The test suite's memory limit is raised; it ran out of 128M.

### Migrations

- `2026_10_03_100000_the_city`
- `2026_10_03_110000_the_starter_city`
- `2026_10_03_120000_every_character_has_a_body_in_a_district`
- `2026_10_03_130000_the_street_becomes_the_city`
- `2026_10_03_140000_what_somebody_is_doing_in_the_city`
- `2026_10_03_150000_shops_and_clinics`
- `2026_10_03_160000_the_back_alley_clinic_opens`
- `2026_10_03_170000_clinic_surgery`
- `2026_10_03_180000_clinic_surgery_fits_anything`



## [0.33.0] — 2026-10-02

A price on your head, a wallet that remembers, and a tab for who likes you.

### Added

- **Bounties.** Every time the nightly roll puts word out about a character,
  creds are added to a new `characters.bounty`: `heat × 50`, scaled up 25% for
  each grade above 1 of the highest-grade system that cost them heat since the
  last hit (`characters.bounty_grade`, written by a completed trace and a landed
  burn, reset on a hit). Hits accumulate. Nothing collects or clears it yet —
  that is for the player-versus-player mechanic. Tunable under
  `cyberspace.heat.bounty.amount`. The bounty is shown on the character sheet,
  and the inbox message now names the running total.
- **Wallet.** A new rail item and `/wallet`: balance, a transfer form and a
  ledger of every movement of creds. `wallet_entries` is immutable, one row per
  movement with the signed amount, the balance after and a stored note.
  `App\Wallet\Wallet` is now the only writer of `characters.creds` — starting
  funds, market and player-market trades, mission rewards, siphons, extraction,
  hunter wins and ICE skim all go through it.
- **Transfers.** `App\Wallet\Transfers` sends creds to another character by
  handle, locking both rows in id order. No fee or cooldown. The recipient gets
  an inbox message (new `Wallet` category).
- **Standing tab** on the character sheet (`/standing`): every entity you have
  a non-zero standing with, and a log of standing changes (missions, runs,
  nightly decay and the rest) read from `entity_ledger`.

### Migrations

- `2026_10_02_120000_a_price_on_your_head` (adds `characters.bounty`,
  `characters.bounty_grade`)
- `2026_10_02_130000_the_wallet_ledger` (creates `wallet_entries`)



## [0.32.1] — 2026-09-30

A hidden button that wasn't, and a framework bump past a security advisory.

### Fixed

- **`.btn` ignored its own `hidden` attribute.** `.btn`'s unconditional
  `display: inline-flex` beat the browser's UA `[hidden]` rule regardless of
  specificity — the same failure already patched for `.tabpanel`, the filter
  chips and `[data-too-dear]`, just missed here. On the scan-reading dialog
  this meant "Run in background" never actually hid itself once a read
  completed, so three footer buttons tried to render at once; with no
  `flex-wrap` on `.panel__ft`, at phone width that pushed "Close window" off
  the visible edge of the dialog, outside the tappable area. Fixed with
  `.btn[hidden] { display: none }` and `flex-wrap` on `.panel__ft`.

### Changed

- **`laravel/framework` updated to v13.34.0**, past the security advisory
  fixed in 13.30.0. `composer.json`'s existing `^13.17` constraint already
  allowed it; only `composer.lock` moved.


## [0.32.0] — 2026-09-30

Heat stops being inert. Cross a threshold and it starts finding you.

### Added

- **Hunters.** Moving in cyberspace — a step, a jump, a recall, jacking in —
  now rolls a low, heat-scaled chance of a hunter: not a run against a
  system, but a straight exchange against ICE generated on the fly from the
  real catalogue. No trace, no IDS, and no door out until it is settled — the
  screen locks the same way a run does, and the two locks refuse each other.
- **The chance is a straight line on heat**, from 0.5% at 5 heat to a capped
  35% at 100, and deliberately has no cooldown: a hunter just beaten is
  exactly as likely to come again on the very next step.
- **Five hunter grades**, tied to the heat that spawned them. Grade 5 draws
  ICE from the same level range a grade 8-9 system does — the top of the
  curve is meant to hurt.
- **The strongest owned icebreaker starts itself** — there is no briefing to
  choose one at. Every existing ICE resistance and Ensnare reach a hunter for
  free, since the damage is the same `damage.synaptic_integrity.ice` a system
  fight deals.
- **Winning pays creds by grade (roughly 300 to 1,000) and trims heat by a
  flat, grade-scaled amount**, plus a low chance of a program, silently
  skipped if the deck has no room. **Losing costs a random 5-15% slice of
  heat rather than resetting it**, and risks real Synaptic Integrity and the
  waiting screen.
- **A hazard-stripe UI kit component** — 45° caution tape between `--ember`
  and the page background — marks the hunt screen so it never reads as an
  ordinary run.

### Migrations

- `2026_09_30_130000_hunters_answer_the_heat` (creates `hunts`, adds
  `characters.current_hunt_id`)


## [0.31.0] — 2026-09-30

The operating-system roll and Ensnare open up to skills, expansion cards exist, and a dead workflow is gone.

### Added

- **Kernel Exploitation** (rank 8, Intellect/Focus, needs Cyberdecks 3, 9,000 creds, on
  the market): +2% a level on the whole `check+operating-systems` tag, 10% trained out —
  one skill reaching whichever OS check a run actually rolls, rather than one per OS.
- **The four operating-system skills each grant a small universal bonus of their own**:
  +1% a level (5% trained out) on the same tag, so training any OS deeply carries a
  little to the others. The gate is untouched — `percentage_of_base` scales what's
  already there, and an untrained OS's table value is 0.
- **`ensnare_reduction`** is a stat now: the active ICE's Ensnare reduction (25/40/55 by
  level), resolved with the runner's modifiers before it becomes the egress multiplier.
  `AttackTag::multiplier()` stays a pure, unmodified lookup; `Simulator::
  ensnareMultiplier()` is the one place a character's modifiers reach it.
- **Signature Scrambling** (rank 16, needs Egress Protocols 3, 35,000 creds, on the
  market): −5% `ensnare_reduction` a level, −25% trained out. An Ensnare III snare (55%
  off egress) comes down to about 41%.
- **Two expansion cards**, the first content to fill that socket: **BlackBox II**
  (uncommon, 6,000 creds, +6% `icebreaker_damage`) and **Pandora SC-652** (rare, 9,000,
  +9%).

### Changed

- **AuraOS, Prism-V, IRONCLAD and Lattice repriced** against what they actually gate:
  5,000/5,000/15,000/250,000 → 3,000/8,000/20,000/200,000, tracking both rank and how
  much of the grid each one opens (1,880/639/289/58 systems).

### Fixed

- Five tests that assumed the hardware and deck_modules tables start empty in every
  fresh database — true only by accident until real hardware content went through a
  migration for the first time. Scoped to the item under test instead.

### Removed

- `.github/workflows/manual.yml`, which POSTed to a `deploy.php` that no longer exists
  and failed on every tag push. The polling deploy worker replaced it long ago.

### Migrations

- `2026_09_29_230000_kernel_exploitation` (a skill and its imprint)
- `2026_09_30_100000_operating_systems_reflect_what_they_gate` (a data re-tune by slug)
- `2026_09_30_110000_two_expansion_cards` (two items and their deck_modules rows)
- `2026_09_30_120000_signature_scrambling` (a skill and its imprint)


## [0.30.0] — 2026-09-29

ICE hits on a flatter curve, traces and operating systems open up to skills, and small percentage skills stop rounding away to nothing.

### Added

- **`trace_hop_chance`** is a stat now: the hop's grade still sets the base (95% at grade
  1 down to 20% at grade 9), and a runner's modifiers move it. Negative lowers the
  trace's chance of getting through.
- **The operating-system roll goes through the skill's own `check.<slug>` stat.** The
  level still sets the base off `cyberspace.os.values`, and anything targeting the check
  reaches the roll.
- **Opaque Routing Protocols** (rank 8, Intellect/Focus, needs Routing 3, 15,000 creds,
  on the market): −4% `trace_hop_chance` a level, −20% trained out.
- **Everybody mends 1 point of Synaptic Integrity every four hours**, trained or not,
  through the new `synaptic_mend_minutes` stat (base 240, an interval so a percentage
  off it lands). Neuroplasticity and doses still stack on top as the fast trickle.
- **New accounts start caught up on the patch notes.** Registering sets
  `last_seen_changelog` to the latest release, so a first visit shows nothing.
- **Existing accounts are backfilled from when they were last active** — the newest
  release note dated before their last recorded action (a run, a purchase, a mission,
  training, a dose, or reading a message; falling back to account creation), so
  everything from that day on opens once.

### Fixed

- **ICE damage is on a flatter curve.** Attack ran 7 → 57 and hit chance 15% → 95% across
  the twelve levels, so damage a turn grew about fifty-fold while a Synaptic Integrity
  pool only grows about three-fold — a landed ICE XII hit cost more than a fully built
  character's whole pool. Attack now runs 7 → 13 and hit chance 15% → 50%. Integrity is
  untouched, so fight length and the twenty-turn rule are unchanged.
- **`check.<slug>` no longer rounds down.** Both places that read a check
  (`CheckRoller`, the operating-system roll) already used it as a float, so flooring it
  only threw away a fraction of every small skill modifier that targeted one. The
  operating-system table is back to its exact original decimals now that nothing forces
  it to whole numbers.
- **Three skills that rounded away to nothing at low levels, the same bug in three
  places.** A percentage of a small, floored or rounded stat can vanish below level 3
  or 4 — this is what made Reactive Firewalling inert at level 1 last release, and the
  same check turned up two more:
  - **Reactive Firewalling** raised to −5% a level (was −2.5%).
  - **Mitigation Protocols** is `+1`/`+1` flat at levels 1–2, then `+4%`/`+4%`/`+6%` at
    3–5 (was a uniform +2%/level that did nothing until level 3, and even then only
    against the strongest icebreakers).
  - **Neural Sheathing** raised to +5% Synaptic Integrity max a level (was +1%, which
    did nothing for a typical starting character until level 4).

### Migrations

- `2026_09_29_170000_existing_players_catch_up_on_the_patch_notes` (data only)
- `2026_09_29_180000_opaque_routing_protocols` (a skill and its imprint)
- `2026_09_29_190000_ice_hits_on_a_flatter_curve` (rewrites the twelve `ice` rows)
- `2026_09_29_200000_reactive_firewalling_retuned` (a data re-tune by slug)
- `2026_09_29_210000_mitigation_protocols_below_the_floor` (a data re-tune by slug)
- `2026_09_29_220000_neural_sheathing_retuned` (a data re-tune by slug)


## [0.29.0] — 2026-09-29

ORACLE earns its price, and the game tells players what changed.

### Added

- **Patch notes.** `PATCHNOTES.md` is the players' changelog, one
  `## X.Y.Z (YYYY-MM-DD)` heading per release, backfilled to 0.1.0. A signed-in
  player sees what they have not read in a modal on the front page, opened by
  itself; a new account sees the latest release alone. Closing it, however it
  is closed, records the newest release in `users.last_seen_changelog`, which
  never moves backwards. The version under the logo reopens every note.
  Signed-out visitors are shown nothing.
- **`bin/release` drafts the patch notes too**, from the changelog entry, and
  opens nano on them before committing. `--changelog` and `--patchnotes` take
  entries written ahead of time.

### Changed

- **ORACLE** is +19% to scan checks (was +9%, below the common Surveyor Mk II's
  +10%) and −25% scan time, and is no longer sold on the market.

### Migrations

- `2026_09_29_150000_oracle_earns_its_price` (a data re-tune by slug)
- `2026_09_29_160000_a_user_remembers_the_patch_notes_they_read` (adds
  `users.last_seen_changelog`)


## [0.28.0] — 2026-09-29

ICE can be resisted, getting up can be trained, and being liked can be learned.

### Added

- **Reactive Firewalling** (rank 8, 4,500 creds, on the market, needs
  Cyberdecks 3): 2.5% less damage from ICE a level.
- **Defensive Compartmentalization** (rank 16, 35,000 creds, needs Reactive
  Firewalling 3): 6% less damage from ICE a level. Not sold on the market; it
  will be found at Exchanges.
- **Spectral (alpha 0.3)**, an experimental daemon: 8% less damage from ICE
  while it runs, at a heavy memory cost. Joint Signals Command keeps it on
  their loyalty shelf.
- **Joint Signals Command's loyalty shelf** also carries Palantír and the THO
  Nightjar.
- **Trauma Recovery** (rank 8, 3,000 creds): 10% less time on the floor after
  a defeat, per level.
- **Showmanship** (rank 8, 2,500 creds): 3% more standing from missions, per
  level.
- In the admin shelf editor, **⊘ marks an item that is not on the market**,
  in the item picker and on rows already on the shelf.

### Changed

- **Routing** is +1 routing hop a level and nothing else; its bandwidth grant
  at level 2 is gone.
- The time spent on the floor after a defeat is measured in seconds
  (`recovery_seconds`, 60) instead of minutes, so a percentage can shorten
  it. The wait itself is unchanged.

### Migrations

- `2026_09_29_120000_reactive_firewalling` (a skill and its imprint)
- `2026_09_29_130000_defensive_compartmentalization` (a skill and its imprint)
- `2026_09_29_140000_content_authored_on_localhost` (Spectral, Trauma
  Recovery, Showmanship, three shelf rows and Routing, upserted by slug)

## [0.27.0] — 2026-09-28

Your deck has to hold what you carry, and skills point the right way.

### Added

- **Deck storage is real.** Everything in your data inventory — programs,
  extracted files, eidetic imprints — lives on the deck you carry and has to
  fit. Anything inside a memory shard does not count.
- **Every deck has its own onboard storage**: 8 QB on the Kurogane Explorer,
  10 on the Scout and NC-II, 12 on the Wayfarer and NC-III, 16 on the Aegis
  Kader, 32 on the Monolith and Citadel, 64 on the Sturmlicht, Daimyo and
  Nightjar. Storage modules and the Cyberdecks skill add to it; without a
  deck you hold nothing.
- **What will not fit is refused** — at the market, the player market, the
  loyalty store, when exporting a scan and when taking something out of a
  shard. On a run, what your deck cannot hold stays on the system, and a file
  download is never started if the file would not fit.
- **A deck carrying more than it holds will not jack in or start programs**
  until you make room. Nothing is ever deleted for being over.
- The deck screen shows a **Storage** bar beside Compute and Memory.
- Admins set a deck's onboard storage in the item editor.

### Changed

- **An eidetic imprint takes as many QB as its skill's rank**: 1 QB for the
  quickest skills, 16 for the slowest. The hardest skills need a better deck,
  or a storage module, before their imprint can be carried.
- **No attribute can start below 10.** Nothing can be dumped at creation;
  play can still lower one.
- **Mitigation Protocols** gives +2% icebreaker damage a level (it cost 1%).
- **Advanced Routing** is +1 routing hop a level and nothing else; its dead
  routing check, bandwidth and power-draw grants are gone.
- **Power Management** still trades heat for power, and now also adds 2
  thermal load at levels 4 and 5, beside their extra supply.

### Fixed

- A training plan that stops at a prerequisite whose imprint was never
  injected now names that prerequisite, instead of reading as though the
  skill being planned was not injected.

### Migrations

- `2026_09_28_220000_three_skills_point_the_right_way` (a data re-tune by slug)
- `2026_09_28_230000_a_deck_carries_its_own_onboard_storage` (adds
  `items.onboard_storage` and writes each deck's figure)
- `2026_09_28_240000_an_imprint_is_as_big_as_its_rank` (rewrites imprint
  sizes still at the old flat 8)

## [0.26.1] — 2026-09-28

Scanners stop stacking for free, missions pay less, and heat scales with what you hit.

### Changed

- **Running several programs of one kind has diminishing returns.** Two
  scanners are not twice one scanner, whether or not they are the same
  program: the strongest gives its full effect, the next 86%, then 57%, 26%,
  10%, 3%, and any more give nothing. Different kinds of program — a scanner
  and an evasion suite — never reduce each other.
- **Mission payouts in creds are a tenth of what they were**, at every grade.
  Standing and loyalty rewards are unchanged. Jobs already offered or taken
  keep what they were offered with.
- **Being traced costs heat by grade**: twice the system's grade (2 at grade 1,
  18 at grade 9), up from a flat 1. It lands when the run ends, however it
  ends.
- **Burning a system costs 2.5 × its grade in heat**, up from a flat 2 — always
  more than being traced off the same system. A burn that lands replaces the
  trace's heat rather than adding to it.

## [0.26.0] — 2026-09-28

Loyalty buys more than things: Lockjaw will make your heat go quiet.

### Added

- **The loyalty store sells services.** An entity can do something for you
  instead of handing something over. Nothing lands in your stash.
- **Lockjaw makes heat go quiet**: 1000 loyalty takes 10 heat off, at every
  Lockjaw desk. Heat never drops below zero, and the desk will not take your
  loyalty if you have no heat to lose. Word that is already out stays out —
  this only changes what the next bounty roll sees.
- **Admins can put services on a shelf** in the entity editor: pick an item or
  a service, and give a service an amount.

### Migrations

- `2026_09_28_210000_a_shelf_can_sell_a_service` — also puts Lockjaw's offer
  on the shelf.

## [0.25.1] — 2026-09-28

Standing earned is kept longer, and higher-grade work is where it is made.

### Changed

- **Standing fades half as fast.** It drifts toward neutral by 0.25% a night
  instead of 0.5% — a month away from 60 now costs about four points, not
  eight. Grudges fade at the same slower rate.
- **Higher-grade missions pay much more standing.** From grade 2 up each grade
  pays about 2.3× the one below: a grade 5 job is now 0.64–1.06 (was 0.30–0.50),
  grade 4 is 0.26–0.48. Grade 1 is unchanged, so the bottom desks still cannot
  carry anyone to the top one.
- **Failing or abandoning a job costs about one average job's standing**, so
  penalties rise with the rewards — a grade 5 failure now costs 0.85.

Jobs already offered or taken keep the rewards and penalties they were offered
with.

## [0.25.0] — 2026-09-28

Stealth can beat the top of the grid, runs carry a loadout, and the first drug that heals.

### Added

- **A run's loadout is chosen on the briefing.** Run-only software — icebreakers
  and the rest — is picked before going in, started as the run begins and stopped
  as it ends. It cannot be changed from inside. One icebreaker runs at a time, and
  the last loadout you took in is ticked for you next time.
- **Rewire**, a nootropic: +2 Synaptic Integrity a minute for ten minutes, at the
  cost of 8% Intellect and Focus while it runs. Tolerance builds; it is not
  addictive.

### Changed

- **Awake ICE guards the core.** Extract, install and burn all fail while an ICE
  is awake — break it or egress. The run screen disables the three buttons during
  a fight.
- **The IDS watches a file transfer.** Every turn after the first of a multi-turn
  pull, the IDS rolls again. If it catches you the ICE wakes and the pull waits,
  kept, until the ICE is broken.

### Fixed

- **A grade 9 IDS was nearly impossible to slip.** The IDS table now flattens at
  the top (grade 9 from 92 to 58), so the best build a player can reach gets past
  a grade 9 system about half the time per look. Grades 2–8 come down with it;
  grade 1 is unchanged. Existing systems are rewritten to match.
- **Clandestine Intrusion Methods and Polymorphic Code** now give 4% stealth a
  level instead of 3%.

### Migrations

- `2026_09_28_180000_rewire` (adds the drug; the deploy worker runs it on pull)
- `2026_09_28_190000_stealth_percentages_raised` (a data re-tune by slug)
- `2026_09_28_200000_the_ids_table_comes_down` (rewrites `systems.ids_detection`
  where it still holds its grade's old value)

## [0.24.0] — 2026-09-28

Five new skills fill the stats nothing reached, brought to scale before anyone trains them.

### Added

- **Five skills for stats nothing moved.** Attack Vectors (icebreaker damage),
  Fractional Siphoning (siphon yield), Noise Seeding (heat decay), Memory
  Optimization (deck memory) and Power Management (deck power supply, with a
  heat cost). Their books are rare.

### Fixed

- **The new skills' numbers, before they reached anyone.** Noise Seeding removed
  about 85 heat a night instead of 1.5; Attack Vectors did nothing at levels 1–4
  for most icebreakers and tripled the weakest at level 5; Memory Optimization's
  flat bonus more than doubled a small deck's RAM. Each is now even across its
  levels, and Power Management costs the heat its description promised.

### Migrations

- `2026_09_28_160000_the_new_skills_brought_to_scale` (a data re-tune by slug;
  the deploy worker runs it on pull)

## [0.23.1] — 2026-09-28

The shop sells without reloading the page, and flash messages leave by themselves.

### Added

- **Buying from the market shelf no longer reloads the page.** The money in the top
  bar and on the market, the shelf's count, the owned tick and the quantity cap all
  update in place. Every other card on the screen is re-checked against what is left,
  and one you can no longer afford switches to "Not enough". A sold-out card is
  removed, and a refusal shows as a red flash. If the server answers anything
  unexpected, such as an expired session, the form falls back to an ordinary submit,
  so a purchase is never lost. Taking a player's offer still reloads.

### Changed

- **Flash messages glitch in, hold for five seconds, then glitch out and go.** Only
  the messages that follow an action do this. A standing warning (form errors, a deck
  that will not run, stalled training) stays until what it describes changes.

## [0.23.0] — 2026-09-28

The night's siphons send a report, and the training queue says what each entry costs.

### Added

- **One siphon report per character, every night.** The siphon job used to pay
  silently. Now each character holding a siphon gets one message: "Siphons ran:
  N ¤", with a line per system drained giving its name, address, grade and creds.
  Each address is a copy button. The lines are a snapshot of that night, so a
  system that moves or burns by morning still reads as what it paid.
- **Each queued training entry shows how long it takes**, beside its target level,
  at the rate the skill earns now. An entry counts from what is already banked or
  reached by the entries above it, so the rows add up to the whole-queue total.
  A stalled skill says "earns nothing".

### Changed

- **A notification may carry a list under its sentence.** `notifications.details`
  holds structured lines beside the body, written once and never recomposed.
  Only the siphon report uses it so far.

### Migrations

- `2026_09_28_140000_a_message_can_carry_a_list` — **must reach production before
  the nightly siphon job runs.** The deploy worker runs it on pull.

## [0.22.1] — 2026-09-28

The market explains its books, and every page asks the database less than half as much.

### Added

- **A skill book on the market is inspected as the skill it teaches.** Pointing at
  a book's header floats the same card the skills screen uses: description, whether
  it's injected and how far, the attributes, requirements and each level's effect.
  A click or tap opens it as a modal. It works on both the shelf and the players'
  offers. It's the header rather than the whole card, because pressing Buy must buy.

### Changed

- **Every signed-in page runs roughly 60% fewer queries.** The top bar built four
  character sheets per page, one for each read of a resource's maximum. Each sheet
  asked all six modifier sources twice: once to work out the context, then again
  for itself. Now there is one sheet for the bar, and it inherits what the context
  gathered. The market also loads each card's program with the shelf instead of
  one at a time, and the skill roster is read once per request. Market 168 → 51
  queries, character 156 → 65, skills 128 → 53, inventory 102 → 43.

## [0.22.0] — 2026-09-28

Development stops sharing the live database, and the market says what you already have.

### Added

- **The market marks what you already have.** A listing gets the kit's corner tick,
  green for anything you hold a row of anywhere (loose, in a container, fitted,
  running or installed) and cyan for a book whose skill is already injected. Cyan
  wins, because injecting spent the copy. It appears on both the shelf and the
  players' offers.
- **`bin/pull-db`** replaces the local database with a fresh copy of production.
  It only reads the remote, as a `--single-transaction` dump that takes no locks
  production would feel.

### Changed

- **Word already out is not news.** At high heat the bounty roll is certain, so it
  put the same message in the inbox every night. `characters.bounty_word_at`
  remembers the last time, and nobody is rolled again for
  `cyberspace.heat.bounty.cooldown_days` (7). It is a memory on the character
  rather than a gentler curve, and a column rather than the inbox, so clearing
  your messages does not reset it.
- **Development runs on a local copy of production.** The laptop and the live site
  shared one MySQL, so a migration reached the live game the moment it ran on a
  laptop. Every scheduled job also ran twice, once from each scheduler, so bounties,
  siphons and restocks happened twice. `db` is pinned to MySQL 8.0 to match the
  server. From here on, **a migration reaches production through the deploy
  worker**, not from a laptop.

### Migrations

- `2026_09_28_120000_word_goes_out_once_a_while` (already applied on production)

## [0.21.2] — 2026-09-28

A stranger's bad night now tells you what it cost.

### Added

- **Losing a backdoor to somebody else reaches the inbox.** Only the nightly
  ageing roll ever said a door was gone, and only to the holder whose door it
  rolled. A lockdown a stranger caused, a relocation, a burn and every other
  door on a system the roll moved all took access in silence — the first sign
  was a siphon that stopped paying, or a route one hop shorter.
  `Backdoors::loseAllOn()` now messages every holder before it deletes.
- **One sentence whatever the cause.** The holder was not there, and naming a
  stranger's run would tell them about something they could not otherwise know.
- **A burn asks first rather than leaving it to the cascade**, which deletes
  the rows and tells nobody.
- **Nobody hears twice.** The door the roll found is gone before the move
  reaches the others, and a lockdown's later relocation finds nothing left.

## [0.21.1] — 2026-09-24

### Added

- **An entity's own words are editable, where its shelf is.** The shelf screen
  said what an entity sells and nothing about who they are, which is the wrong
  way round for a screen you arrive at in order to decide what they *should*
  sell. `business` — the one-liner, shown wherever an entity is named in
  passing — and `description`, the long form a player reads standing at the
  desk, are both on it now. Until this, a typo in either was unfixable without
  a migration: entities had no editor at all.
- **What they ARE stays with the migration**, and is shown beside the fields as
  fact rather than as a field. `name` is quoted inside stored inbox sentences
  that must never be recomposed; `category` and `interactable` are design
  decisions that change what an entity is rather than how it reads.
- **Blank writes NULL, not an empty string.** A flavour entity has no long form
  at all, and `''` would be a description that renders as nothing while reading
  as present.

## [0.21.0] — 2026-09-24

The shelves can be filled by somebody other than a developer.

### Added

- **The loyalty shelves are authored in the admin.** The shelf shipped a
  release before its editor, so the only way to put something on one was a
  direct write to the database — **content that cannot be authored is content
  only a developer can add**, which is the same wall implants hit earlier
  today. `/admin/entities` lists who owns the grid and how much each is
  holding; `/admin/entities/{entity}` is the shelf.
- **One screen per shelf, not an edit page per row.** An offer is four numbers
  and an item, small enough to edit in place, so the listing IS the editor —
  the call the other three editors make by having no `show` route, arrived at
  from the other side. Each line is its own form, because one form around the
  table would save every row on every press.
- **The blank row is the common row.** A shelf entry is usually an item and a
  price, available at every desk with no gate beyond the desk's own, so the two
  grades and the standing DEFAULT rather than reading as zeroes. The opposite
  makes the common case the one that needs the most typing.
- **Entities are listed and edited, never created or destroyed.** Systems point
  at an owner, so one cannot be deleted without orphaning a district of the
  grid — the roster arrives by migration for the reason the archetypes do. What
  is edited here is what they SELL.
- **The dashboard surfaces the one thing worth surfacing**: entities that deal
  with players and sell them nothing, which is a desk that takes loyalty and
  hands back nothing.

### Changed

- **An offer spans a RANGE of desk grades.** `entity_offers.grade` shipped as a
  single number, which said that a thing kept by the grade 2 desk is a
  different row from the same thing kept by the grade 3 desk — two rows, two
  prices, and nothing to say they are the same offer. Most of what an entity
  sells is available across several of its desks, and the common case should
  not be the one that needs duplicating. `min_grade` and `max_grade` are
  inclusive, and a row kept by one desk alone sets both to the same number.
- **The unique key moves with it**: (entity, grade, item) becomes (entity,
  item). **One offer per item per entity**, because two rows would be two
  ranges and an overlap would be two prices at one desk with nothing to say
  which applies. An entity that wants something cheaper at its better desks
  wants a discount mechanic, which is a different feature.
- **`ItemOptions` comes out of `ArchetypeController`**, which had it private.
  Two editors now want the identical grouped list, and a second copy that
  drifted would file an imprint in one bucket on one screen and another on the
  next.

### Fixed

- **A migration that could not be re-run after failing halfway.**
  `entity_id` carries a foreign key and MySQL refuses to drop the last index
  covering one, so the obvious order — drop the old keys, add the new — got as
  far as dropping the unique and then failed. **DDL auto-commits, so there was
  no transaction to take that back**: the table sat half-changed with the
  migration unrecorded, and re-running it failed differently because the first
  statement had already succeeded. The new index now goes on BEFORE the old one
  comes off, and every step asks whether it still has work to do. The repair is
  in the same file rather than a second one — a migration that cannot be re-run
  is one that has to be fixed by hand on every database it touches.

### Migrations

- `2026_09_24_170000_a_shelf_spans_grades`

## [0.20.0] — 2026-09-24

Loyalty has somewhere to go, and the deploy finishes what it starts.

### Added

- **The loyalty store.** Loyalty has been earnable since missions landed this
  morning with nothing to spend it on — the same trap standing was in before
  missions existed, one step later, in the order the phasing list predicted.
  `entity_offers` is the shelf and **a shelf is an (entity, GRADE) pair** rather
  than a desk: an entity keeps up to five desks and two of the same grade are
  the same shop, so `exchanges` is not referenced at all. The items are
  ordinary catalogue rows, usually with `on_market` false — **a second DOOR
  onto the catalogue rather than a second catalogue**. No stock column: loyalty
  never decays and is earned only by finishing work, so it is the whole
  scarcity.
- **Two gates, asked in order.** `exchanges.min_standing` decides whether an
  agent deals with somebody at all, which is the same gate their work reads —
  a desk that will not employ you does not sell to you either. Then the ROW may
  ask for more, and almost never does: `entity_offers.min_standing` defaults to
  0, and a gruff agent keeping something back is a row with 25 in it.
- **`App\Standing\LoyaltyExchange` is the only thing that spends loyalty**, and
  it lives beside `StandingLedger` rather than in `App\Market` because the two
  currencies never meet: nothing converts loyalty to creds or back. Spending
  moves the books through the LEDGER like everything else, so `standing:verify`
  still finds them equal to the sum of their entries — `LoyaltyStoreTest`
  asserts exactly that after a purchase.
- **Spending earns a little standing**, through the `StoreSpend` reason that
  has sat unused since standing was built. The ratio lives with the store, as
  its config comment always said it would: a thousand loyalty for one point, so
  buying is a courtesy and never a way to BUY standing.

### Changed

- **The deploy worker runs the post-pull steps.** `git_worker.php` pulled and
  stopped there — its own comment said *"Optional: Run build commands here"* —
  so `composer install`, cache clearing and migrations were a human's job on a
  deploy nobody performs, which is a job nobody does. It now decides from the
  diff between the old and new `HEAD`: `composer.lock` moving runs Composer,
  anything under `database/migrations/` runs `migrate --force`, and the caches
  are cleared on every pull because compiled Blade from the previous commit is
  stale on all of them. Dependencies first, then caches, then schema — a
  migration may use a class that arrived with the dependencies, and Artisan
  should read the config that was just pulled. Everything is something `dedlok`
  can do without sudo, which is the whole constraint. **A failed step is logged
  and the loop continues**: a daemon that dies on a bad release stops deploying
  silently, and nobody is watching its output either way.
- **It pulls over itself, which is worth knowing.** The file lives in the repo
  root, and that IS `/var/www/dedlok` on the server, so a deploy overwrites the
  script doing the deploying. PHP reads a script once at startup and this is a
  `while(true)` loop, so **a change lands on disk at the next push and takes
  effect at the next RESTART** — two events, and forgetting the second is how a
  fixed worker goes on behaving like the broken one.
- **The Exchange screen is finished.** Both halves of a desk are real now, and
  `ExchangeTest` asserts *Not yet* appears NOWHERE on it rather than simply
  dropping the old assertion — an assertion quietly deleted is one that stops
  meaning anything.

### Migrations

- `2026_09_24_160000_an_entity_keeps_a_private_shelf`

## [0.19.5] — 2026-09-24

Four string keys that nothing had ever defined, and the test that will not let
a fifth happen quietly.

### Fixed

- **Item cards printed a key at players.** A spec line read
  `4 QFLOPSITEMS.SUMMARY.SEPARATOR3 QB`: the separator was authored under
  `decks` while three readers asked for it under `items`, so `implode()` glued
  the key itself between the numbers. Every program, module and file spec line
  has done this since the first release that had one. There is **one separator
  now, in `items`** — a spec line is a spec line whether it is a deck's
  sockets, a module's measures, a program's costs or a file's numbers — and
  `decks.summary` keeps only the socket count.
- **Two back-links rendered `CYBERSPACE.TITLE` under an arrow**, on the run
  briefing and the Exchange. The key is `cyberspace.map.title`.
- **The skill editor's rank select and listing column read `SKILLS.RANK`.**
  There is no top-level `skills` block at all; both wanted a VALUE rather than
  the field label beside them, so `admin.skills.fields.rank_value` is new.
- **A modifier validation error read `ADMIN.VALIDATION.MODIFIER_TARGET`** in
  place of its reason, turning a careful message into a key.

### Added

- **`StringKeysTest`, because this is the one failure mode `Text::get()` cannot
  announce.** A missing key returns the key — deliberately, so a half-written
  screen still renders — and nothing throws, nothing logs and no test fails. It
  keeps happening until somebody looks at that particular card. So the grep is
  the test: every literal `Text::get()` and `Text::plural()` in `app`,
  `resources`, `routes`, `config` and `database`, resolved against
  `config/strings.php`, with the files asking for anything missing named in the
  failure. A second assertion pins that the scan finds keys at all, since a
  scan that found none would pass while checking nothing.
- Keys built at runtime — `'…intent_'.$purpose->value` — are deliberately out
  of its scope: the enum behind each is its own guarantee.

### Changed

- **A GitHub workflow deploys on a tag push.** `.github/workflows/manual.yml`
  POSTs to the server's own `deploy.php` when any tag reaches origin, so
  `git push origin main --follow-tags` is now the whole deploy for a release
  that needs no migration.

## [0.19.4] — 2026-09-24

The landing page stops borrowing somebody else's button.

### Added

- **The running version, under the wordmark.** Tied to the LOGO's 560px box
  rather than to the content column, so the number sits on the wordmark's right
  edge and the two read as one block — against the content edge it drifted a
  quarter of the page away and looked like furniture. `v` is part of the format
  string; `config/version.php` holds the number and nothing else, which is what
  lets a running container report what it is.

### Changed

- **The supporter link is ours now.** The Ko-Fi widget was a third-party script
  that drew its own button with its own radius and its own magenta, on the one
  page every visitor sees first — and the fonts are self-hosted precisely so
  nothing leaves the origin. It is an ordinary link: same destination, no
  external request, and it works with JavaScript off.
- **It wears the kit's own ember.** `.btn--danger` is an outline that fills on
  hover, which is the form rule 2 allows the accent — and it is the only ember
  on the page, on the only thing there asking for something rather than telling
  you something.
- Both sentences live in `config/strings.php` under `home.*`, not in the view.

## [0.19.3] — 2026-09-24

Somewhere to put the hosting money.

### Added

- **A supporter button on the landing page**, and `NOTICE.md` names Ko-Fi as
  where it goes rather than a Patreon that was never linked. It is the first
  third-party script the application loads — see below.

### Fixed

- **The support link in `NOTICE.md` was written as `[[text|url]]`**, which is
  wiki syntax. Markdown renders that literally, brackets and pipe and all, so
  the link was not a link.

## [0.19.2] — 2026-09-24

The item editor could only ever make two kinds of thing. Now it makes all of
them, and implants have somewhere to be authored.

### Fixed

- **Creating anything but a program or a drug was impossible.** Saving an
  implant came back with six refusals at once — the qflops cost, memory cost,
  storage size, duration, addictive and tolerance gain fields are all
  prohibited. **The form was arguing with itself**: the editor renders every
  panel whatever kind of item is being edited, and the software and drug panels
  default their numbers to ZERO rather than to blank. `filled('0')` is true, so
  those six arrived present on every save and each is `prohibited` for an item
  that is not that kind. A deck module or a plain item would have failed
  identically.
- **A zero on a panel that is off is the form's own default**, and becomes
  null. Anything else was TYPED and stays present, so a compute cost on
  something that is not a program is still refused with a sentence rather than
  quietly discarded — the distinction `positiveCounts()` has always drawn for
  the fourteen socket counters. The first attempt at this fix dropped the
  deliberate value too, and `ProgramAdminTest` refused it, correctly.
- **The suite could not have caught it**, which is the part worth keeping:
  every test in `ItemAdminTest` hand-built a payload without those fields.
  `asTheFormPosts()` is what a browser actually sends, and the three new tests
  around it fail without the fix.

### Changed

- **Implants have a panel of their own**, beside Hardware, Software and Drug.
  The region select lived under *Bulk and containment*, which is where nobody
  looked — an implant region is not bulk, it is what the item IS, and it is the
  only thing that makes an item an implant at all. Each option now carries its
  region's capacity (*Spine — 1 slot*, *Body — 4 slots*), because how many of a
  thing you can have is the question somebody authoring one is really asking.
- **Two things the deploy taught us are written down.** Production and
  development share one database, which is why a release's migrations already
  read as *Ran* on the host before anything was pulled — harmless while every
  migration is additive, and the entry says exactly when it stops being. And
  the scan-file loop is covered by tests and by nobody: a QA pass on the live
  site is deferred deliberately rather than forgotten.

## [0.19.1] — 2026-09-24

Words and records. Nothing a deploy needs beyond a pull.

### Changed

- **The grid is EXPLORED, not swept.** A pass over the player-visible
  vocabulary: systems rather than machines, explored rather than swept, jacked
  in rather than in. The map's lede, several refusals and the two file-name
  lists all read plainer. **Not one test moved**, because the assertions that
  used to quote these sentences were rewritten to read them through
  `Text::get()` in 0.18.0 — which is exactly the pass that change was made for.
- **`TODO.md` stops designing missions and starts recording them.** The entry
  was 355 lines of design for something that now exists; it keeps the shape
  every built entry in that file keeps — a status line, what the design got
  right, what it got wrong, and what is left. What is left is retrieval, the
  loyalty store, listing part of a pile, and every number in
  `config/missions.php`.
- **The mistake is kept on purpose.** `mission_targets.system_id` cascaded, so
  a demolition deleted its own target row and a job whose targets had all been
  burned by other players read as *finished*. The general shape generalises and
  is now written down in both files: **a row that exists to REMEMBER something
  must not cascade from the thing it remembers.**
- **`CLAUDE.md` gains a Missions section**, because that file explains a built
  mechanic and `TODO.md` argues about an unbuilt one. The entities entry moves
  from *none of it built* to *built except the store*, and its phasing list now
  names the one step left — with the warning it carried coming true one step
  later: loyalty is earnable now and has nowhere to go, exactly as standing had
  nowhere to come from before missions existed.

### Fixed

- **A duplicated file name.** The relay word list had gained a second *Routing
  tables*, which would have doubled that name's odds against every other word
  in it.
- **The placement hint contradicted itself**, saying the door is not yours and
  then that you get paid rather than a backdoor — the same half of the sentence
  twice. It says what the entity gets instead.

## [0.19.0] — 2026-09-24

The Exchange stops saying *Not yet*. Agents hand out work, and finishing it
is how an entity comes to think anything of you.

### Added

- **The mission board.** A job is a designated set of systems, an action
  required of each, and a clock — designed in TODO.md the day before and built
  here. **A board is rolled per character and never shared**, refreshed on the
  hour and lazily, which is `Probe::settle()`'s shape rather than a sweep: a
  scheduled refresher would roll boards for every character at every desk they
  have ever walked past, nearly all of which nobody will look at. Nobody can
  take your job, two players at one desk see different work, and **declining
  clears the offer until the hour comes round** — which is what makes turning
  something down a decision rather than a formality. The floor holds even then:
  somebody who declines everything still finds work waiting, and it is not the
  job they threw back.
- **Four actions, and each is a different kind of EVIDENCE.** That is the whole
  reason the list is fixed and short — `mission_targets.satisfied_at` is one
  column filled several ways. A **survey** carries files in and spends them at
  the desk. A **demolition** and a **placement** cannot carry anything, so the
  act records itself where it happens: `Simulator` reaches the ledger inside
  the transaction that burns or installs. **Supply** names no systems at all
  and buys intel by the file, which is the sink scan files have never had and
  the other half of scanning as an occupation.
- **Placement is deliberately not an install.** No `backdoors` row is written
  for the runner: the door belongs to the ENTITY, so it pays nobody nightly, it
  is not a routing hop, it never enters a chain, and none of the four things
  that take a backdoor away has anything to take. The flag on the target row IS
  the record. A run installs once, so **a run spent planting for somebody else
  is a run not spent planting for yourself** — which is the cost that makes the
  type a choice rather than free standing. The system still forgives the visit
  and does not lock down, exactly as `runs.backdoored` already says.
- **Three endings, costing three different things.** Completing pays creds,
  standing and loyalty. Failing charges standing, and **abandoning charges
  exactly the same** — if giving up were free nobody would ever do it, they
  would sit on the job and let it run out, and the game would have charged them
  four hours for the same outcome. **Voiding costs nothing at all**, because a
  machine somebody else burned was never this runner's fault.
- **`missions:expire` sweeps every five minutes**, the cadence the lockdown
  sweep already uses and for its reason: the penalty has to land whether or not
  the player ever comes back. A job whose target has gone voids rather than
  fails even here.
- **A stale scan file says so.** A file's 48 hours ride along in the row's own
  JSON and are read when something asks — that stays lazy. The MESSAGE cannot
  be: a file dies at an arbitrary minute with nobody looking at it. `scans:stale`
  runs hourly and deletes nothing; destroying a worthless file stays the
  player's call. **Said once**, marked in the file's own JSON, because a stale
  file sits in a stash indefinitely and an hourly sweep with no memory would
  repeat itself for ever.
- **A seller hears that their listing sold.** A listing is left up and walked
  away from, so the sale lands on nobody's screen and creds appear with nothing
  to explain them — the inbox's own test, word for word. `Category::Market` is
  the fifth case and `Category::Mission` the sixth, each arriving with the
  sender the enum's docblock demands. The buyer is told nothing: they pressed
  the button, and a flash message already covered it.

### Changed

- **`standing_gain` joins the stat graph**, and deliberately NOT `stats.bases`:
  there is no such thing as a character's standing gain in the abstract, only
  what this job at this desk was worth when it was offered. So the base is
  snapshotted onto the mission row and handed in at the call site the way
  `scan_duration` is, and the first perk or implant that makes somebody better
  at being liked is a modifier `MissionLedger` never learns about. The PENALTY
  is flat on purpose — being bad at consequences is not a thing to be good at.
- **Rewards are snapshotted at the offer**, so retuning `config/missions.php`
  can never change a job somebody already took. The same call
  `market_purchases.unit_price` makes about a price.
- **`CLAUDE.md` gained a *Deploying* section.** Development is entirely in
  Docker and production is not: Apache plus PHP-FPM on bare metal, where the
  FPM user is `dedlok` rather than `www-data`, `open_basedir` lives in the pool
  rather than the vhost, `error_log = /dev/stderr` discards every error, and
  `storage:link` cannot work because `symlink` is disabled. Each of those cost
  part of an outage.

### Fixed

- **A burned target no longer erases itself.** `mission_targets.system_id`
  cascaded on delete, so a demolition deleted its own target row — which meant
  a job whose targets had all been burned by OTHER players had nothing
  outstanding left and read as *finished*, paying out for work nobody did. It
  nulls instead: the row survives its own subject, keeps the address, and
  `stillStands()` says what actually happened. `MissionTest` pins it.

### Migrations

- `2026_09_24_150000_work_done_for_an_entity`

## [0.18.0] — 2026-09-24

Scanning becomes an occupation. A finished read is an object you can carry,
and there is somebody to sell it to.

### Added

- **A finished scan is a file you can hold.** `system_scans` is keyed to a
  character: a read could be looked at, gone stale and spent, but never handed
  to anybody, so scanning was a profession with no income. A complete read now
  **exports** to a Scan File — one unstackable `data` item with the facts as
  JSON on the inventory row, the shape an extracted file already took, and the
  second use of it that made the pattern worth having. Exporting stamps
  `system_scans.used_at`, which had read *"Nothing spends one yet"* since the
  table was created. **That one write is the whole mechanic**: it makes one
  read exactly one object for ever — without it a scanner mints ten files off
  a single pass — and it is why **selling what you know costs you knowing it**,
  since `scopeLive` already skips a spent row and the machine leaves your own
  map as the file is made. A file carries the ADDRESS and never a position: an
  address is meaningless and trading one is expected, which is exactly the rule
  `BookmarkKeeper` exists to protect.
- **Reading one in**, which is the mirror and the half that makes a file worth
  buying. `App\Cyberspace\ScanImporter` spends the file and writes the buyer
  their own scan. **The clock does not restart** — `expires_at` is copied, so a
  file bought at hour 47 is worth an hour, and that is the entire price signal
  on second-hand intel. A partial read of your own is replaced; only a live and
  complete one refuses, because that is the only case with nothing to learn.
- **Runners sell to each other.** `market_offers` is the table of offers
  `items.on_market` was never going to be — that flag is the SEEDER's question,
  and CLAUDE.md has said since the shelf was built that player listings would
  be a table instead. **An offer points at an inventory ROW rather than an
  item**, which is the whole difference and what the scan file finally made
  unavoidable: every one of them is the same catalogue entry and a different
  machine, so *one Scan File, 400 creds* is not an offer anybody could act on.
  Listing is reached from the item's own card, buying from the offer.
- **Listed is a fifth way to be elsewhere.** `InventoryItem::scopeLoose()`
  already excluded four — in a container, fitted, running, installed — and a
  listed row joins them rather than gaining a column of its own, because the
  offer row IS the fact. So a listed module cannot be fitted, a listed program
  cannot be run and a listed file cannot be read in, and none of those three
  learned that a market exists.
- **A buyer takes what they want.** The price is per unit and the listed row is
  the pile: three of twenty leaves seventeen on offer at the same figure, and
  the last one taken deletes the row and the listing together. Asking for more
  than is left is refused rather than quietly reduced, the rule the shelf
  already keeps. **Unstackable rows TRANSFER** — what is written on them is the
  thing being sold, and granting a fresh catalogue copy would hand over a blank
  one — while **stackable rows merge** through `ItemGranter`, which is also
  what stops two piles colliding on `stack_key`. Money and goods move in one
  transaction with the offer and its pile locked inside it, because two buyers
  reaching for the last three of five is every sale on this side of the market.

### Changed

- **`App\Market\OfferBook` is the only thing that moves creds between
  characters.** `Purchaser` only ever spent them into the shop. It deliberately
  writes no `market_purchases` row: that table is DEMAND, read by
  `MarketSeeder` to decide what the shop restocks more of, and what one player
  sold another says nothing about what a shop should order.
- **The item card's *Show on market* button is real**, and the scan dialog's
  footer now says what a finished read is for.
- **Player-visible text is asserted through the config.** Nine tests matched
  wording by hand and broke on a pass of rewriting; what they are about is that
  the right key reaches the right screen, so they read through `Text::get()`
  now.

### Fixed

- **A finished scan could not be shut away.** The reading dialog offered
  *Abandon scan* and *Run in background* whatever state the read was in, and
  both are untrue of one that has finished — there is nothing left to abandon
  and nothing left running. They are swapped for *Close window* and *Save to
  stash* the moment the file is whole, and the note names the file.

### Migrations

- `2026_09_24_120000_a_scan_file_is_a_thing_you_can_hold`
- `2026_09_24_130000_something_spends_a_scan_now`
- `2026_09_24_140000_runners_sell_to_each_other`

## [0.17.0] — 2026-09-23

The icebreakers go on sale, and jacking in stops dying on the first desk
near the portal.

### Added

- **The twelve icebreakers reach the shelf.** They were listed and never
  delivered — `on_market` true with a shelf size of zero, which is the default
  and means nothing arrives at the market here by being forgotten. This is the
  authoring step that was left, and it matters more than most stock: an
  icebreaker is the gate in front of every fight, and `DefencesTest` pins the
  other half of that rule — twenty turns is never enough against the matching
  ICE — so the ladder has to be climbable by BUYING the next piece as well as
  by training for it. Shelves fall as rarity climbs, eight down to one, so the
  top of the ladder is scarce rather than merely expensive.

### Fixed

- **Jacking in returned a 500 wherever a desk stood near the portal**, which
  was every player's first act. The system-type vocabulary is written down in
  SQL three times — `systems`, `system_contacts` and `loot_tables` — and the
  Exchange widened only the first. Every arrival sweeps, a sweep writes a
  sighting for everything in the window, and a Golden Path desk had been
  placed two cells from the way in: so the contacts constraint refused the
  insert and took the arrival down with it. A sighting may be of a desk now,
  built from `SystemType::values()` like the systems one, and `ExchangeTest`
  pins it so the next type to arrive fails in a test rather than on somebody's
  first click. **`loot_tables` is deliberately left narrow** — a table of what
  a kind of system holds has nothing to say about a desk that holds nothing
  and is never raided.

### Migrations

- `2026_09_23_190000_icebreakers_reach_the_shelf`
- `2026_09_23_200000_a_sighting_may_be_of_an_exchange`

## [0.16.0] — 2026-09-23

Somebody is keeping score. Entities own the grid, remember what you did to
them, and a few of them keep a desk you can walk up to.

### Added

- **Entities, and ten of them are somebody.** `corps` is `entities`: a rename
  rather than a second table, because it already owned 2,866 of the 2,867
  systems and a second owner table would have meant every reader asking which
  kind of owner a system had. It gains a `category` — corporation, government,
  military, fringe — and **`interactable`, which is the whole difference
  between scenery and somebody you can work for**, false by default the way
  `archetypes.playable` is. Five existing companies are promoted and five are
  authored: the Bureau of Network Integrity and the Directorate of Revenue
  Enforcement, Joint Signals Command, and two fringe crews, Lockjaw and Golden
  Path. The five new ones take systems of their own, because an entity nobody
  can act against is half a relationship.
- **Standing: what an entity thinks of you.** Two tables and one invariant —
  `entity_books` carries a running total beside `entity_ledger`, an immutable
  log of every change, and **the first is always exactly the sum of the
  second**. It survives clamping because every delta records WHAT LANDED: an
  act against somebody already at the floor writes a delta of zero, which says
  honestly that it changed nothing. `standing:verify` asks out loud and exits
  non-zero if the answer is no. Being seen on their system costs a little,
  taking something costs more, a burn costs twenty raids, and a door of yours
  found costs something in between. **Flavour entities keep no books at all**,
  so the hundred-odd companies nobody can deal with are free to pass.
- **And it fades, proportionally.** Half a percent of what is carried, toward
  neutral from both directions, nightly at 05:20 after the heat comes off. Not
  flat, which at the size of what earns standing would erase a day's work at
  the bottom of the ladder and cost nothing at the top — and a percentage
  gives every rung a soft ceiling without another rule.
- **A reader, in the same release.** The map and the run briefing both say what
  the system's owner makes of you, as a band rather than a figure: *hostile*,
  *hated*, *cold*, *neutral*, *known*, *trusted*, *allied*. Heat spent four
  releases as a number nothing read; this one did not.
- **The Exchange: a system that is not one.** `SystemType::isInteractable()`
  split into four questions the day this arrived, exactly as its own docblock
  predicted it would — an Exchange answers no to being run against, scanned and
  defended, and **yes to being owned**, because whose desk it is is the whole
  reason to stand on one. Thirty-three desks stand across the ten entities,
  deeper for higher grades, and none of them is ever raided, shut or moved.
  Standing on one opens **Missions & Loyalty**, where the entity introduces
  itself, an agent is named, and the desk says what standing it wants before it
  will deal with anybody at all.

### Changed

- **A skillbook is an Eidetic Imprint.** Across the board: the class, the
  relation, the predicate, the picture folder, the refusal, the tests and the
  docs. The NAME needed no data migration, which is the whole argument for
  composing it — an imprint has no name of its own and reads from the skill it
  carries, so one line of `strings` renamed all thirty-nine at once. The SLUG
  is the part with a row and moves by migration, because an item whose slug
  says skillbook and whose name says imprint is one thing with two names.

### Fixed

- **A band lookup collapsed two bands into one.** The standing vocabulary was
  keyed by float floors in config, and PHP casts a float array key to an int —
  so −0.001 and 0.0 were the same key and *cold* was unreachable. It is a list
  of `at`/`band` pairs now, with the reason written beside it.

### Migrations

- `2026_09_23_120000_corps_become_entities`
- `2026_09_23_130000_the_first_entities_worth_dealing_with`
- `2026_09_23_140000_the_new_entities_get_systems`
- `2026_09_23_150000_standing_and_loyalty`
- `2026_09_23_160000_entities_keep_front_desks`
- `2026_09_23_170000_the_grid_admits_an_exchange`
- `2026_09_23_180000_skillbooks_become_eidetic_imprints`

## [0.15.1] — 2026-09-23

The sheet says what is in you, the inbox reads one at a time, and a
flaky test turns out to have been the test.

### Added

- **The character sheet says what is in you.** One card per drug family
  somebody carries anything for: the overdose line as a meter — what is
  working against what this body holds — each dose by name with the strength
  it was frozen at and when it wears off, and the books underneath. A family
  with nothing in it and clean books is left out, because a row of zeroes
  describes a problem nobody has. It was the last thing the drugs loop owed:
  everything overdosing costs was said out loud and nothing let a player see
  how close they were standing to it.
- **The training screen carries the skill card.** What is training and every
  row of the queue open the same card the list below does — on the NAME and
  never the row, which also holds Remove and would otherwise open a card over
  the button somebody was aiming at.
- **The queue says how long all of it takes**, in its summary as well as
  inside, so it reads without opening the panel. Counted per SKILL rather than
  per entry — reaching level 4 costs one sum against what is banked, and
  counting each entry from zero would charge three times for the same points —
  and absent rather than wrong when something in the queue earns nothing.
- **The wordmark is on the front page**, centred above the notice, in four
  widths. A phone fetches 31KB where it used to be handed 797KB.
  `images/logo.png` stays as the master and is not served; the view says how to
  regenerate the four from it.

### Changed

- **Opening a message reads that one.** The inbox's rule — that arriving on
  the screen reads nothing — is about the screen: arriving is not reading
  thirty messages, and clicking this row is reading this one. A category with
  nowhere to lead still reads and stays put, and "mark all read" is still
  there for the rest. The rows are buttons rather than links, because opening
  one writes before it navigates.
- **TODO.md is swept.** The drugs entry loses sixty-five lines of design notes
  for a mechanic that shipped, the siphon's figure is answered, and a new entry
  lists every value no skill moves: 16 of 104 stats have something behind them,
  and the nine untouched attributes, both deck totals, `icebreaker_damage`,
  `siphon_yield` and `heat_decay` are the authoring work that is left.

### Fixed

- **An intermittent test failure, and it was the test.**
  `RunScreenSmokeTest` walked every run action against the real, unpinned die,
  so one full run in several failed on a type error several frames from its
  cause. Both dice are pinned now and every action is walked twice, passing and
  failing — which reproduced it on the spot: with every roll landing, an awake
  ICE empties a starting pool in four turns, and the helper was beginning the
  next run on a character who had been jacked out. It stands them back up, and
  a refusal names itself rather than arriving as a null.

## [0.15.0] — 2026-09-22

The screens grow bars, cards and a search box; every level of a skill is
worth something.

### Added

- **The character sheet has Vitals.** Hitpoints and Synaptic Integrity, each
  against its RESOLVED maximum rather than a stored one, with a bar and the
  effects that moved that maximum listed underneath — an injury, an implant, a
  dose, a skill — one line per layer, read straight off the resolver's own
  breakdown. The same account the run screen's evasion card gives, on the
  screen a player opens most.
- **A skill has a card, and it is the same device an item has.**
  `<x-skill-detail>` in a `<template>`, floated beside the row on hover and
  opened as a modal on a tap, so a skill and an item are inspected the same
  way. It says what the skill is, what governs it, what it requires, what
  knowing it grants at each level, whether the book is still owned, whether it
  has been injected, and how far it has been trained — the level dots, with the
  one being earned breathing in ember.
- **What a modifier DOES is now said in words, once.** `Modifier::effectText()`
  renders "+3% Evasion" from the modifier itself, so a card cannot drift from
  what the resolver does; the skill card and character creation both read it,
  and every stat a skill effect reaches is named, so none of them renders as a
  bare percentage any more.
- **The deck screen measures compute, memory and heat.** Compute and memory are
  what the running set spends against what the deck resolves. Thermal load is
  drawn against the highest LIMIT anything fitted carries — a scale and never a
  chassis rating, since a deck still has none — with each module's warning in
  brass and its limit in ember ticked on the bar. No module with a limit, no
  bar: there is nothing to measure against.
- **The market is one accordion per kind, with a search box.** It matches a
  name, a kind, the skill a book teaches and its flavour, opens the groups that
  match while there is a query, and puts them back when the box empties.
  Filtering in the page rather than a round trip per keystroke: everything on
  the shelf is already there. `data-filter` in the kit, for anything else that
  wants it.
- **A front page.** `NOTICE.md`, rendered from Markdown into a collapsed
  accordion teased by its first 250 characters, over CITY and CYBERSPACE.

### Changed

- **Every level of a skill is worth something.** Seven skills still handed out
  milestones — one grant at level 2, another at 4, and nothing in between,
  which reads on the new card as levels that do nothing. Cyberdecks, Neural
  Sheathing, Overclocking, Thermal Management, Neural Interfacing, Eidetic
  Implants and Advanced System Profiling grant at every level now. Overclocking
  is still a trade: the heat climbs faster than the compute does.
- **The body map is a picture.** The drawn Vitruvian figure is
  `public/images/body.gif`, screened onto the well so its black drops out, with
  the five regions marked on it in `--info`.

### Fixed

- **Signing in landed on a 404.** Fortify was sending an authenticated player
  to `/home`, where nothing is routed. It goes to `/`.
- **Attribute labels spilled under their bars.** `.vital--tight` gives a label
  48px, which is right for "HP" and not for "PERCEPTION"; the meter is
  positioned, so anything that overflowed was painted over. `.vital--wide` is
  the sheet's column.

### Migrations

- `2026_09_22_120000_every_level_of_a_skill_is_worth_something`

## [0.14.0] — 2026-09-22

Heat finally does something, and there is something new to put in your body.

### Added

- **Heat attracts attention.** It has been a number that only went up since the
  trace was built — one point a completed trace, two a burn — and read by
  nothing, which is not a mechanic. Every character carrying any is now rolled
  once a night against the chance somebody out there decides they are worth
  money: the uncertainty floor under five points, climbing in a straight line to
  certain at a hundred. A character carrying none is not rolled at all, because
  a floor is what an attempt keeps rather than something the world owes somebody
  who has done nothing. What lands is a message saying the word is out — there
  is no bounty row, no hunter and no price yet, so it says that and stops where
  the mechanic stops.
- **And heat comes off again, slowly.** Half a point a night, after the bounty
  roll rather than before it: you answer for the heat the night ended with, and
  the cooling is the morning after. Two nights to shake a completed trace, four
  to shake a burn. The rate is a stat, so the first skill that speeds it up —
  a fixer's contacts, a clean identity, a scrubbed record — is a modifier and
  nothing in the nightly pass learns it exists.
- **Drugs.** A drug is an item with a `drugs` row, the shape software and deck
  hardware already have; what a dose does stays on the item's modifiers like an
  implant's. Taking one spends a copy, writes a dose that wears off on its own
  clock, and moves two books kept per family — so somebody can be deep into
  opiates and clean of stimulants. Tolerance cuts what the NEXT dose is worth
  and only its positive half: a drug does less *for* you as it becomes familiar,
  never less *to* you. Addiction past a threshold is a withdrawal you carry
  until you take something of the right family, which clears it without curing
  it: the figure underneath does not move, and only time brings it down. Both
  come off slowly if left alone, tolerance faster than addiction, so a break
  gives the drug its strength back long before it gives the need up.
- **Overdosing, which is allowed, deterministic, and costs three separate
  things.** A body holds so many of one family at once and tolerance widens
  that, so tolerance costs potency and buys headroom. Past the line the ceiling
  comes down as an injury, the body takes ordinary damage a constitution can be
  written against, and past the family's own threshold the heart stops and drops
  you on the waiting screen. Opiates and sedatives do it soonest, a stimulant
  has to work harder at it, and nothing about a psychedelic stops a heart.
  Nothing rolls for any of it: a drug is a known quantity somebody puts into
  their own body on purpose, and the third one hurting *sometimes* is a slot
  machine.

### Changed

- **The resolver still has no idea that one modifier can scale another**, which
  was the open question drugs were blocked on for two releases. Tolerance is
  applied at the DOSE and frozen onto it, so what somebody swallowed is what
  they swallowed — and the arithmetic stayed add, percentage, multiply,
  override, clamp. A dose in effect is a row of its own read by a sixth modifier
  source, because a modifier row names one definition and takes one amount while
  a drug's effects are a list. Two doses are two rows and they add up, which is
  the stacking answer drugs wanted without a rule being written for it.
- **`characters.heat` is a decimal.** What earns heat is whole and what removes
  it is not, so rounding the decay would have meant either no decay at all below
  a point a night or a rate nobody could tune between one and two.
- **A seventh damage origin, and the Crim noticed on its own.** An overdose
  hurts the body through the ordinary damage door, so the archetype perk that
  covers every way of being hurt in the body picked it up without being told —
  which is what the tag sets are for.

### Migrations

- `2026_09_21_140000_heat_cools_by_half_a_point`
- `2026_09_21_150000_a_drug_is_an_item_with_a_family`
- `2026_09_22_090000_a_drug_may_disagree_with_its_family`

## [0.13.0] — 2026-09-21

Four things the player is finally told, and one wall that became a difficulty.

### Added

- **Achievements, and they belong to the account rather than the character.** A
  character is a run of play; what a person did has to survive whatever becomes
  of the one who did it. One running total per metric, one row per unlock, and
  the catalogue is config — so a second achievement over an existing metric is a
  row in a file and no PHP at all. The call sites report what happened and never
  name an achievement, which is why the six places already counting something —
  creds raided, ICE defeated, systems scanned, systems burned, the longest chain
  held, siphon income — needed one line each. An open achievement is on the page
  from the first day as something to go and do; a secret one is not there at all
  until it is won.
- **The notification centre: what happened while nobody was looking.** That is
  the whole test for what belongs in it. A flash message already covers
  everything you just did, and until now nothing carried the other half — a read
  that finished an hour after you walked away, a backdoor the nightly roll took
  off you, a level the clock crossed while the tab was shut, an achievement that
  unlocked as a side effect of something else. All four send now. Three separate
  TODO entries stood on this table not existing.
- **Messages carry a category and deliberately not a priority.** A category says
  where a message came from — Cyberspace, Training, Achievement — and never how
  loud it is, because anything that needs acting on this instant belongs on the
  screen where the acting happens. The rail carries the unread count on the icon
  so it is legible while the rail is shut, and reading them is its own act: a
  badge cleared by arriving is a badge cleared for messages nobody read.
- **`NotifyOfSkillLevel` is the first listener in the game.** `SkillLevelReached`
  has been dispatched since the training queue was built with nothing subscribed
  to it.
- **Plan a route to a skill you want.** Name the level you are after and the
  prerequisites, and the levels in between, are worked out and queued in order —
  nine presses of a button in the order a player would otherwise work out on
  paper. The planner decides the order and the count and nothing else; every
  entry still goes through the one writer, so the validator judges the result
  exactly as it judges a hand-built queue. Stopping part-way is the ordinary
  outcome and says so: the queue holds five, and a route can run through a book
  you have never spent.

### Changed

- **A scan goes round until it has the picture or the file goes stale.** The
  five-pass budget is gone. It was stopping the ordinary case — a machine you
  could read, given the minutes — at a half-filled file and a refusal telling you
  to come back in two days, which is a wait rather than a decision. The expiry
  window is the budget now, so a failed scan still costs the window and an
  analyser's compute rather than being ground until it succeeds, and the window
  is what bounds the catch-up walk. The `scan_passes` stat retires with the rule
  it existed for; what a better analyser buys is the same picture sooner.
- **A machine's operating system is a roll rather than a wall.** It used to be a
  verdict taken at the door: hold the skill at level 1, or every action against
  that machine failed for ever. An unfamiliar system was impassable instead of
  expensive, and levels 2 to 5 were worth nothing at all on a run. The level is
  the check value now — 4.7% at no skill, 48% at level 2, the certainty floor at
  level 5 — off a table rather than a multiplier, because the first level is
  worth nearly twice the fourth. No grade term: a machine's grade already decides
  which system it runs. A landed roll opens the machine for the rest of that run.
- **Only the core asks.** Extracting, installing a backdoor and burning are the
  three actions that reach for the machine itself. Cutting at an ICE no longer
  asks at all — an icebreaker against an ICE is one program against another and
  needs no vocabulary.
- **The odds of reaching the core are on the run screen.** The failure line has
  to stay silent, because naming the operating system would hand over a scan fact
  for one click — so a streak of failures had nothing to explain it, and an
  unexplained streak is indistinguishable from a bug. The screen quotes the
  chance the roll will use and never the reason behind it. Same bargain the
  egress panel already makes.
- **`runs.os_locked` keeps its name and changes its meaning**, from a verdict
  about the runner to the state of the door, so nothing is migrated but the
  column's comment.

### Migrations

- `2026_09_21_100000_an_achievement_belongs_to_the_account`
- `2026_09_21_110000_a_read_goes_round_until_the_file_goes_stale`
- `2026_09_21_120000_something_happened_while_nobody_was_looking`
- `2026_09_21_130000_the_operating_system_is_a_roll_not_a_wall`

## [0.12.2] — 2026-09-21

The last run purpose, and the last route nothing had ever posted to.

### Added

- **Burn — destroy the machine.** The third intention on the briefing has been a
  disabled button under a *Not built yet* tag since the screen shipped; it is a
  real submit now, and a burn is an act you spend a turn on like any other. A
  core touch, so it takes the IDS's second look exactly as extracting and
  installing do, and it fails in one sentence for all three of its reasons — a
  machine already gone, an operating system you cannot speak, or a purpose that
  is not this one — because the OS gate must not announce itself here either.
  When it lands it is the last turn of the run: nothing is left to strike back,
  advance a trace or be shut, so the run ends Completed.
- **What a burn leaves behind is nothing.** The machine is deleted outright —
  the one thing in the game that deletes a system. Its address goes out of
  existence with it, every backdoor into it cascades away, its contents go, and
  the position it held is simply empty until a seeding pass puts something new
  there. No loot, because a burn takes nothing; no raid counted, because the
  books it would be read from are ash.
- **Anybody else standing in it is thrown out**, for one flat point of Synaptic
  Integrity and nothing else. That is the price of a shared world, and the same
  cancellation a lockdown already dealt: a machine you are standing in is a
  machine somebody else can burn.
- **Lockdown's fourth trigger, by needing no code at all.** The design table has
  always listed burn as a reason a machine shuts. It resolves itself instead —
  the row is gone by the time the run ends, so the question "does the machine
  now know" finds nothing to ask. A burn leaves no lockdown even when the IDS
  fired and the ICE woke, and nothing had to be told to skip it.

### Changed

- **`run.start` is tested over HTTP**, which it never had been. It was the only
  route in the application with no request-level test under any name — the
  writer behind it was covered and the screen in front of it was covered, and
  nothing in between ever posted the form. Every purpose, both backdoor kinds,
  six refusals that have to arrive as a message rather than a server error.
- **The consequence a lockdown and a burn share now lives once.** Throwing
  everybody out of a machine that stopped being somewhere to stand was a private
  method on the lockdown; it is on the simulator now, where endings are
  resolved, so the order every bad ending follows has one place to drift from
  instead of two.
- **Three documents that were wrong rather than merely stale.** CYBERSPACE.md
  said phase 3 was under way and everything past it was still design, three
  phases after that stopped being true. TODO still described the database
  account as holding every privilege on every schema, which was fixed on the
  server and corrected in CLAUDE.md a release ago. And the note asking someone
  to raise the PHPStan level outlived the morning it was raised.

### Fixed

- **A test that passed against deliberately broken code.** The new route test
  was written expecting a run to be refused for a deck that stopped running, and
  it passed with the refusal path removed — because that refusal cannot be
  reached from this route at all. The readiness net runs first on every
  jacked-in request and turns the request away with its own message, so the
  controller's branch only ever answers callers that are not requests. Not a bug
  in the game; a bug in the test, and exactly the kind the pass exists to catch.

## [0.12.1] — 2026-09-21

Documentation only, and a trap written down.

### Changed

- **The database account is no longer the risk CLAUDE.md said it was.** That
  section was written against a `SHOW GRANTS` from 2026-09-01 and had been
  wrong since somebody acted on it. Re-verified: global privileges are `USAGE`
  only, `ALL PRIVILEGES` is scoped to `cyberpunk`.\* and nowhere else, and the
  two unrelated production schemas are out of the account's view entirely. That
  retires the worst line in the file — `FILE` plus an injection reading and
  writing on the database server. Two findings still stand and are now the
  whole section: the host mask is `%`, and the connection is unencrypted.
- **An unquoted `#` in `.env` truncates the value at that character**, which is
  written down because it cost an afternoon: a sixteen-character password
  reached MySQL as three, and the `1045 Access denied` that came back was
  accurate, unhelpful and about the last thing anyone suspects. Single quotes
  and not double, since a double-quoted value interpolates and that password
  also had a `$` in it. The one-liner that compares the file against what the
  framework actually loaded is in there with it.

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

[Unreleased]: https://github.com/nahkampf/dedlok/compare/v0.75.2...HEAD
[0.75.2]: https://github.com/nahkampf/dedlok/compare/v0.75.1...v0.75.2
[0.75.1]: https://github.com/nahkampf/dedlok/compare/v0.75.0...v0.75.1
[0.75.0]: https://github.com/nahkampf/dedlok/compare/v0.74.0...v0.75.0
[0.74.0]: https://github.com/nahkampf/dedlok/compare/v0.73.1...v0.74.0
[0.73.1]: https://github.com/nahkampf/dedlok/compare/v0.73.0...v0.73.1
[0.73.0]: https://github.com/nahkampf/dedlok/compare/v0.72.1...v0.73.0
[0.72.1]: https://github.com/nahkampf/dedlok/compare/v0.72.0...v0.72.1
[0.72.0]: https://github.com/nahkampf/dedlok/compare/v0.71.2...v0.72.0
[0.71.2]: https://github.com/nahkampf/dedlok/compare/v0.71.1...v0.71.2
[0.71.1]: https://github.com/nahkampf/dedlok/compare/v0.71.0...v0.71.1
[0.71.0]: https://github.com/nahkampf/dedlok/compare/v0.70.0...v0.71.0
[0.70.0]: https://github.com/nahkampf/dedlok/compare/v0.69.2...v0.70.0
[0.69.2]: https://github.com/nahkampf/dedlok/compare/v0.69.1...v0.69.2
[0.69.1]: https://github.com/nahkampf/dedlok/compare/v0.69.0...v0.69.1
[0.69.0]: https://github.com/nahkampf/dedlok/compare/v0.68.1...v0.69.0
[0.68.1]: https://github.com/nahkampf/dedlok/compare/v0.68.0...v0.68.1
[0.68.0]: https://github.com/nahkampf/dedlok/compare/v0.67.0...v0.68.0
[0.67.0]: https://github.com/nahkampf/dedlok/compare/v0.66.3...v0.67.0
[0.66.3]: https://github.com/nahkampf/dedlok/compare/v0.66.2...v0.66.3
[0.66.2]: https://github.com/nahkampf/dedlok/compare/v0.66.1...v0.66.2
[0.66.1]: https://github.com/nahkampf/dedlok/compare/v0.66.0...v0.66.1
[0.66.0]: https://github.com/nahkampf/dedlok/compare/v0.65.0...v0.66.0
[0.65.0]: https://github.com/nahkampf/dedlok/compare/v0.64.1...v0.65.0
[0.64.1]: https://github.com/nahkampf/dedlok/compare/v0.64.0...v0.64.1
[0.64.0]: https://github.com/nahkampf/dedlok/compare/v0.63.2...v0.64.0
[0.63.2]: https://github.com/nahkampf/dedlok/compare/v0.63.1...v0.63.2
[0.63.1]: https://github.com/nahkampf/dedlok/compare/v0.63.0...v0.63.1
[0.63.0]: https://github.com/nahkampf/dedlok/compare/v0.62.2...v0.63.0
[0.62.2]: https://github.com/nahkampf/dedlok/compare/v0.62.1...v0.62.2
[0.62.1]: https://github.com/nahkampf/dedlok/compare/v0.62.0...v0.62.1
[0.62.0]: https://github.com/nahkampf/dedlok/compare/v0.61.1...v0.62.0
[0.61.1]: https://github.com/nahkampf/dedlok/compare/v0.61.0...v0.61.1
[0.61.0]: https://github.com/nahkampf/dedlok/compare/v0.60.0...v0.61.0
[0.60.0]: https://github.com/nahkampf/dedlok/compare/v0.59.0...v0.60.0
[0.59.0]: https://github.com/nahkampf/dedlok/compare/v0.58.2...v0.59.0
[0.58.2]: https://github.com/nahkampf/dedlok/compare/v0.58.1...v0.58.2
[0.58.1]: https://github.com/nahkampf/dedlok/compare/v0.58.0...v0.58.1
[0.58.0]: https://github.com/nahkampf/dedlok/compare/v0.57.0...v0.58.0
[0.57.0]: https://github.com/nahkampf/dedlok/compare/v0.56.1...v0.57.0
[0.56.1]: https://github.com/nahkampf/dedlok/compare/v0.56.0...v0.56.1
[0.56.0]: https://github.com/nahkampf/dedlok/compare/v0.55.1...v0.56.0
[0.55.1]: https://github.com/nahkampf/dedlok/compare/v0.55.0...v0.55.1
[0.55.0]: https://github.com/nahkampf/dedlok/compare/v0.54.0...v0.55.0
[0.54.0]: https://github.com/nahkampf/dedlok/compare/v0.53.2...v0.54.0
[0.53.2]: https://github.com/nahkampf/dedlok/compare/v0.53.1...v0.53.2
[0.53.1]: https://github.com/nahkampf/dedlok/compare/v0.53.0...v0.53.1
[0.53.0]: https://github.com/nahkampf/dedlok/compare/v0.52.0...v0.53.0
[0.52.0]: https://github.com/nahkampf/dedlok/compare/v0.51.0...v0.52.0
[0.51.0]: https://github.com/nahkampf/dedlok/compare/v0.50.0...v0.51.0
[0.50.0]: https://github.com/nahkampf/dedlok/compare/v0.49.2...v0.50.0
[0.49.2]: https://github.com/nahkampf/dedlok/compare/v0.49.1...v0.49.2
[0.49.1]: https://github.com/nahkampf/dedlok/compare/v0.49.0...v0.49.1
[0.49.0]: https://github.com/nahkampf/dedlok/compare/v0.48.0...v0.49.0
[0.48.0]: https://github.com/nahkampf/dedlok/compare/v0.47.0...v0.48.0
[0.47.0]: https://github.com/nahkampf/dedlok/compare/v0.46.1...v0.47.0
[0.46.1]: https://github.com/nahkampf/dedlok/compare/v0.46.0...v0.46.1
[0.46.0]: https://github.com/nahkampf/dedlok/compare/v0.45.0...v0.46.0
[0.45.0]: https://github.com/nahkampf/dedlok/compare/v0.44.0...v0.45.0
[0.44.0]: https://github.com/nahkampf/dedlok/compare/v0.43.0...v0.44.0
[0.43.0]: https://github.com/nahkampf/dedlok/compare/v0.42.3...v0.43.0
[0.42.3]: https://github.com/nahkampf/dedlok/compare/v0.42.1...v0.42.3
[0.42.2]: https://github.com/nahkampf/dedlok/compare/v0.42.1...v0.42.2
[0.42.1]: https://github.com/nahkampf/dedlok/compare/v0.42.0...v0.42.1
[0.42.0]: https://github.com/nahkampf/dedlok/compare/v0.41.0...v0.42.0
[0.41.0]: https://github.com/nahkampf/dedlok/compare/v0.40.0...v0.41.0
[0.40.0]: https://github.com/nahkampf/dedlok/compare/v0.39.0...v0.40.0
[0.39.0]: https://github.com/nahkampf/dedlok/compare/v0.38.0...v0.39.0
[0.38.0]: https://github.com/nahkampf/dedlok/compare/v0.37.0...v0.38.0
[0.37.0]: https://github.com/nahkampf/dedlok/compare/v0.36.1...v0.37.0
[0.36.1]: https://github.com/nahkampf/dedlok/compare/v0.36.0...v0.36.1
[0.36.0]: https://github.com/nahkampf/dedlok/compare/v0.35.0...v0.36.0
[0.35.0]: https://github.com/nahkampf/dedlok/compare/v0.34.1...v0.35.0
[0.34.1]: https://github.com/nahkampf/dedlok/compare/v0.34.0...v0.34.1
[0.34.0]: https://github.com/nahkampf/dedlok/compare/v0.33.0...v0.34.0
[0.33.0]: https://github.com/nahkampf/dedlok/compare/v0.32.1...v0.33.0
[0.32.1]: https://github.com/nahkampf/dedlok/compare/v0.32.0...v0.32.1
[0.32.0]: https://github.com/nahkampf/dedlok/compare/v0.31.0...v0.32.0
[0.31.0]: https://github.com/nahkampf/dedlok/compare/v0.30.0...v0.31.0
[0.30.0]: https://github.com/nahkampf/dedlok/compare/v0.29.0...v0.30.0
[0.29.0]: https://github.com/nahkampf/dedlok/compare/v0.28.0...v0.29.0
[0.28.0]: https://github.com/nahkampf/dedlok/compare/v0.27.0...v0.28.0
[0.27.0]: https://github.com/nahkampf/dedlok/compare/v0.26.1...v0.27.0
[0.26.1]: https://github.com/nahkampf/dedlok/compare/v0.26.0...v0.26.1
[0.26.0]: https://github.com/nahkampf/dedlok/compare/v0.25.1...v0.26.0
[0.25.1]: https://github.com/nahkampf/dedlok/compare/v0.25.0...v0.25.1
[0.25.0]: https://github.com/nahkampf/dedlok/compare/v0.24.0...v0.25.0
[0.24.0]: https://github.com/nahkampf/dedlok/compare/v0.23.1...v0.24.0
[0.23.1]: https://github.com/nahkampf/dedlok/compare/v0.23.0...v0.23.1
[0.23.0]: https://github.com/nahkampf/dedlok/compare/v0.22.1...v0.23.0
[0.22.1]: https://github.com/nahkampf/dedlok/compare/v0.22.0...v0.22.1
[0.22.0]: https://github.com/nahkampf/dedlok/compare/v0.21.2...v0.22.0
[0.21.2]: https://github.com/nahkampf/dedlok/compare/v0.21.1...v0.21.2
[0.21.1]: https://github.com/nahkampf/dedlok/compare/v0.21.0...v0.21.1
[0.21.0]: https://github.com/nahkampf/dedlok/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/nahkampf/dedlok/compare/v0.19.5...v0.20.0
[0.19.5]: https://github.com/nahkampf/dedlok/compare/v0.19.4...v0.19.5
[0.19.4]: https://github.com/nahkampf/dedlok/compare/v0.19.3...v0.19.4
[0.19.3]: https://github.com/nahkampf/dedlok/compare/v0.19.2...v0.19.3
[0.19.2]: https://github.com/nahkampf/dedlok/compare/v0.19.1...v0.19.2
[0.19.1]: https://github.com/nahkampf/dedlok/compare/v0.19.0...v0.19.1
[0.19.0]: https://github.com/nahkampf/dedlok/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/nahkampf/dedlok/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/nahkampf/dedlok/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/nahkampf/dedlok/compare/v0.15.1...v0.16.0
[0.15.1]: https://github.com/nahkampf/dedlok/compare/v0.15.0...v0.15.1
[0.15.0]: https://github.com/nahkampf/dedlok/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/nahkampf/dedlok/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/nahkampf/dedlok/compare/v0.12.2...v0.13.0
[0.12.2]: https://github.com/nahkampf/dedlok/compare/v0.12.1...v0.12.2
[0.12.1]: https://github.com/nahkampf/dedlok/compare/v0.12.0...v0.12.1
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
