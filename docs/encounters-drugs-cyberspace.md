# Encounters, drugs, consumables, implants, cyberspace rules

_Split out of CLAUDE.md. Heading levels are as they were there._

### Narrative encounters

**`docs/CITY.md`, *Encounters*, is the document.** Something that happens to a character in the City:
a graph of nodes and choices with outcomes as rows, authored at `admin/encounters`, nothing seeded.

- **Data, never PHP.** A new kind of effect is an `OutcomeKind` case and a branch in
  `EncounterOutcomes`, and **every kind goes through the writer that already owns it**
  (`Wallet`, `ItemGranter`, `StandingLedger`, `Flags`, `InjuryLedger`, `DamageResolver`,
  `DrugTaker`). **A dose is grant-then-take** in the runner's transaction, so tolerance,
  addiction and overdose all apply; the editor refuses a non-drug item. **Injuries are the two
  `OutcomeKind::INJURIES`** — the drug withdrawals are `DrugLedger`'s alone.
- **An outcome belongs to a button OR a screen** (`choice_id` / `node_id`, exactly one, held by the
  request). A screen's apply on arrival, every time, through `EncounterRunner::arrive()`; the floor
  is asked once, after everything.
- **The editor has a ✕ on every screen, choice and outcome** (`data-remove-row`, `ui-kit.js`): it removes the row and what is nested in it
  from the form, and nothing is deleted until the form is saved (a save replaces the graph, so a row not posted is gone). Rows are
  NOT renumbered, because the request reads them by key; `data-add-row` therefore takes the next index past every one ever used
  (`data-next-index`), or a "+" after a ✕ would reuse an index a row still has. Removing the entry screen is refused on save (exactly one entry).
- **Nodes are reached by `node_key`, never by id**, so the editor replaces them wholesale on
  every save and nobody mid-encounter is stranded. `EncounterEditor` is the only writer of the
  graph; `Encounters::open()` the only door in; `EncounterRunner` the only thing that answers or
  closes one.
- **No CHECK on a cascading FK** (MySQL error 3823; SQLite will not tell you). Rules like "an
  item outcome has an item" live in `EncounterRequest`. **Run a new migration on the MySQL
  container, not only the suite.**
- **Rarity is the pool.** The draw is out of `max(eligible weights, city.encounter.pool)`, so a
  lone low-weight encounter is rare, not certain.
- **The spawn roll is not a check** (a flat percentile, injected and container-bound), its chance
  the resolved `encounter_chance` stat. **You cannot jack in during any City activity**
  (`Navigator::jackIn()`), so an encounter never opens beside a run; `canHappenTo()` still guards
  against jacked-in, run, hunt, encounter and floor.
- **A travel hit stops the trip** (`stop()` with `step + 1`: the character is already in the
  district reached); an explore pass settles its discovery first.
- **`EnsureEncounterIsResolved` owns the screen**, the hunt lock's shape. Encounters wait for the
  player. A choice must belong to the CURRENT node; cost is charged before the roll.
- **A choice may be a FIGHT** (`encounter_choices.fight_npc_id` + `win_key`/`lose_key`/`flee_key`): it opens a
  fight through `Fights::open()` (BEFORE charging the cost) and the encounter row stays. When the fight ends
  `EncounterRunner::afterFight()` moves it to the win, loss or nobody-won screen (`flee_key` covers the player
  getting away AND the opponent doing so; no gains) and applies that screen's arrival outcomes, which is where a
  reward lives. The destinations are keys snapshotted onto the fight, never a choice id. A fight choice has no
  roll, next screen or outcomes of its own, and restricts the NPC's deletion. **`EnsureEncounterIsResolved` stands
  down while a fight is live**, or the two locks redirect to each other for ever.
- **Three more doors are cyberspace's** (`grid_step`, `system`, `run`), with prerequisites, a clock that stops, and `run_end`;
  see `docs/ENCOUNTERS_GRID.md` for what is built. **Prerequisites** (`encounter_prerequisites`, `PrerequisiteJudge`, an editor panel) are a
  list of conditions an encounter needs HELD before it is drawn (flag, items, scan files, creds, skill, standing, district), each
  negatable, none being no condition; held and never spent. **`EncounterPause`** is the one place the player's clocks stop while a grid
  encounter is open: new clocks read it. The paragraph below is the first of them, kept as it was.
- **A fourth door is cyberspace's: `EncounterStart::GridStep`** (`starts_on = grid_step`; design and what is not built in
  `docs/ENCOUNTERS_GRID.md`). **The realm is the door's** (`isGrid()`), never a column. `Navigator::arrive()` rolls it after the hunter, in its
  own write on the LOCKED character, never on the jack-in; `canHappenTo($character, $start)` wants somebody jacked in and not in a run for a
  grid door and the opposite for a City one. Base chance `cyberspace.encounter.grid_step_chance`. `EncounterController` closes onto the
  map for somebody jacked in. A player in one is held by the same lock, so they cannot move or jack out until it is answered.
- **The button shows a band word**, the figure in `title`.

### Drugs

**A drug is an item with a `drugs` row**, the same shape software and deck hardware have.
Nothing on `items` says an item is one. What a dose DOES stays on `items.modifiers` like an
implant's; the row says which family, how long, and what it costs the books.

- **`App\Drugs\Family` is a fixed vocabulary and not rows** — stimulant, opiate, sedative,
  psychedelic, nootropic. **A new family is a case and a withdrawal**: each needs its own
  `config/effects.php` definition, because coming off a stimulant is not coming off an
  opiate and there is nothing generic to say about either.
- **`Medical` is the sixth family (2026-10-07): treatment that acts for a while.** A dose that carries `hitpoints_regen`
  (like Rewire carries `synaptic_regen`) is a drug and not a `consumables` row, because a dose in effect is exactly what
  `character_doses` is for. Decided: **it overdoses like a stimulant** (`drugs.overdose.families.medical`: 4 hit points per
  dose over the line, the heart stops at 3 over), **it is never addictive** (`Family::addicts()`: `DrugLedger::record()` never
  raises its addiction whatever the row says, `reconcile()` returns at once so no `effects.withdrawal_medical` exists or is
  needed, and the item editor refuses a non-zero `addictive` on one), and tolerance is per drug as for every other family.
- **A drug may be FIGHT-ONLY** (`drugs.fight_only`, 2026-10-07): a setting on the drug and not a second kind, so it keeps its
  family, books, tolerance and overdose. Its length is `duration_turns` (rounds; `duration_minutes` is 0 and unread), it may
  carry `turn_hitpoints` (signed: restored at the start of each round, scaled by the dose's potency, or lost as the drug's
  price, flat and not mitigable like an overdose's injury) and any effect rows and a consumable half like any drug.
  `DrugTaker::take()` refuses it outside a fight (`DoseRefusal::OnlyInAFight`; the stash card shows a *Fights only* tag
  instead of Take). **Using one is the round's whole action** (`ActionKind::Use`, belted rows only): the engine just spends the
  turn, then `Fights::act()` takes the dose through `DrugTaker` with `inFight: true`, so the enemy acts first and the dose
  works **from the next round**. The dose is a `character_doses` row with `turns_left` (NULL = an ordinary dose); it is in
  effect while `turns_left > 0` (`Dose::scopeInEffect()` and `ActiveDoses`), counts down once a round after the round is
  played, and is deleted when the fight ends however it ended (`expires_at` is a one-day backstop only). **The player's
  side of the snapshot is REBUILT from the character every round** (aim, damage, defence, pursuit; `CombatantBuilder::
  forCharacter()`, keeping only the opponent's statuses), which is how a dose's stat effects reach a fight that began
  before it — and why a weapon that is no longer the one the fight began with throws. **In a fight `DrugTaker` never asks
  the waiting room**: an overdose or a flatline leaves the hit points at 0 and `Fights` ends the fight as a loss
  (`Result::ADown`), whose `finish()` asks the floor.
- **THE CLOCK STOPS IN A FIGHT** (2026-10-07), so an open fight cannot be waited in: `characters:regenerate` (SI, HP, the mend)
  and `drugs:withdraw` skip a character with `current_fight_id`; `ActiveDoses` judges an ordinary dose as at the moment the
  fight opened (so neither its benefit lapses nor its cost is waited out), and `Fights::finish()` pushes every such dose's
  `expires_at` forward by the fight's length. **Only fights**: a cyberspace run is real-time by design (scans, lockdowns, hunters)
  and its clocks are NOT paused. Overdose capacity and `hasInThem()` still read the real clock (`scopeInEffect`).
- **The books are per (character, family) and a missing row means clean.** Somebody can be
  deep into opiates and clean of stimulants. `App\Drugs\DrugLedger` is the only writer of
  `character_drug_use`, and the only thing that writes or clears a withdrawal.
- **A dose in effect is its OWN row**, because `character_modifiers` cannot express it: a
  modifier row names a definition and takes one amount, and a drug's effects are a list on
  the item. So `character_doses` points at the item and
  **`App\Stats\Sources\ActiveDoses` is the sixth modifier source** — the resolver learned
  nothing new for it. **Two doses are two rows and they add up**, which is `Stacking::Sum`
  by construction rather than by a rule anybody wrote.
- **TOLERANCE IS A PROPERTY OF THE DOSE.** It is read when the dose is taken and frozen
  onto the row as `potency`. A band that climbs an hour later does not reach back into
  somebody's blood — and that decision is the whole reason this mechanic needs nothing
  from `Resolver`, which still has no idea that one modifier can scale another.
- **Only the POSITIVE amounts are scaled.** Tolerance means a drug does less *for* you,
  never less *to* you; softening the cost too would make tolerance a pure good.
- **`App\Drugs\DrugTaker` is the only door** between an item and somebody's blood. One
  transaction: the copy is spent, the dose begins, the books move, the injury lands.
- **Withdrawal is written, not derived**, and **taking one clears it without curing it** —
  the row goes for as long as something of that family is in them and the figure underneath
  does not move. Only time brings it down. **`drugs:withdraw` sweeps every five minutes**,
  for `cyberspace:settle`'s reason: taking a dose has somebody pressing a button, a dose
  WEARING OFF has nobody.
- **Overdosing is allowed and it is DETERMINISTIC.** A body holds `drugs.overdose.base`
  doses of one family at once and tolerance widens that, so **tolerance costs potency and
  buys headroom**. Capacity is counted against what is ALREADY working, so the dose being
  taken is the one that breaks it. Nothing rolls: a drug is a known quantity somebody puts
  into their own body on purpose, and the third one hurting *sometimes* is a slot machine.
- **Too much costs three separate things, and they are different KINDS of thing.** The
  ceiling comes down as an **injury** (`neural_damage`, not mitigable — mitigation has
  nothing to say about a ceiling). The body takes **ordinary damage** through
  `DamageResolver` under `DamageOrigin::Overdose`, so a constitution is written against
  `all_of: ['damage', 'overdose']`. And past the family's line the **heart stops**.
- **A flatline is not big damage.** The body is emptied outright and does not come through
  the damage door — a resistance is about how hard something lands, and a stopped heart is
  not a matter of degree. The same call the ICE attack tag of that name makes.
  `WaitingRoom::checkFloor()` is asked either way, which is the rule every bad ending
  follows.
- **Lethality belongs to the FAMILY**, in `drugs.overdose.families`: opiates and sedatives
  stop you at two past the line, a stimulant at three, a psychedelic or a nootropic never
  (`lethal_at` null). **`drugs.lethal_at` is the per-drug override** and is nearly always NULL — null
  asks the family and does not mean never, so a survivable drug in a lethal family sets a
  high number.
- **`drugs:metabolise` takes a day off both figures nightly**, tolerance faster than
  addiction — a break gives the drug its strength back long before it gives the need up.
  Books that reach nothing on both are deleted; absent and zero are the same answer.
- **`App\Drugs\DrugEditor` is the only writer of the `drugs` table** and clears the side an
  item stopped being, exactly as `ProgramEditor` does. The item editor has a Drug panel and
  `ItemRequest` refuses a drug that is also software, deck hardware or an imprint.

### Consumables

**A consumable is an item with a `consumables` row** — the same shape a drug, software and a weapon have — and it does
something ONCE, AT ONCE, when a copy is used: a medkit. **It is not a drug and it is not a modifier**: a drug is an effect
that lasts and is read by the resolver while it lasts, and the effects editor's targets are resolved stats, which is
why it had nothing to offer a medkit. A consumable changes a STORED number a single time.

- **`App\Items\UseEffect` is a fixed vocabulary**; `heal_hitpoints` is the first (restores current hit points, via
  `Character::recover()`, so never past the maximum as it stands — mending an injury is a clinic's job). A new kind is a
  case, a label, and an arm of `would()` and `apply()`. `consumables.effects` is a list of `{kind, amount}`.
- **`App\Items\Consumer` is the only thing that uses one**, in one transaction with the character locked: the copy is
  spent (the last takes its row) and the effects land together. **A use that would do nothing is REFUSED and the copy
  kept** (`UseRefusal::NothingToDo`, as a service that would do nothing is by `LoyaltyExchange`), so a medkit is never
  wasted at full health. It refuses a stranger's row, a non-consumable, a copy that is not to hand (in a container, fitted),
  and **a live fight** (`InAFight`: docs/COMBAT.md lists items mid-fight as later).
- **`drug_<family>_tolerance` is a use effect too** (six cases, one per `Drugs\Family`): it takes that many points off the
  family's stored tolerance through `DrugLedger::lowerTolerance()`, floored at nothing, and a pure consumable at zero tolerance is refused
  as `NothingToDo`. A drug can carry it (applied after the dose's own gain, in the same transaction).
- **`App\Items\ConsumableEditor` is the only writer of the table and clears the side an item stopped being.** The item
  editor has a Consumable panel with one whole-number field per kind (blank is none; zero and fractions are errors), and
  `ItemRequest` makes a consumable none of the other kinds (software, hardware, imprint, implant, armour, weapon,
  ammunition) except a drug.
- **A drug may ALSO be a consumable** (decided 2026-10-07, any family): a trauma drip that heals 15 now and mends for ten
  minutes. It is TAKEN and never used: `DrugTaker` applies the instant effects in the dose's transaction (on the locked
  character, after the books move and BEFORE any overdose harm, so a lethal dose is not undone by its own medicine), the card
  offers only *Take*, `Consumer` refuses it as `NotUsable`, and `DoseResult::$instant` lets the flash say what was restored.
  **Tolerance never touches the instant part** (it scales only what a dose does while it lasts), and at full health the dose
  is still taken, the instant part simply restoring 0 (`NothingToDo` is for pure consumables).
- **Where it shows up:** the stash card (*Use*, with what it does in the button's title, and *Put on belt*), the route
  `inventory.use`, the belt (consumables go on it like drugs) and the Equipment screen's belt dialog.
- **HP does not regenerate on its own** unless an effect carries `hitpoints_regen` (below): clinics and medkits are the way back.
- **`hitpoints_regen` is points per HOUR**, zero for everybody, a stat any effect row can carry (a dose, an implant, a skill,
  a piece of armour; offered in the effects editor as *HP regeneration (per hour)*). Per hour and not per minute because
  the smallest whole rate is the smallest grant there can be, and a point a minute is sixty an hour. `characters:regenerate`
  (every minute) credits each minute's share: `floor(rate × (m+1) / 60) − floor(rate × m / 60)` for m the minute since the
  epoch, so over any hour it is exactly `rate`, spread evenly, with no stored remainder. Like the SI trickle it heals from zero,
  and there is no baseline mend for hit points: nobody gets any for free.

### Implants

**An implant is an item with a body to go in.**

- **Five regions and eleven slots, a fixed vocabulary and not rows.**
  `App\Implants\BodyRegion`: head 2, body 4, arms 2, legs 2, spine 1. Every character has
  the same body — the opposite of a cyberdeck, hence no `implant_slots` table.
- **Capacity belongs to the REGION and is counted, not stored.**
- **`items.implant_region` is the only place the region is written down.**
- **`inventory_items.installed_at` decides whether an implant is doing anything**, and
  `App\Implants\ImplantFitter` is its only writer. An implant in the stash or in a shard
  does nothing, and neither can be installed without being taken out first.
- **Installed is a fourth way to be elsewhere** (see `scopeLoose()`).
- **An implant's effects live on `items.modifiers`**, read by `InstalledImplants`.
- **Surgery happens at a clinic and the Implants screen is READ ONLY.** `ImplantFitter`
  stays the only writer of `installed_at`, but the only door a player can reach is
  `App\City\Surgeon` (see *The City* in `docs/character-and-city.md`): a timed activity charged up front, a roll to fit one
  (failure = injury and the implant is rejected, staying in the stash), no roll to take
  one out, and no jacking in while on the table.
- The screen is `<x-body-map>`: `public/images/body.gif` with the regions marked on it in SVG.

### Cyberspace, runs, hunters, missions

**The full rules are in `docs/CYBERSPACE_RULES.md` — read the matching section before editing
any of this.** It covers the world and grid, scanning, defences, node contents, raids and
lockdown, backdoors, the map, runs, extraction, egress, burn, attack tags and defeat,
hunters, the waiting room, recovery and missions. The traps that matter everywhere:

- **Each of these has one writer**: `Grid` (position queries), `Chunk` (terrain bits),
  `Navigator` (grid position), `RunStarter` (runs), `Simulator` (every in-run action),
  `Extractor`, `Burner`, `RaidLedger`, `Lockdown`, `Backdoors`, `Hunters::spawn()`,
  `MissionBoard`/`MissionLedger`, `WaitingRoom`. Go through them; never write the columns.
- **A row that REMEMBERS something must not cascade from it** (`system_contacts`,
  `system_scans`, `runs`, `mission_targets` carry no system FK). A backdoor is access, so it does.
- **Damage goes through `DamageResolver`; checks through `CheckRoller`.** Every bad ending
  runs damage, consequence, `jackOut()`, then `WaitingRoom::checkFloor()`.
- **Settling is lazy and catches up**; sweeps (`cyberspace:settle`, `missions:expire`,
  lockdowns) only keep stored rows true between visits. Do not delete the catch-up.
- **Dice are injected**, and `Probe`/`Simulator` are container-bound so tests pin screens.
- **The map screen has a *Routing chain* box** (beside Jump, Thresholds and Bookmarks): the same `ChainBuilder::for()` call the
  briefing makes, with no target, so it lists every system you hold a routing backdoor on, highest grade first, up to `routing_hops`,
  with the `used/cap` count and the briefing's own empty states (cannot route / nothing held). Read only: nothing to pick.
- **Zero means "has none", null means "not looked"** on defences and scans.
- **Never name a hidden fact in a failure line** (OS, hidden systems, remaining loot).
- Locks: `EnsureRunIsFinished`, `EnsureHuntIsResolved`, `EnsureCharacterIsStanding`,
  `EnsureDeckStillRuns` — named allow-lists, admin not exempt.

