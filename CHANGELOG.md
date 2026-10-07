# Changelog

## [Unreleased]

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

[Unreleased]: https://github.com/nahkampf/dedlok/compare/v0.42.0...HEAD
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
