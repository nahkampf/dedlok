# The City

The physical half of the game: districts, the places in them, and what happens there.
You are in the City or you are jacked in, and a character can be jacked in while their
body stands in a district.

Read docs/MECHANICS.md first for anything that produces a number. Every duration, chance and
modifier named here resolves through the one resolver, and none of the arithmetic lives
in this file.

**Status: phases 1, 2 and 3 built (2026-10-03); the rest designed.** Agreed 2026-10-02.
Every figure below is unbalanced.

| Piece | State |
| --- | --- |
| Everything below, unless a row here says otherwise | **specified only** |
| Districts, links, locations, `character_locations`, `character_flags`, `characters.district_id` | **built**; seven districts and ten placeholder locations arrive by migration |
| The city map and the district map (`/city`, `/city/{district}`) | **built**, view only; a jacked-in character is redirected to cyberspace |
| `Location::scopeVisibleTo()`, `Discovery::unlock()`, `Flags::set()`, `flag:set` | **built**; nothing in the game calls `Discovery` yet |
| `Category::Street` → `Category::City` | **built**; stored rows moved by migration, the route is the city map |
| Travel (walk and pod), explore, cancelling, `city_activities`, `Router`, the lazy settle and `city:settle` | **built** (phase 2a) |
| `exploration`, `explore_duration`, `travel_duration`; `checks.floors.exploration` = 0 | **built**; the pool reads about 13 on the six characters in the local copy of production, against the 14 the difficulties assume (Trivial ~48%, Difficult ~19%, Obscure ~4%, Secretive ~0.9%) |
| Shops and clinics: `/city/place/{slug}`, `ShopKeeper`, `Clinic`, `shop_items`, `clinic_services` | **built** (phase 3); one clinic arrives by migration, shops are stocked in the editor |
| Admin editors for districts (with their links) and locations | **built** (phase 2b): `/admin/districts`, `/admin/locations` |

Vocabulary, because it was corrected: moving between districts is **travel**, finding
places is **explore**. *Walk* is only the free MODE of travel (the other is *Hail a
pod*), never the name of an action. A place you have found is **explored**.

---

## Presence

- **`characters.district_id` is where the body is, and is always set.** Being jacked in is
  still only the cyberspace position (`grid_x`/`grid_y`) and means nothing else.
- **City actions require being jacked out**: travel, explore, shops, clinics, encounters.
  Cyberspace actions require being jacked in, as today. The refusal says *jack out first*.
- **Running software survives a jack-out**, as it already does. No new rule.
- **Jacking in is refused while a City encounter is open**, the way `TravelRefusal::Hunted`
  refuses it. So an encounter, a hunter and a run can never overlap.
- **A travel that is under way finishes whether or not you jack in.** You cannot START
  one while jacked in.

## The maps

Two levels, both schematic node maps laid out by percentage coordinates, like the deck
board. Not a grid.

1. **The city map** — districts. Clicking one opens its district map.
2. **The district map** (`/city/{district}`) — that district's explored locations.
   Hidden locations are not drawn at all, not even as fog: a player must not be able to
   count what they have not found.

Any district's map may be opened from the city map. Travel, explore, shops and clinics
work only in the district the body is in.

## Content

Definitions are rows, authored in the admin like items. Nothing here is config except the
numbers (`config/city.php`).

| Table | Is |
| --- | --- |
| `districts` | slug (permanent), name, text, a `color` (below), `map_x`/`map_y` on the city map, and `travel_weight` (default 1): a multiplier on how long crossing THIS district takes |
| `district_links` | an undirected link between two districts, nothing else. The graph the router walks |
| `locations` | a place in a district: slug, name, kind, `map_x`/`map_y` on the district map, a discovery rule, and for `explorable` a `concealment` |
| `character_locations` | one row per (character, location) meaning *found by exploring* |
| `character_flags` | one row per (character, flag), `set_at`. A flag is a slug and is permanent |
| `city_activities` | one row per character at most: what they are doing right now |

### District colour

`districts.color` is a `DistrictColor` case: one of the kit's DECORATION tokens (steel,
cyan, brass, violet, clay, jade, moss). It says **which** district something belongs to on
the maps and lists, and never what state it is in — so ember and every semantic alias are
out. Violet, clay and moss were added to the kit for this (muted, in the register of the
other four). A district's locations are drawn in its colour.

### How a location becomes visible

`locations.discovery` is three-valued:

- **`open`** — visible from the start.
- **`explorable`** — found by exploring. `concealment` is required, and is null on the
  other two (a CHECK says so).
- **`explored_by_flag`** — visible once the character has `locations.required_flag`.

**Visibility is a READ**: open, or a `character_locations` row, or the required flag
set. A flag needs no unlock step and cannot drift out of step with the location.

- **`App\City\Discovery::unlock()` is the only writer of `character_locations`.**
- **`App\Characters\Flags::set()` is the only writer of `character_flags`.** Flags are one
  way: set, never unset. Who sets them: an admin `flag:set <handle> <flag>` command to
  begin with, then narrative outcomes and authored quests. The present missions are
  procedural and cannot carry a story flag, so they are not a setter.

### Concealment

`App\City\Concealment`: **Trivial, Difficult, Obscure, Secretive.** Each maps to a
difficulty in `config/city.php`, and that is the whole effect.

## Time

Everything in the City that takes time is **stored and resolved in SECONDS**, because a
skill that targets seconds is far easier to write than one that targets minutes. A
resolved duration is floored at 1.

A character has at most one activity at a time. It settles **lazily**, `Probe::settle()`'s
shape: each pass begins when the previous one matured, so an hour away is an hour of
passes. `city:settle` sweeps every five minutes so the rows are true between visits. It is
NOT what makes an activity finish; do not delete the catch-up because the sweep exists.
Anything that lands while the player is away goes to the inbox under `Category::City`.

### Travel

- **A route is built, not picked.** `App\City\Router` is pure (district ids, links and
  travel weights, never models) and returns the QUICKEST route: lowest total weight of the
  districts crossed, ties broken by fewer districts. No route is a refusal, not an error.
- **A travel is one pass in every district on the route EXCEPT the destination, origin
  included**, and then you arrive. Each pass takes `travel_duration` (base **30 seconds**
  × the `travel_weight` of the district it is in, resolved with the character's
  modifiers). **You MOVE along the route: `district_id` is the district whose pass is
  running**, so it is never null. Starting from A to reach D by B and C: the first pass is
  in A, the second in B, the third in C, and when that finishes you are in D. Three
  passes for four districts.
- **The route is snapshotted at the start** and never rewritten, as `runs.chain` is.
- **Two modes.** *Walk* is free and takes the full time. *Hail a pod* takes **half** of
  each pass (applied after the modifiers) and costs **25 creds per district on the
  route, the destination and the origin both counting** — so 100 for A→D above — charged
  up front through `Wallet::move()` (`Reason::Pod`).
- **Cancelling waits for the pass in progress to finish, then leaves you in the district
  that pass was in** — cancel in B and you stay in B, you do not go on to C. On a pod
  the districts not reached are refunded: A→D cancelled in B pays for A and B and gets
  25 back for each of C and D.
- **Planned hook, not built:** a travel pass may roll for an encounter in the district it
  reaches, and an encounter stops the travel there.

### Explore

- **A pass takes `explore_duration`, base 240 seconds**, and costs only the time. It is
  started in the district the body is in.
- **The roll is made when the pass MATURES**, once per still-hidden `explorable` location
  in that district, through `CheckRoller` against the concealment's difficulty. The
  value is the resolved **`exploration`** stat, a pool: `(Focus + Perception + Senses) /
  4`, the same divisor egress uses, so a starting character is about 14.
- **A pass finds at most one location, or none.** Where several rolls succeed, the
  HARDEST wins and the others wait for another pass. That keeps each concealment's quoted
  chance true however many places are left, and stops the easy ones starving the rare
  ones.
- **Secretive does not use the certainty floor.** `checks.floors.exploration` is `0.0` —
  the first entry in that list, deliberately: a character far below a secret should be
  hopeless against it, not 0.85%. The exception is counted by looking at one file.
- **Cancelling an explore is allowed at any time** and simply loses the roll.

#### Starting difficulties

Chosen against a typical starting pool of 14.25 (about 19 an attribute, read off the
stealth and egress comments rather than measured) and `checks.spread` 8:

| Concealment | Difficulty | Chance a pass finds it |
| --- | --- | --- |
| Trivial | 14 | ~51% |
| Difficult | 25 | ~21% |
| Obscure | 38 | ~5% |
| Secretive | 51 | ~1% |

**They are only true at spread 8.** A retune of `checks.spread` moves all four.

## Interactables

**Phase 3 decisions, all defaults the author has not yet seen** — each is one number or
one line to change:

- **A listing has stock and a restock clock.** It is a row (`shop_items`) with a nullable
  price that falls back to `items.base_cost`, a `max_stock` (NULL is unlimited), a
  `restock_amount` and `restock_hours`. **Restocking is lazy**: each whole interval since
  `restocked_at` adds the amount up to the maximum, settled under a lock by whoever looks
  next, and the boundary advances by whole intervals so a full shelf banks nothing. A new
  listing starts full. **It ignores `items.on_market`**: selling what the market does not is
  the point.
- **A shop writes neither `market_purchases` nor `market_stock`** — the first is the
  seeder's demand signal and `Purchaser` is its only writer. `ShopKeeper` is the door.
- **A place can BUY from players** (`location_buys`): only what its list names, at its
  own price or `city.sell.fraction` (0.4) of the item's cost, kept under a shop's price so
  buying and reselling cannot print money. **What is sold vanishes** — it does not join a
  shelf. Only things in the character's hands can be sold: not installed, inside a
  container, fitted, running, listed on the market, the deck being carried, or a container
  holding anything.
- **Treatment is instant and priced PER POINT** (`clinic_services.price`): restoring
  charges `maximum − current`, worked out under the lock. Four services — restore hit
  points, restore Synaptic Integrity, mend neural damage, mend physical trauma. Mending
  an injury raises a maximum and does not fill anything, so the screen says to mend first
  and restore after. Implant surgery stays free and is not a clinic service.
- **Implant surgery is its own section below.**
- **Shops and clinics refuse while any City activity runs**, while jacked in, and unless
  the body is in the place's district. A place is a feature by having rows; there is no
  feature table.
- **The place page is the first door that addresses a location**, so a hidden place is the
  same 404 as one that does not exist.
- Pins keep one icon; a place's features appear in its tooltip and as tags in the list.


Typed features on a location, a fixed vocabulary like `Drugs\Family`; each kind gets its
own table when it is built, never a JSON bag. First are **shops** (a per-location stock,
paid through `Wallet`) and **clinics** (HP, SI and injury treatment, using
`Character::recover()` and `InjuryLedger`).

## Implant surgery

Decided 2026-10-03 and built the same day; every figure is unbalanced.

- **Only at a clinic that offers it.** The Implants screen is read-only and points at the
  City. `ImplantFitter` is still the only writer of `installed_at`; `Surgeon` is the only
  door a player reaches it through.
- **A clinic with surgery has a `clinic_surgeries` row**: a surgeon `rating`, and a price
  per tier to fit and to remove. **A clinic fits any implant** — how risky it is to go there
  is its rating, nothing else. **What a clinic SELLS is its shop listings**, like any
  shop; the two are independent, except for **buy and fit**: an implant on a clinic's shelf
  has a second button that buys it and goes straight onto the table, price plus fitting fee
  in one transaction (`Surgeon::buyAndFit()`). **Any refusal at any step — no room left in
  that region of the body included — rolls the purchase back**, and the button is not offered
  when there is no room. Defined per clinic in the editor.
- **A surgery is a timed City activity** (`city_activities.kind = surgery`), seconds
  per tier through `surgery_duration` (300 to fit, 120 to remove), charged up front. **It
  cannot be cancelled**, and a body on the table cannot jack in (`TravelRefusal::UnderTheKnife`).
- **Fitting is a roll**: the clinic's rating against `city.surgery.difficulty` for the
  implant's tier, through `CheckRoller` with its own chance quoted beforehand. **A failure
  costs `injury_per_tier` points a tier** — neural damage for the head and spine, physical
  trauma elsewhere — **and the implant is rejected: it stays in the stash.** Destroying a
  rejected implant is the harsher variant left out, and stays out: a bought implant that fails to
  take is in the stash too. **Removing is a fee and time and no
  roll.**

## Encounters

Two kinds, and they are different engines. **Narrative is built (phase 4, 2026-10-03); combat
is blocked** — it needs weapons and armour, which the game does not have, so it needs its own
design pass. Reaching 0 HP uses the existing waiting screen.

**Every number below is a default the author has not yet tuned** — one number or one line to
change. Nothing is seeded: encounters are authored at `admin/encounters`.

### What an encounter is

**Data, never PHP.** A graph of nodes, choices on each node, and outcomes on each choice. If a
story needs new PHP, the outcome vocabulary has leaked; fix the vocabulary.

- **`encounters`** — the definition. `slug` (`[a-z0-9_]`, permanent), `title`, `intro`,
  `starts_on` (`travel`, `explore`, `place` — a button — or `arrival` — rolled once per arrival at a place, needs its own `chance`, optional `location_id`/`district_id`, takes the screen; "arrival" is coming to a place other than the one last viewed this session, so a reload is not one), `weight`, optional `district_id` (NULL is
  anywhere) and `location_id` (for `place`), `required_flag`, `once`, `retired`. Text is ON the
  row. Both foreign keys cascade.
- **`encounter_nodes`** — a `node_key` unique within the encounter, `body`, `entry` (exactly one)
  and `ends` (shows its body and a Continue button; its choices are ignored).
- **`encounter_choices`** — `label`, optional `requires_flag` (hidden, never greyed), `cost` in
  creds, `next_key` (NULL closes) and an optional **check**: `check_stat` (any stat id, resolved
  through the Sheet), `difficulty`, `pass_key`, `fail_key` (each falling back to `next_key`).
- **`encounter_outcomes`** — one row per effect, on `always`, `pass` or `fail`. **An outcome belongs
  to a button (`choice_id`) or to a SCREEN (`node_id`), exactly one**: a screen's outcomes apply the
  moment somebody ARRIVES on it, every time (so a loop back to it hurts again), always — a screen has
  no roll, so no pass or fail. That is how an ending can cost something: its text cannot, but its
  outcomes can. They apply on the opening screen too, as `Encounters::open()` puts someone on it,
  and the lines are told with the choice that led there (or as the log's first line, for the
  opener). Held by `EncounterRequest`, not a CHECK — both columns cascade (MySQL error 3823).
- **NODES ARE REACHED BY KEY, NEVER BY ID.** The editor replaces an encounter's nodes wholesale
  on every save, which is safe only because nothing points at their ids: a live character
  carries `node_key`, and a key that no longer exists closes the encounter gracefully.
- **No CHECK touches a cascading foreign key** (MySQL refuses one, error 3823, and the SQLite
  suite would never say so). "An outcome of kind `item` has an item" lives in
  `EncounterRequest`, as `entity_offers` does it. The CHECKs that exist are on plain columns.

### Outcomes: a fixed vocabulary

`App\City\OutcomeKind`, like `Drugs\Family`: a new kind is a case, a label and a branch in
`EncounterOutcomes`. **Every kind goes through the writer that already owns it.**

| Kind | Writer |
| --- | --- |
| `creds` (±) | `Wallet::move()`, `Reason::Encounter` |
| `item` | `ItemGranter`, after `Storage::fits()` for data |
| `standing` (±) | `StandingLedger`, `StandingReason::Encounter` |
| `heat` (±) | `characters.heat`, floored at nothing. `bounty_grade` is not touched: only a run sets it |
| `flag` | `Flags::set()` |
| `injury` (±) | `InjuryLedger::inflict()` / `heal()`; the key is one from `config/effects.php` |
| `damage` | `DamageResolver`, `DamageOrigin::Encounter` — a resistance is `all_of: ['damage', 'encounter']` |
| `dose` | `DrugTaker`, after `ItemGranter` makes the one copy it needs (below) |

**`damage` can empty a resource**, so a choice that dealt any asks `WaitingRoom::checkFloor()`
last. A deleted item or entity cascades its outcome away. **A fight is not an outcome**: an outcome cannot
lead anywhere. It is a kind of CHOICE (`docs/COMBAT.md`, *From an encounter*), with a win, a loss and a nobody-won
screen, and a reward is the win screen's own arrival outcomes.

**A dose is grant-then-take**, in the runner's own transaction. `DrugTaker::take()` takes an
inventory row and spends a copy, and an encounter has none, so one is granted and taken at once;
nothing is left behind, and a copy the player already owned is not spent. Everything a dose is then
applies as if the player had chosen it: tolerance weakens it, addiction grows, and too many of one
family overdoses (damage, an injury, and for lethal families a flatline), with `DrugTaker` asking
the floor itself. The item must have a `drugs` row (`EncounterRequest` checks); the amount is
ignored. An author who wants a bad pill is writing a story that can kill — that is the point.

### Checks and the button

- **`CheckRoller` is the only die**, kind `encounter` (the global floor). `chance()` is asked for
  the button and `roll()` for the click, so the warning and the roll cannot disagree. An unknown
  stat id is a value of nothing, not a crash.
- **The button shows a band word** (`config/city.php` `encounter.bands`), with the figure in its
  `title`. Unmeasured numbers on a screen read as promises.
- **A choice's `cost` is charged before the roll** and not refunded by a failure. Unaffordable
  disables the button and the runner refuses again.

### How one starts

**Three doors, one writer: `Encounters::open()`.**

- **A travel pass** rolls as it matures, in the district it reaches. A hit **stops the trip there**
  (`Traveller::stop()` — a pod's unreached districts are refunded) and opens the encounter. A hit
  on arriving at the destination arrives first, then opens it. **There is no pause and resume** (decided 2026-10-03): afterwards the player travels again. A player can also end a trip themselves with *Stop travelling*, which finishes the pass in progress and leaves them in that district.
- **An explore pass** settles its discovery FIRST — what the pass found is never lost — and then
  may roll.
- **A place** can offer one (`starts_on = place`): a button on its page, only while the body is
  in that district. This is the door authored quests walk through.

**The spawn roll is not a check**, so it is a flat percentile with an injected die, bound in the
container (`Hunters`' shape). Its chance is the **resolved `encounter_chance` stat**, based on
`city.encounter.travel_chance` (11%) or `explore_chance` (40%) and handed in at the call site, so a
skill that quiets the street is a modifier.

**Which encounter is a weighted draw out of `max(eligible weights, city.encounter.pool)`**
(pool 100), and the rest of the pool is "nothing happens". That is what makes RARE mean rare: a
lone encounter of weight 2 is a 2% pick, not a certainty because nothing else qualified. Eligible
is right door, right district, flag held, not retired, an entry node, and — if `once` — not
already seen. **Seen is a flag**, `encounter_<slug>`, set on entry.

**You cannot jack in while travelling, exploring or on the table** (`Navigator::jackIn()` refuses
any City activity), so a pass can never mature under somebody in cyberspace and an encounter can
never open beside a run. `Encounters::canHappenTo()` still checks — jacked in, in a run or hunt,
already in an encounter, on the floor — as a guard for an activity row that predates the rule.
A settle that opens one adds an `encounter_waiting` message, which the sweep sends to the inbox.

### State and the lock

- **`character_encounters`** is the live instance (encounter, `node_key`, an append-only `log`
  JSON the screen replays), and **`characters.current_encounter_id`** is what "in an encounter"
  means. `Encounters::open()` sets it and `EncounterRunner` clears it. The row is deleted when
  the encounter closes; the flag is the only lasting record. Deleting a definition cascades the
  live row and nulls the pointer.
- **`EnsureEncounterIsResolved`** is `EnsureHuntIsResolved`'s shape: named allow-list
  (`encounter.show/choose/leave`), 409 JSON, admin not exempt, ordered after the waiting-room
  check. City screens that settle on the way in redirect to the encounter if one just opened.
- **Encounters wait for you.** One that opens while somebody was away holds the screen on their
  return; nothing happens meanwhile.
- **One transaction per choice**, on the locked character and the locked live row. The choice
  must belong to the CURRENT node, so a double submit is refused rather than replayed. A choice
  with nowhere to go closes at once; an ending node closes on Continue.

### Prerequisites *(designed 2026-10-11, not built)*

**An encounter may carry prerequisites: a list of conditions that must ALL hold before it can be drawn, and the
list may be empty.** An empty list is today's behaviour exactly. This is the same question `required_flag` and
`once` already answer for one thing each, made general, so that an encounter can wait for a flag set AND ten scan
files AND a skill level. It applies to every realm and door (`docs/ENCOUNTERS_GRID.md` shares it).

- **Eligibility, never a roll.** A prerequisite is judged where the pool is built (`Encounters::open()`): an
  encounter with an unmet one is not in the pool, so it neither spawns nor dilutes the draw. A `place` or `spot`
  encounter offered by a button is simply not offered.
- **Data, never PHP.** Rows in `encounter_prerequisites` (`encounter_id`, `kind`, `subject`, `amount`, `negate`),
  a fixed vocabulary as `OutcomeKind` is: a new kind is a case and an arm of one judge, `PrerequisiteJudge`, and
  nothing else. The judge only READS, through the owner of each thing (`Flags`, `Character::stat()`,
  `SkillInjector`/`hasSkillAtLevel`, `Wallet`, standing through `StandingLedger`).
- **Kinds to start with:** `flag` (set / not set via `negate`), `item` (an item and a minimum quantity), `scan_files`
  (at least N whole, unexpired, loose scan files, optionally of one system type; files are not an item, so they
  need their own kind), `creds` (at least), `skill` (level at least, on the resolved level), `standing` (with an
  entity, at least or at most), `district` (is standing in one). Each is "at least" by default and `negate` flips it.
- **Held, not spent.** A prerequisite never consumes anything. Spending is an outcome on a choice (the cost of the
  choice, or an `item` outcome with a negative quantity), so "needs ten scan files" and "takes ten scan files" are
  two separate things an author states separately.
- **`required_flag` becomes the first prerequisite.** A migration copies each `required_flag` into a `flag` row and
  the column goes; `once` and `retired` stay columns (they are about the encounter's history, not the character).
- **A choice keeps `requires_flag`.** A button is hidden by it, never greyed, and the same judge can later back a
  choice's own list; that is a separate decision (see below).
- **The editor** gets a *Prerequisites* panel above the screens: add and remove rows as everywhere else
  (`data-add-row`, `data-remove-row`), a kind picker that decides which of subject and amount are shown.
  `EncounterRequest` refuses a row of a kind missing what it needs, and a subject that does not exist (a flag is
  free text, so it is not checked; an item, skill and entity are). NOT a CHECK: it hangs off a cascading key
  (MySQL error 3823).
- **Not decided:** whether a choice may have a list of its own (today one flag); whether "any of" (OR) is ever
  needed, or whether two encounters say the same thing better; whether the player is ever told what an unmet
  prerequisite was. The default is no, as with a hidden location, so nobody can read off the list by looking.

### The editor

`admin/encounters`; `EncounterRequest` validates the nested form and `EncounterEditor` is the
only writer. Refused, because SQL cannot see a graph: no entry or more than one, a target that is
not a node here, a non-ending node with no choices, an outcome of a kind missing what it needs,
and **a node from which no ending can be reached** (`EncounterGraph`, pure). **Loops are allowed**
("ask another question"); a trap is not. The form pads itself with blank rows and skips them.

### Not built

A fight outcome; per-district danger; any seeded content.

## Phases

1. **Foundation.** *(built)* Schema, `district_id`, the presence rule, the city map and district
   map, `Discovery`, `Flags`, the `Street` → `City` rename (a data migration over
   `notifications.category`), a starter city by migration, and a minimal admin editor for
   districts, links and locations.
2. **Travel and explore.** *(built, with the editors)* `Router`, `city_activities`, the settle and its sweep, the three
   stats, the pod fare.
3. **Shops and clinics.** *(built)*
4. **Narrative encounters**, spawned by travel and explore and fixed at locations. *(built)*
5. **Physical combat** — *built through `docs/COMBAT.md`'s step 6 (a fight can start from an encounter; a win pays, a loss mugs). The balance pass is not done.*
6. **Hooks.** Authored quests that set flags, hunters on the street, PvP.

## Not decided

- Whether a district can itself be hidden. Every district is on the map for now.
- What a bounty collected in the City looks like. Parked 2026-10-06 until PvP is designed: full payout and a per-pair cooldown, and a *safe house* a target can hide in (the mirror of a cyberspace data fortress). See `docs/TODO.md`.
- Housing, investments and crafting (docs/TODO.md's list).
