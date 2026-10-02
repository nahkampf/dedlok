# The City

The physical half of the game: districts, the places in them, and what happens there.
You are in the City or you are jacked in, and a character can be jacked in while their
body stands in a district.

Read MECHANICS.md first for anything that produces a number. Every duration, chance and
modifier named here resolves through the one resolver, and none of the arithmetic lives
in this file.

**Status: designed, nothing built.** Agreed 2026-10-02. Every figure below is
unbalanced.

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
| `districts` | slug (permanent), name, text, `map_x`/`map_y` on the city map, and `travel_weight` (default 1): a multiplier on how long crossing THIS district takes |
| `district_links` | an undirected link between two districts, nothing else. The graph the router walks |
| `locations` | a place in a district: slug, name, kind, `map_x`/`map_y` on the district map, a discovery rule, and for `explorable` a `concealment` |
| `character_locations` | one row per (character, location) meaning *found by exploring* |
| `character_flags` | one row per (character, flag), `set_at`. A flag is a slug and is permanent |
| `city_activities` | one row per character at most: what they are doing right now |

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
  districts entered, ties broken by fewer districts. No route is a refusal, not an error.
- **A travel is one pass per district on the route, origin excluded.** Each pass takes
  `travel_duration` (base **30 seconds** × that district's `travel_weight`, resolved with
  the character's modifiers). **While a pass runs the body is IN that district** —
  `district_id` is set when the pass BEGINS and the route is walked from there, so it is
  never null and always names the district being crossed. Leaving the origin is instant.
  A route A→B→C→D is three passes.
- **The route is snapshotted at the start** and never rewritten, as `runs.chain` is.
- **Two modes.** *Walk* is free and takes the full time. *Hail a pod* takes **half** of
  each pass (applied after the modifiers) and costs **25 creds per district on the
  route**, charged up front through `Wallet::move()` (`Reason::Pod`).
- **Cancelling waits for the pass in progress to finish.** The player is left in the
  district that pass is crossing, which is where `district_id` already says they are, and
  any pod districts not yet entered are refunded.
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

Typed features on a location, a fixed vocabulary like `Drugs\Family`; each kind gets its
own table when it is built, never a JSON bag. First are **shops** (a per-location stock,
paid through `Wallet`) and **clinics** (HP, SI and injury treatment, using
`Character::recover()` and `InjuryLedger`).

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

1. **Foundation.** Schema, `district_id`, the presence rule, the city map and district
   map, `Discovery`, `Flags`, the `Street` → `City` rename (a data migration over
   `notifications.category`), a starter city by migration, and a minimal admin editor for
   districts, links and locations.
2. **Travel and explore.** `Router`, `city_activities`, the settle and its sweep, the three
   stats, the pod fare.
3. **Shops and clinics.**
4. **Narrative encounters**, spawned by travel and explore and fixed at locations.
5. **Physical combat**, after weapons and armour are designed.
6. **Hooks.** Authored quests that set flags, hunters on the street, PvP.

## Not decided

- Whether a district can itself be hidden. Every district is on the map for now.
- What a bounty collected in the City looks like.
- Housing, investments and crafting (TODO.md's list).
