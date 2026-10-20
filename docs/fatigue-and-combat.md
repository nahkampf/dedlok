# Fatigue, safehouses, physical combat

_Split out of CLAUDE.md. Heading levels are as they were there._

### Fatigue and Safehouses (phase 1 built — `FATIGUE.md`)

**Fatigue is the third resource, and FULL is bad.** `characters.current_fatigue` (DECIMAL(8,2), starts 0, clamps to `fatigue_max`) is
deliberately NOT in `Character::RESOURCES`, which means "full is good" to the damage vocabulary, the waiting room and the dials.

- **`App\Characters\Fatigue` is the only writer.** `add()` is scaled by `fatigue_taken` (never `ease()`); `charge($character, $kind, $times)`
  reads `config('fatigue.costs.*')` (positive adds, negative eases) and is called from grid steps, scans, run turns, fight rounds, travel
  passes and explore passes. `reading()` is what the topbar dial and the sheet card draw.
- **`fatigue_max` = 6 Focus + 6 Meat + Social**, a `stats.formulas` entry. `Sheet::baseFor()` resolves its inputs from a second sheet built
  WITHOUT the three fatigue effects (`Character::modifiers($asAt, $without)`), which closes the loop (fatigue lowering attributes lowering the
  ceiling). Other effects still move it. `fatigue_recovery` (per minute) and `fatigue_taken` are stats; the minute clock (`characters:regenerate`)
  eases and syncs, skipping fights and paused encounters.
- **Breakpoints** (`Fatigue::sync()` after every write): Tired at 75% (−3 Focus/Intellect/Reflex), Drained at 90% (−7/−5/−5, replaces Tired);
  Exhausted (−10 all attributes) is granted only by a collapse and lifts under 50%. Up to a minute of staleness after a maximum change is accepted.
- **A collapse is a flag, then a safe point.** Reaching the maximum only sets `characters.collapse_pending`; `EnsureCharacterIsStanding` runs
  `Collapse::trigger()` on the next request, so no travel/run/fight loop is cut mid-step. One transaction on the locked character: abandon the
  encounter, close the hunt, end the run (Cancelled), forfeit the fight, drop the grid position; fixed 20% of the maximum as `DamageOrigin::Exhaustion`
  (HP in a fight or the City, else SI); a mugging (`Reason::Mugged`, 20% chance, 10–33% of creds); a free `Traveller::relocate()` home;
  Exhausted; `WaitingRoom::put()` for 120s with the `collapse` cause, waking at 95% of the maximum. **Exhausted's −10 lowers the HP and SI maxima
  and the clamp-down rule cuts both pools beyond the 20% — kept as designed (2026-10-18).** An away character is flagged by the sweep but collapses
  on their next request.
- **Safehouses** (`safehouses`, `App\Models\Safehouse`, `admin/safehouses`): a type belongs to ONE district; several players may hold the same type.
  `characters.safehouse_id` NULL means the fallback (`config('safehouse.fallback')`, the cardboard box in Knightsbridge Projects). Rest rate per
  type; security and stealth are stored and unread. `character_district_passes` tallies explore passes (the "familiarity" a safehouse will need).
- **Renting** (`App\Safehouses\Renter`, the only writer of `characters.safehouse_id` / `rent_due_at`): needs the body in the district, at
  least `safehouse.passes_needed` (5) explore passes there (`character_district_passes`) and the first day's rent, paid now. Renting drops the
  old one; taking the fallback box is free, needs no passes and is held as `safehouse_id` NULL. Rent is per `safehouse.rent_hours` in advance
  (`Reason::Rent`, note "Rent: <title> [<district>]"), charged lazily by `CityClock::settle()` and swept by `safehouses:rent` every 15 minutes;
  never into debt (an unpayable period waits and is paid when the money arrives); a further `grace_hours` unpaid and the home is lost, they
  fall back to the box and get the `safehouse_lost` message. `Collapse` settles rent first so they wake where they still live.
- **Actions** (`safehouses.actions`, `App\Safehouses\Action`, `HomeActions`): only in the home's district, only when the type lists them. **Rest
  is a `city_activities` row (`ActivityKind::Rest`)**, so the lock is the existing one; it eases fatigue at the type's `rest_rate` a minute,
  credited whenever the City is looked at (the row's `updated_at` is how far it is paid) and ends at nothing or when stopped. Patch, Meditate
  and Cold shower are instant, with a cooldown each (`character_safehouse_cooldowns`) and figures in `config/safehouse.php` (placeholders).
  **Eat** eats the type's `meal`.
- **A meal is `App\Characters\Meal`** (fullness added, fatigue removed): the one place eating is done. A safehouse type carries it as
  `meal_fullness` / `meal_fatigue` (the same two column names, `Meal::rules()` and `<x-meal-fields>` for anything else that authors one, e.g. a
  location's menu later); a food item carries it as the two `UseEffect`s below, which build a `Meal` too.
- **Food at locations**: a location is a place to eat by having `location_meals` rows (name, price, a `Meal`), edited as a *Menu* in the
  location editor (`FeatureEditor`, replaced wholesale) and bought only through `App\City\Kitchen` (`Presence`, creds, `Meal::refusedFor()`,
  `Reason::Meal`).
- **Fullness** (`App\Characters\Fullness`, `characters.current_fullness`): hidden, 0 to `fatigue.fullness.max` (50), drained
  `drain_per_minute` (0.07) by the minute clock, and only ever a limit on eating. **Food is a consumable** with the `UseEffect`s `fullness` and
  `ease_fatigue` (edited like `heal_hitpoints`); `Consumer` refuses food as `UseRefusal::TooFull` when full, keeping the copy. Screens: `/safehouse`, and the district screen's "Get a safehouse here" / "Your
  safehouse".
- **Storage** (`App\Safehouses\SafehouseStorage`, the only writer of `inventory_items.safehouse_id` / `storage_expires_at`): a type's
  `storage_slots`, one per row (a stack is one). A stored row is an ordinary inventory row tagged with the safehouse: out of `scopeLoose()`
  and of the deck's storage count, no stack key (piles merged by hand), data withdrawn only where `Storage::fits()`. **Safe from muggings by
  construction**: muggings take creds only. Usable only at the home you hold, in its district, idle. Losing or leaving a home gives its stored
  rows `safehouse.storage_hours` (48) and sends `safehouse_stored` with a Collect button (`details['collect']`, also on `/safehouse`);
  `collect()` moves what fits, `safehouses:rent` deletes the rest after the deadline, and renting the same place again takes them back. A
  safehouse holding things cannot be deleted in the admin (RESTRICT).
- **Wired drugs stop rest**: `drugs.wakefulness` (per drug, in the item editor) adds up over the doses in effect; at
  `safehouse.rest.wakefulness_limit` (10) `HomeActions::tooWired()` refuses Rest (`Refusal::Wired`) and ends one under way, keeping what it
  earned. A dose of an `overridden_by` family (opiate, medical) in effect lets them rest anyway.
- **Finding, working out and raiding a home** (`App\Safehouses\Leads`, the only writer of `safehouse_leads` and of a stakeout's / scouting's
  `city_activities` row; `LeadController`; `safehouse.leads.*`): a finished explore pass may SENSE a held, non-box home in the district
  (`exploration` check against `leads.find.difficulty`, floored at 2% by `checks.floors.safehouse_find`), giving a lead ("Suspicious place N")
  with discovery 0 and security 0. **Stakeout** (passes of a minute until stopped or the owner is known: 50% nothing, 40% +0.1, 10% +0.8) and
  **Ask around** (instant: nothing / clues for 5-15 creds and +2 / misdirected -2 / a nutjob for 5-25 creds) raise discovery; past the home's
  `stealth` the owner is known and the place is "Safehouse: <handle>". Then **Scope out the security** (minute passes, +3 security on a check) and
  **Break in** (a stealth + Perception/Senses check; difficulty falls to its base once security passes the home's, else rises with the shortfall).
  Inside: **Rob** (up to `steal_rows` stored rows) or **Burn** (the holder loses the home and its storage at once, `Renter::burnDown()`), or Leave.
  A successful rob puts the HOME on a 24h cooldown for everyone (`characters.raid_cooldown_until`: rob and break-in refuse with `Cooldown`). A failed
  break-in has a 15% chance (`police_chance`) of the police arriving: a stern talking-to (no pursuit until physical combat) and the scouted security
  reset to 0. Attack is not built (no PvP). A lead is deleted whenever its target stops holding that home (`Leads::prune()`); the Tasks screen lists leads
  with a Forget button. Stakeout/scouting are city activities (the existing lock) settled lazily by `CityClock`. Fatigue per pass/step is
  `fatigue.costs.*`, mood per step `mood.events.*`. All numbers are placeholders.
- **Mood** (`App\Characters\Mood`, `characters.mood` DECIMAL, -100 to +100, `config/mood.php`): the only writer. A stored figure moved by
  `event($character, 'fight_won')` (amounts in `mood.events`: fights won/lost, collapse, injury per point, a meal, a dose, raided,
  burned) and pulled `drift_per_minute` (0.01; `extreme_drift_per_minute` 0.03 beyond ±70) towards 0 by the minute clock. Bands (`mood.bands`) grant `config/effects.php` rows
  (`mood_despondent`, `mood_low`, `mood_good`, `mood_elated`; small, one at a time) via `sync()` after every write, as Tired and Drained
  do. Shown on the sheet as a band word with the figure in the tooltip; no dial. Placeholders throughout; more events (winnings, losses of
  creds, safehouse quality as a baseline) are not wired.

### Physical combat (in progress — `COMBAT.md`)

**Steps 1–6 of the build are done** (damage types, armour, weapons, wielding, the engine, NPCs, the stored fight, encounters that start fights, and what a win pays and a loss costs). Left: the balance pass, which needs authored content.

- **The eight damage types are `DamageOrigin` cases** (blunt, slashing, penetrating, fragmentation =
  family `kinetic`; electrical, thermal, chemical, neural = `specialty`). The family is a second tag on
  the generated `damage.<resource>.<type>` stats, so a resistance is `all_of: ['damage', 'kinetic']`.
  The six older origins are causes and carry no family. Resistances **sum, then apply once** — never
  diminish. Adding origins changes two pinned test lists (the damage vocabulary, the Crim perk's stats).
- **Armour is an item with an `armour` row** (the slots it covers); its resistances are `items.modifiers`.
  `App\Combat\ArmourWearer` is the only writer of `worn_armour`, one row per slot. The seventh modifier
  source, `WornArmour`, wraps a worn piece's DAMAGE modifiers in `hit_location_in` with the slots it
  actually covers; `Damage` carries an optional `?ArmourSlot $location` and `DamageResolver` puts it in the
  context. **A hit with no location never meets worn armour** (ICE, an overdose, an encounter's `damage`
  outcome, a burn). Non-damage modifiers on a piece apply whenever it is worn. The editor refuses a
  condition of the armour modifier's own.
- **`Damage` has an optional `penetration`** (signed percent, default 0): `DamageResolver` scales only the
  hit's NEGATIVE percentage modifiers by `1 − p/100`, so a round can ignore part of a vest. Vulnerabilities and
  immunities are untouched. Only the combat engine passes one; ICE and every older source must not.
- **Combat numbers are generated stats** (`App\Stats\CombatStats`): `aim.<class>` (one shared pool in
  `stats.pools.aim`), `weapon_damage.<class>` (base handed in, unrounded) and the declared `defence`. A new
  `WeaponClass` case brings both with it; each needs a `character.names` entry to be offered in the editor.
- **There is no dodge, and none is planned** (decided 2026-10-05): `defence` and `pursuit` are permanent stats
  (base 8 each in `stats.bases`), and modifiers reach them like any other. A combat skill is **data on its row**,
  never code: its `skills.modifiers` target `aim.<class>`, `weapon_damage.<class>`, `defence` or `pursuit`. Close in
  rolls `pursuit`; Create distance and Disengage roll `defence` against the opponent's `pursuit`.
- **A weapon class can be gated by a skill** (`config/combat.php` `skill_gates` maps class to skill slug,
  `gate_level` is the level, 1). `WeaponWielder::wield()` is the only place it is asked, on the RESOLVED level
  (`skill.<slug>`), and refuses with `WieldRefusal::RequiresSkill`; a class left out is free, a slug with no skill row
  gates nothing, and a weapon already equipped is never taken away. Pistol, SMG, rifle and shotgun are gated; blade,
  blunt, unarmed and explosive are not. The stash card tags a gated weapon *Requires <skill> <level>* whether or not
  you can equip it (no per-card sheet).
- **The fight engine is pure and never writes** (`App\Combat\Fight\FightEngine`): a plain-data `FightState`
  in, a new one out, resistance a `Mitigation` argument, one injected die in a documented order, `chance()` and
  never `CheckRoller::roll()`. Nothing in it may call `takeDamage`, `WaitingRoom`, `WeaponLoader` or `Wallet` —
  the step that stores a fight applies its `Result`. Tested with `Tests\Support\QueueDice`, which throws when
  it runs dry and is asserted spent, so a drift in dice order fails a test.
- **Distance is a number** (`FightState::$distance`, `combat.distance`): a fight opens at 2, 0 is close combat, 6 is the
  most, and **only an action moves it** — Close in (one step) or Create distance (one or two, `far_share`). `Range` is
  derived (`Range::at()`). **Disengage works only from beyond 2.** `FightEngine::choose()` plays any side with nobody
  choosing: the one better at close combat (`prefersClose()`) closes in, the other holds range and backs off when
  closed on. Old stored fights (`range`) still load.
- **`php artisan fight:simulate <handle>` is the balance tool**: a real character's loadout against an opponent
  described by options, seeded and repeatable, writing nothing. Run it before and after touching any combat
  number (`config/combat.php`, `stats.pools.aim`, `stats.bases.defence`). `DamageResolver::against()` is the
  resolver's sum with the modifiers handed in; `preview()` uses it, and a fight must not query per hit.
- **An NPC is a row of numbers and three pointers at items** (`npcs`: ranged weapon, melee weapon, standing
  round), authored at `admin/npcs`. A fight reads it ONCE into a snapshot (`CombatantBuilder::forNpc()`), so an
  edit never touches a fight running. Bare hands at Close, no ammunition counting, resistances as percent off
  by type and by body part. Whose it is (`entity_id`), what it carries (`npc_loot`) and what it does to somebody
  it beats (`tags`) arrived with step 6 and are described under *Endings* below.
- **`App\Combat\Fights` is the only writer of `fights` and `characters.current_fight_id`.** One transaction a
  round with the character and the fight locked; the player's hit points and SI are re-read from the character at
  the start of every round and the round's drop applied with `takeDamage()` (never `DamageResolver::apply()`);
  the opponent is a snapshot on the row; ammunition goes through `WeaponLoader` and a desync throws. **Anything
  that can start something must refuse while `current_fight_id` is set** (the middleware is not the guard), and a
  fight may open inside an encounter. `fight:start <handle> <npc-slug>` is the only way to open one until
  encounters can.
- **Endings are `App\Combat\FightEnding`'s**, called by `Fights::act()` on the locked character BEFORE the waiting
  room is asked (damage, consequence, the floor), returning log lines that go onto the fight's log and into the
  `DownedCause` effects. **It has its own integer die** (`fn (int $min, int $max): int`, container-bound like
  `DefeatResolver`'s) and never draws from the fight's: that die is pinned in an exact order and asserted spent.
  A win pays the grade's band (`combat.pay.<grade>`, through `Wallet` as `Reason::Fight`), rolls each drop in the
  order authored (a chance, and a quantity only on a success; granted through `ItemGranter`) and moves the owner's
  standing by `StandingReason::Defeated` × the opponent's grade (negative, silent in the log). **Only a win pays or
  moves standing**: a loss costs, and getting away — the player's or the opponent's — is nothing either way.
- **Tags are `CombatTag`**, a fixed vocabulary (`mugged`, `maim`; a new one is a case, a string and a branch).
  **`npcs.tags` NULL is the DEFAULT, `[mugged]`, and any list REPLACES it** — the opposite of ICE, where null and
  `[]` are one answer. `mugged` takes a uniform `combat.mugging` percent of ALL creds carried, floored
  (`Reason::Mugged`); `maim` deepens `physical_trauma` by a `combat.maim` percent of the maximum as it stands. The
  editor cannot express "takes nothing": ticking no box is the default.
- **A fight copies its terms when it opens** (`fights.entity_id`, `tags`, `loot`), so an opponent edited or
  deleted mid-fight changes nothing. `fights.entity_id` is a plain column, never a foreign key. An item in the
  snapshot that has since been deleted is skipped and costs no die.
- **Drops are `npc_loot`** (item, chance 1–100, min and max quantity), replaced wholesale by `NpcEditor`, the only
  writer. **Physical items only** (`NpcRequest`): a data item would have to ask `Storage::fits()` and a drop cannot
  be refused. This is NOT `loot_tables`, which is the grid's rarity weights. `Fights::act()` copies `creds` back
  onto the caller's character, or the topbar shows the old balance after a payout.
- **Weapons and ammunition are items with rows** (`weapons`, `ammunition`). A hit's damage is ONE fixed
  figure (a firearm's is its round's); the profile (a map of type to percent, summing to exactly 100) splits it; `DamageProfile` is the
  pure judge. `WeaponWielder` is the only writer of `equipped_ranged_id` / `equipped_melee_id`.
- **Gear is only its own kind**: armour, a weapon and ammunition are each exclusive of one another and of
  hardware, software, drugs, imprints and implants (`ItemRequest`). Armour and weapons are non-stackable,
  ammunition stackable; all are physical.
- **A piece worn, a weapon equipped or a weapon with rounds in it stays in the stash grid, tagged**, and
  cannot be listed, sold or put in a container (`InventoryItem::isOnPerson()`).
- **Rounds are on the weapon's inventory ROW**, never on `weapons`: `loaded_ammo_id` (an item, because
  ammunition stacks) and `loaded_rounds`, no CHECK (error 3823) so a NULL pointer IS an empty magazine.
  `App\Combat\WeaponLoader` is the only writer; rounds come off the loose pile and return through
  `ItemGranter`. **Anything that removes a weapon or a round hands the rounds back first** (destroy, both
  editors). A mid-fight reload or switch costs a turn — the engine's, per COMBAT.md.
- **One item per kind of round**: `ammunition` is unique on (calibre, type), in the schema and in
  `ItemRequest`, because ammunition stacks per item and a twin would be a pile that never merges.
- **A firearm has no damage of its own — its ROUND does.** A weapon with a calibre has `damage` and
  `damage_profile` NULL (the editor refuses both on one; CHECK `chk_weapons_damage_or_calibre`), and the round's
  `damage` is the whole figure of a hit (1–1000, absolute, never a percent) with a required profile. A blade,
  club or grenade has no round and keeps both on the weapon. **A round's riders ADD to the weapon's.** An NPC
  with a firearm needs a standing round (`NpcRequest`); with none it has no ranged arm.
- **`config/strings.php` must not declare a top-level group twice** — PHP keeps the last silently
  (`StringKeysTest` guards it).

