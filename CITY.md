# The City

The physical half of the game: districts, the places in them, and what happens there.
You are in the City or you are jacked in, and a character can be jacked in while their
body stands in a district.

Read MECHANICS.md first for anything that produces a number. Every duration, chance and
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
- **A clinic with surgery has a `clinic_surgeries` row**: a surgeon `rating`, a `max_tier`
  (1 or 2), a price per tier to fit and to remove, and the body regions it will work on
  (`clinic_surgery_regions`). Defined per clinic in the editor. The Back-Alley Clinic
  fits tier 1 into the body, arms and legs and will not touch a head or a spine.
- **A surgery is a timed City activity** (`city_activities.kind = surgery`), seconds
  per tier through `surgery_duration` (300 to fit, 120 to remove), charged up front. **It
  cannot be cancelled**, and a body on the table cannot jack in (`TravelRefusal::UnderTheKnife`).
- **Fitting is a roll**: the clinic's rating against `city.surgery.difficulty` for the
  implant's tier, through `CheckRoller` with its own chance quoted beforehand. **A failure
  costs `injury_per_tier` points a tier** — neural damage for the head and spine, physical
  trauma elsewhere — **and the implant is rejected: it stays in the stash.** Destroying a
  rejected implant is the harsher variant left out. **Removing is a fee and time and no
  roll.**

## Encounters

Two kinds, and they are different engines.

- **Narrative** — a graph of nodes and choices. A choice may carry a `CheckRoller` check on
  a skill or an attribute. Outcomes are data: creds, an item, standing, an injury, heat,
  a flag, a dose.
- **Combat** — NPC rows against the physical engine. **Blocked:** the game has no weapons
  or armour. It needs its own design pass first. Reaching 0 HP uses the existing waiting
  screen.

An open encounter locks the screen, `EnsureRunIsFinished`'s shape.

## Phases

1. **Foundation.** *(built)* Schema, `district_id`, the presence rule, the city map and district
   map, `Discovery`, `Flags`, the `Street` → `City` rename (a data migration over
   `notifications.category`), a starter city by migration, and a minimal admin editor for
   districts, links and locations.
2. **Travel and explore.** *(built, with the editors)* `Router`, `city_activities`, the settle and its sweep, the three
   stats, the pod fare.
3. **Shops and clinics.** *(built)*
4. **Narrative encounters**, spawned by travel and explore and fixed at locations.
5. **Physical combat**, after weapons and armour are designed.
6. **Hooks.** Authored quests that set flags, hunters on the street, PvP.

## Not decided

- Whether a district can itself be hidden. Every district is on the map for now.
- What a bounty collected in the City looks like.
- Housing, investments and crafting (TODO.md's list).
