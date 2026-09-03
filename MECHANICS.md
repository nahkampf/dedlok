# MECHANICS.md

How numbers work in this game: what a stat is, what can change one, and the
recipe for adding items, skills and effects without touching the maths.

Read this before adding anything that produces or alters a number.

---

## Status

**The stat/modifier system described here is a specification, not working code.**
Nothing in `app/` implements it yet. This file is the agreed design, written
down so the implementation has something to be checked against.

| Piece | State |
| --- | --- |
| Attributes on `characters` (10 columns, 0–100 in the DB) | **built** |
| Attribute clamping on save | **built**, at the lifetime cap of 100 |
| Archetypes + perks as rows (`archetypes`, `archetype_perks`) | **built**; `characters.archetype` is a real foreign key |
| Live perks routed through the resolver — Netrunner's "Grey matter", Solo's "Scar tissue" | **built** |
| Display-only perks | one kept deliberately as the documented example; the rest removed |
| `hitpoints_max` and `synaptic_integrity_max` as stats | **built**, formulas in `config/stats.php` |
| `ModifierSource` + `ArchetypePerks` | **built** |
| `items` + `inventory_items` tables, two containers | **built**, with constraints |
| Skillbooks and injection (`App\Inventory\SkillInjector`) | **built**, the only writer of a new `character_skills` row |
| Containers — items holding items (`App\Inventory\ItemPlacer`) | **built**, one level deep, capacity enforced |
| Item names and flavour | **built** — columns on `items`; the shared vocabulary is in `config/strings.php` |
| Character creation point pool | **built** |
| `Modifier`, `Op`, `Target`, `Stat`, `StatRegistry`, `Resolver` (`app/Stats/`) | **built** — pure, no DB, 38 unit tests |
| `config/stats.php` — the 10 attributes, tagged and bounded | **built** |
| Conditions on modifiers | **specified only** — a `condition` key is rejected, not ignored |
| Derived stats and the dependency graph | **specified only** |
| `current_hitpoints`, `current_synaptic_integrity` as resources | **built**, with clamping |
| `skills`, `skill_prerequisites`, `character_skills`, `training_queue` | **built**, with constraints and `skills:validate` |
| Skill thresholds and SP/minute (`App\Skills\`) | **built**, pure and config-tunable |
| Skill training — the deposit, the scheduler, the queue (`App\Skills\TrainingDeposit`) | **built**, with `training:deposit` on a one-minute schedule |
| `SkillLevelReached` / `TrainingEntryCompleted` events | **built**, dispatched; no listeners yet |
| `skill_effects` and skills as a `ModifierSource` | **specified only** |
| `character_modifiers` table | **specified only** |
| Item *modifiers* — items that change a stat | **specified only**; items exist, none of them alter a number yet |
| Implants, drugs, injuries | **specified only** |
| The market's supply — `items.rarity`, `items.seed_quantity`, `market_stock`, `market_purchases` | **built**, with `market:seed` on an eight-hour schedule |
| The market itself — a storefront, a price, a transaction | **not built**; nothing spends money yet |
| Cyberdeck hardware — `items.hardware`, `deck_slots`, `deck_modules` | **built**, with constraints and the item editor |
| Fitting a module into a deck a player owns | **not built**; nothing is installed anywhere yet |
| Anything a deck's numbers DO — qFLOPS, quantabits, bandwidth, a power budget | **specified only**; stored as bases, read by nothing, and resolved rather than read when they are |
| Inventory capacity — an overall limit per inventory | **specified only**; `weight` is stored and unenforced |

Do not describe anything below as working until it appears in the table above.

---

## The core idea

One pure function does all the arithmetic:

```
resolve(statId, baseValue, Modifier[], context) → ResolvedStat { value, raw, breakdown }
```

No database, no Eloquent, no randomness. Everything that changes a number —
a skill, an implant, a drug, an injury, an archetype perk, a piece of gear,
the weather — is converted into a `Modifier` **before** it reaches the resolver.

That one constraint is what makes the system extensible: adding a new *kind* of
source never touches the maths, and the maths can be tested without a database.

---

## Stats

A stat is a named number. Every stat is declared once, with tags:

| Stat id | Tags | Notes |
| --- | --- | --- |
| `focus` | `attribute`, `mental` | stored on `characters` |
| `muscle` | `attribute`, `physical` | stored on `characters` |
| `hitpoints_max` | `derived`, `vital` | computed, never stored |
| `skill.hacking` | `skill`, `mental` | stored per character |
| `check.intrusion` | `check`, `mental` | derived from attribute + skill |

Tags exist so a modifier can target a **group** without listing members.
`-10% to all mental attributes` is one modifier, not six, and it keeps working
when an eleventh attribute is added.

### Derived stats vs resources

These look alike and behave completely differently. Getting them confused is
the most expensive mistake available here.

- **Derived stat** — computed on read, **never stored**. `hitpoints_max`,
  `synaptic_integrity_max`, `check.intrusion`. Storing one guarantees it goes stale.

  Formulas live in `config/stats.formulas` as data, not code:

  ```php
  'hitpoints_max' => ['meat' => 1, 'muscle' => 2, 'focus' => 2],
  ```

  Each term is `ceil(attribute / divisor)`, so that reads *meat + half of
  muscle + half of focus, each rounded up*. They are data because the
  character-creation screen recomputes them in the browser on every slider
  move; both sides read this array, so the formula exists once and the two
  cannot disagree. `DerivedStatTest` asserts the page ships the config values
  verbatim rather than restating them.
- **Resource** — stored state, bounded by a derived stat. `current_hitpoints`,
  `current_synaptic_integrity`, both columns on `characters`. These are the only
  numbers on that table that are both mutable and saved.

**Naming rule:** a derived ceiling ends in `_max`; its stored counterpart is
prefixed `current_`. `synaptic_integrity_max` is computed from attributes;
`current_synaptic_integrity` is stored and clamped down by it. A bare
`synaptic_integrity` would hide which of the two it is, which is the confusion
this whole section exists to prevent.

`Character::RESOURCES` maps each column to the stat that bounds it, and is the
single place that pairing is written down.

**The clamp rule, enforced by the model:**

- A new character starts at full. The columns are nullable so that `null`
  ("not yet initialised") stays distinguishable from `0` ("dead") — a default
  of `0` would have collapsed the two.
- On every save, resources are clamped into `0 .. maximumFor(...)`, **after**
  the attribute clamp, because changing an attribute moves the ceiling.
- Clamping is **down only, never scaling**. Raising max HP does not heal a
  wounded character; lowering it does not heal them either. `CharacterResourceTest`
  pins both directions.

When a derived maximum drops (an implant is removed), the resource **clamps
down. It never scales.** Losing an implant must not heal you.

---

## Modifiers

```php
[
    'op'        => 'percentage_of_base',   // see the table below
    'amount'    => -10,
    'target'    => ['id' => 'focus'],      // or ['all_of' => ['attribute', 'mental']]
    'condition' => null,                   // optional, see Conditions
]
```

`target` accepts either an explicit `id` or a tag selector. Nothing else.

### Operations and order

Every modifier belongs to a layer. Layers always resolve in this order:

| # | Op | Combines by | Example |
| --- | --- | --- | --- |
| 1 | `add` | sum | `+2 focus` from a skill rank |
| 2 | `percentage_of_base` | **summed, applied once** | `-10% focus` from an implant |
| 3 | `multiplier` | product | `×0.5` from a hard debuff |
| 4 | `override` | highest priority wins | `focus = 0` while unconscious |
| 5 | clamp | stat definition | attributes to `0..100` |
| 6 | round | stat definition | once, at the very end |

Layers 1–3 are commutative, so evaluation order inside a layer cannot change
the result. Only `override` carries a priority, because it is the only op that
is not commutative. **A unit test asserts this**; if you add an op, it must
either be commutative or live in the `override` layer.

### `percentage_of_base` applies to base + flat

This is the single most important rule in the file, and the name is slightly
misleading, so read it twice.

> `percentage_of_base` is a percentage of **(base + all `add` modifiers)**,
> not of the stored base alone. "Base" in the name means *the base of the
> percentage calculation*, which is the value after flat bonuses.

All percentages are **summed and applied once**, never compounded. Three
`+50%` effects produce `×2.5`, not `×3.375`. Use `multiplier` only when
compounding is genuinely intended, and expect to justify it in review.

Worked example — Focus, base 15, with a `+2` skill rank and two `-10%` implants:

```
base                     15
+ add        (+2)        17          base + flat
+ percentage (-10 -10)   17 × 0.80 = 13.6
+ multiplier (none)      13.6
+ clamp      (0..100)    13.6
+ round                  14
```

### Clamping: negative-then-clamp

Values may go **negative or above the ceiling while resolving**. Clamping
happens once, at the end.

This is not a detail. Clamping mid-pipeline would make order matter: `-20`
then `+5` gives `5` if you clamp early and `0` if you clamp late. Late
clamping is what makes layers 1–3 commutative, which is what the whole design
rests on. Never clamp inside a layer.

`ResolvedStat` exposes both:

- `raw` — uncapped, may be negative. For diagnostics and for chained maths.
- `value` — clamped and rounded. **What game logic uses.**

### Ceilings

| Bound | Value | Enforced by |
| --- | --- | --- |
| Attribute floor at character creation | **1** | validation + slider `min` |
| Attribute cap at character creation | 50 | validation + slider `max` |
| Attribute floor for the lifetime of a character | 0 | stat definition, `tinyint unsigned` |
| Attribute cap for the lifetime of a character | 100 | stat definition + MySQL `CHECK` |

Both bounds are tighter at creation than in play, and for different reasons.
The ceiling is progression: a character grows from 50 toward 100. The **floor
is a trap**: an attribute of 0 gives `sp_per_minute = 0` for every skill it
governs, so a character created with a zeroed attribute could never finish
training those skills. Play may still reduce an attribute to 0; starting there
is what is forbidden.

A consequence worth knowing: with a floor of 1 per attribute, a pool below ten
points cannot be spent at all. `Character::poolIsSatisfiable()` checks both
ends, and the creation screen refuses to render a form that can never balance.

These are two separate constants, and conflating them is a live bug rather
than a tidiness issue:

- `Character::ATTRIBUTE_MAX` = **100**, the lifetime bound. Used by the clamp on
  save and by `config/stats.php`. Matches the `CHECK (focus <= 100)` constraints
  on the table, so the storage layer enforces the same number.
- `Character::CREATION_ATTRIBUTE_MAX` = **50**, used by the sliders, the
  creation validator (`creationAttributeRules()`) and the starting spread.

Clamping on save to 50 would silently truncate any attribute grown past 50 in
play. `CharacterCreationTest` asserts both ceilings and both behaviours.

---

## Conditions

**Not implemented yet.** `Modifier::fromArray()` rejects a `condition` key
rather than ignoring one, so a condition written today fails loudly instead of
silently applying unconditionally.

When it lands, conditional modifiers will reference a **named predicate**, never
an inline expression:

```php
'condition' => ['when' => 'hp_below_pct', 'args' => ['pct' => 30]],
```

Predicates live in a registry and are resolved against a context object at
evaluation time.

The cost is real and worth planning for: **conditional modifiers cannot be
cached the same way as unconditional ones**, because they depend on state that
changes per check. The modifier bag is split in two — context-free modifiers
resolve once and cache, context-dependent ones layer on at check time. Do not
add a condition to something that could be unconditional.

---

## Where things live

Mechanics in `config/`, instances in the database, and words wherever the
thing they describe already lives — on the row if it has one, in
`config/strings.php` if it does not. Same split the rest of the project uses.

| What | Where |
| --- | --- |
| Stat definitions: ids, tags, formula, clamps, rounding | `config/stats.php` |
| Implant / drug / effect definitions | `config/implants.php`, `config/effects.php`, … |
| **Item and skill definitions** | the `items` and `skills` tables — the documented exception, see below |
| Market restock maths — rarity chances, the demand window and curve | `config/market.php` |
| Player-visible words with no row of their own | `config/strings.php` |
| What a given character actually has | `character_modifiers` table |

`character_modifiers` holds `character_id`, `definition_key`, `source_type`,
`source_id`, `stacks`, `expires_at`, and a `data` JSON column for per-instance
values (a rolled quality, a remaining charge).

**Definition keys are permanent.** Rows in the database reference them, so a
deleted key orphans live data. Retire a definition with a `retired => true`
flag; never remove or renumber one. The same rule already applies to archetype
ids — and is now enforced, since `characters.archetype` is a foreign key into
`archetypes`.

---

## Skills

Skills are the **one exception** to "mechanics in config": their definitions
live in the database, rows and all, including `title` and `description`. There will be hundreds of them, the market has to query them,
and prerequisites are a graph.

That exception has a price, and it is worth being explicit about: in config a
bad definition fails a test, but in the database a bad row is just a row. The
replacement is two-layered.

**What SQL can express is a constraint:**

| Constraint | Catches |
| --- | --- |
| `rank IN (1,2,4,8,16)` | an invented rank |
| `primary/secondary_attribute IN (...)` | a misspelled attribute |
| `required_level BETWEEN 1 AND 5` | an out-of-range prerequisite |
| `skill_id <> required_skill_id` | a skill requiring itself |
| `unique(character_id, position)` | two queue entries in the same slot |
| `unique(character_id, skill_id, target_level)` | the same level queued twice |
| `unique(skill_id, required_skill_id)` | the same prerequisite listed twice |

**What it cannot is `php artisan skills:validate`:** prerequisite cycles, an
`attained_level` that disagrees with `skill_points`, points above the rank's
cap, gapped training queues. Run it in CI and after any bulk edit: the database
is the only thing holding those invariants, so nothing else will notice.

### Drawing the graph

`/skills` renders the whole catalogue as one collapsible section per category,
from `App\Skills\SkillTree`. Only the first opens: a page that starts as a wall
of every skill in the game is not scannable.

**It stopped being a tree, and it is better for it.** Prerequisites form a DAG,
not a tree — a skill may require several others, and the seeded catalogue
already has one that requires two. Nesting could draw only one of those edges
and had to explain the rest in words. Once the categories became sections, the
nesting and the section were saying the same thing anyway, and the nesting was
the one costing indentation a phone does not have.

- Every skill states **all** of its prerequisites, each in its own box. A box
  can be repeated; an edge cannot.
- **A section is alphabetical.** It is a place to look a skill up, and looking
  one up wants its name where you expect it. Depth is still walked while
  building — that walk is what catches a cycle — but it orders nothing and is
  not carried on the node.
- A prerequisite in **another category** is named with the category on it, and
  moves nothing: the skill stays in its own section.
- A **cycle** makes every skill in it unreachable. The builder refuses to walk
  a skill it is already walking, marks what it finds, and renders the rest;
  `skills:validate` is what explains it.

**Rank is text, not colour.** It reads as `×4` in the meta column. Mapping it
onto the kit's `t1`–`t5` accents was tried and reverted: `.item-row` paints its
left border from `--tier`, `.t2` is `--jade`, and `--ok` IS `--jade` — so a
rank-2 skill drew a green stripe exactly where the overlay's green means "you
have this", in the place the eye reads the rail.

**What a signed-in player has is laid over the top**, never built into the tree:
a corner tick on skills they have injected and a five-segment meter of levels
attained. It arrives as one `skill_id => level` map, so the arrangement cannot
depend on who is reading it — two players must not see different shapes for the
same catalogue. A guest sees the catalogue with no overlay at all.

**The tick keys off presence in that map, never the level.** Level 0 is a real
state — injected, untrained — and reading the level to decide would hide a
skill the moment it was learned.

Skills the player does not have are **half-lit until pointed at**, so the ones
they do have carry the page. Dimming is half of the overlay and needs a viewer:
a guest sees the catalogue at full strength, because "you do not have this"
means nothing without a you. A signed-in player who has injected nothing does
see every row dimmed — that is the same statement, and it is true.

### What happens to time past the target

An entry stops at its target level **only when something else is queued**. The
surplus has somewhere better to be, and `completeEntry` hands the successor the
exact completion moment so none of it is lost in the handover.

With an **empty queue behind it**, the segment runs to the end of the minute the
threshold fell in, and no further. The minute a player is part-way through is
credited whole — they trained all of it — and the minutes after it are not,
because by then nothing was queued to train.

That distinction is invisible while deposits run every minute: the crossing
minute is the current one. It is the whole behaviour on a **catch-up run**,
where `now` can be hours past the threshold and must not become hours of
training for a queue that emptied in its first minute.

**The rank's level-5 cap bounds it either way**, and that is enforced on the
award (`min($award, $cap - $points)`) rather than by computing the moment the
cap is reached — a rate change inside the segment would let such a moment drift
off the real ceiling.

Note the asymmetry this creates, because it is deliberate: queueing two skills
trains the second one with the surplus, while queueing one trains the first one
further. Neither wastes the time; they just spend it differently.

### The training queue

**A queue entry is a (skill, target level) pair, not a skill.** The same skill
appears once per level being trained, and entries from different skills
interleave. Holding Cryptography 3 and aiming at Codebreaking I, which needs
Hacking III and Cryptography V:

```
1. Hacking I        4. Cryptography IV
2. Hacking II       5. Cryptography V
3. Hacking III      6. Codebreaking I
```

Cryptography I to III are absent because they are already held. Position 1 is
what trains now.

The split matters: `character_skills` holds **progress** (one row per skill,
forever), `training_queue` holds **intent** (many rows per skill, one per
level). An earlier design put a `queue_position` on `character_skills` and
could not express any of the above — one position per character+skill makes
"Hacking I, II, III" unrepresentable.

The training clock lives on the queue entry rather than the skill, because it
belongs to the session: when an entry completes it is deleted and the next
inherits the clock at the exact completion moment, so no accrual time is lost.

**Three rules that no single row can show**, checked by
`App\Skills\TrainingQueueValidator` and by `skills:validate`, which simulate
the queue forward from the character's current skills:

1. **Levels are consecutive.** `target_level` must be exactly one above what
   the character will hold by then. Skipping means the levels in between never
   train.
2. **Prerequisites are judged where the entry starts**, not where it was added.
   Codebreaking I is valid at position 6 precisely because Hacking III and
   Cryptography V complete at positions 3 and 5. The same six entries in a
   different order are invalid.
3. **Positions are gapless 1..n.**

### Points, levels and rank

Levels come from points, and both are stored:

```
multiplier(L)        = round(0.8 * 5.95^(L-1))   ->  1, 5, 28, 169, 1003
threshold(L, rank)   = 242 * rank * multiplier(L)
```

so a rank 1 skill needs 242 / 1,210 / 6,776 / 40,898 / **242,726** points, and
rank is a flat training-time multiplier on every level. Thresholds are
**cumulative** totals, not per-level increments — reaching level 3 means having
6,776 points in total, not 6,776 on top of level 2. Points accrue and are never
spent, and stop at the level-5 threshold — overspill past the cap is discarded.

`attained_level` is denormalised from `skill_points` **on purpose**. It is safe
only because the minute deposit is the single writer and updates both together;
anything else writing `skill_points` directly leaves the level stale and
invisible, which is what `skills:validate` exists to notice.

### Training speed

```
sp_per_minute = 60 * (0.121 * primary + 0.0762 * secondary)
```

on the **resolved** attributes, so an implant or drug that moves a governing
attribute changes training speed with no special handling. A character with
15/15 trains a rank 1 skill to level 5 in about 23 hours; 50/50 does it in
under 7. Both coefficients and the whole curve are in `config/skills.php`, and
`App\Skills\TrainingRate` is the only thing that reads them.

**Zero in both attributes gives zero SP/minute — training never completes.**
This is why the creation floor is 1 rather than 0. It remains reachable in play
if something drives an attribute to zero, and the deposit handles it by marking
the entry stalled and leaving the clock where it is, rather than looping.

### The deposit

`App\Skills\TrainingDeposit` is the only thing that writes `skill_points`.
`training:deposit` runs it every minute from `routes/console.php`, driven by the
`scheduler` container — deliberately not the web container, because a heartbeat
that only beats while someone is browsing is not a heartbeat.

**It is not a per-minute tick.** Each call advances a character from their own
stored clock to now in a single pass, so a run after an outage credits exactly
what a run every minute would have. That is the property the whole design rests
on, and `TrainingDepositTest` asserts it directly: eight hours caught up in one
pass equals 480 one-minute runs, to the point.

The rate is piecewise-constant and every piece boundary is knowable in advance
— nobody plays during an outage, so the only things that can change mid-gap are
time-driven. The gap is split at those boundaries and each segment is computed
in bulk, so an eight-hour outage costs a handful of steps rather than 480.
`rateChangeBoundaries()` returns nothing today because archetype perks are
permanent; timed modifiers plug in there, and a drug expiring mid-gap must split
the segment or its bonus is paid for the whole outage instead of its last ten
minutes.

Fractional points are never discarded. A segment awards whole points and moves
the clock only by the time those points actually cost, carrying the sub-point
remainder into the next run. **This is why `last_deposited_at` is stored to the
microsecond.** At whole-second precision the carry is itself rounded away: a
deposit at 118.32 SP/min pays for 59.84 seconds and the clock moves a full 60,
losing a third of a point every run — 1,440 times a day, which made being
offline pay better than being online.

The rest of the contract:

- **Completion hands the clock over.** A finished entry is deleted, the queue is
  resequenced, and the successor inherits the exact completion instant, so the
  remainder of the gap keeps accruing instead of being lost in the handover.
- **One event per level, in order.** A gap spanning three breakpoints dispatches
  three `SkillLevelReached` events, not one claiming level 3.
- **Accrual stops at the cap.** Overspill past level 5 is discarded, so the
  column has a real upper bound.
- **A re-run inside the same minute is a no-op**, which is what makes the
  schedule safe to retry.

## The market

Only the supply side exists: what the market carries, and how it decides to
carry more of it. Nothing sells anything yet — there is no price paid, no
transaction, no money.

Two columns on `items` and two tables:

| What | Where | Means |
| --- | --- | --- |
| `items.rarity` | `common`, `uncommon`, `rare`, `ultra_rare` | how often the market gets a delivery |
| `items.seed_quantity` | 0 by default | how many a full shelf is; 0 means never stocked |
| `market_stock` | one row per item | what is on the shelf now |
| `market_purchases` | one row per purchase, forever | demand |

`App\Market\MarketSeeder` runs every eight hours (`market:seed`, scheduled in
`routes/console.php`) and, for each item that is `on_market` with a
`seed_quantity` above zero:

```
demand     = units bought in the last 7 days           (market_purchases)
ratio      = demand / seed_quantity
target     = ratio < 0.5
               ? seed_quantity
               : seed_quantity * min(ratio / 0.5, 4)
added      = roll(1..100) <= chance(rarity)
               ? max(0, target - stock)
               : 0
```

with `chance` being 100, 70, 20 and 5 percent for the four rarities. Everything
above the item's own two columns is in `config/market.php`.

The properties that matter:

- **It tops up, it does not add.** Stock the players did not buy still counts,
  so an unpopular item cannot pile up pass after pass.
- **The demand branches meet at the threshold.** `ratio / threshold` rather
  than `ratio` is what makes the multiplier exactly 1 at the moment demand
  becomes interesting. Using `ratio` directly would put a step in the middle of
  the curve, where one extra sale in a week doubles the shelf.
- **The multiplier is capped**, because a run on an item otherwise feeds
  itself: a deeper shelf is bought out harder, which is more demand.
- **The rarity roll is all or nothing.** A failed roll adds *nothing*, rather
  than adding less. Scarcity a player can feel is a shelf that is usually
  empty, not a shelf that is always slightly short.
- **A pass is not idempotent and is not caught up.** Unlike the training
  deposit there is no stored clock to advance from: a missed pass is simply a
  shelf that stays as it is, and a pass run twice is two deliveries. That is
  what `withoutOverlapping()` on the schedule is protecting.
- **Nothing is transactional across items.** Each item reads the shelf it
  finds, so a pass that dies half way leaves a coherent market and the next one
  finishes the job.

## Cyberdecks

A cyberdeck is an item, and so is every module that goes in one. What makes
them hardware is one column and two tables.

| What | Where | Means |
| --- | --- | --- |
| `items.hardware` | `deck`, `cpu`, `npu`, `ram`, `nic`, `expansion`, `psu`, or NULL | what the item IS |
| `deck_slots` | one row per socket on a deck | what a deck PRESENTS |
| `deck_modules` | one row per module item | what a module is WORTH |

`items.hardware` is the only place the kind is written down. `deck_modules`
deliberately does not repeat it: a row that could disagree with its item about
being a CPU is a row every reader has to defend against.

### The sockets

Fourteen (kind, variant) pairs are the whole vocabulary, and
`App\Decks\SlotType` is where they are written down — the editor renders one
counter per type, the request validates against the same list, and
`App\Decks\SlotLayout` counts by it.

| Kind | Variants | Cap per deck |
| --- | --- | --- |
| `cpu` | Socket G (448-pin), Socket K (624-pin), Socket Z (962-pin) | 4 |
| `npu` | — | 4 |
| `ram` | Opto-electrical, QDRAM, **Universal** | 8 |
| `nic` | — | 4 |
| `expansion` | XGate, PEI, Horizon | 5 |
| `psu` | — | 2 |
| `io` | Electrode, NeuraLink | 1 of each |

**A cap is on the kind, not on each of its variants.** Five expansion slots
means five altogether, not five of each — which is why it cannot be a CHECK
constraint: a deck's sockets are separate rows, and no constraint on one of
them can count the others. `ItemRequest::withValidator()` is where that,
"a deck needs a CPU socket" and "a deck needs a power socket" live. The last
two are game design rather than data integrity, and are a line each to remove.

**`Universal` is a socket and never a module.** A RAM slot may take either kind
of stick; no stick is both. That asymmetry is the reason a slot's variant and
a module's variant are checked against separate lists, and the reason fit is
`Variant::accepts()` rather than `===`.

**An IO port seats nothing.** The electrode and neuralink ports are where the
deck reaches the person using it. They are rows rather than two booleans on
`items` because "what interfaces does this deck present" is one question, and
answering half of it with rows and half with flags means every screen that
lists a deck's hardware reads two shapes and joins them by hand.

### The modules

| Kind | Socket it wants | Measured by | Draws power |
| --- | --- | --- | --- |
| `cpu` | Socket G / K / Z | `qflops` | yes |
| `npu` | the NPU socket | `qflops` | yes |
| `ram` | Opto or QDRAM (or a Universal socket) | `quantabits` | yes |
| `nic` | the NIC socket | `bandwidth`, in QB/s | yes |
| `expansion` | XGate, PEI or Horizon | *nothing yet* | yes |
| `psu` | the power socket | `watts` — what it SUPPLIES | no |

**At most one measure per module, enforced by the schema.** They are four
nullable columns rather than one generic `rating` because qFLOPS, QB, QB/s and
watts do not convert into one another, and a single column would need a unit
column beside it to mean anything. An expansion card fills none of them: what
an expansion card does is undecided, and a made-up number now is a number to
migrate away from later.

**A supply gives power and draws none** — `chk_deck_modules_supply`. The other
side of that sum, a deck's power budget, is not built: nothing is fitted into
anything yet, so nothing has a budget to spend.

### Deck numbers are bases, not values

Nothing reads a deck's numbers yet. When something does, none of them is read
straight off the row:

- **Every measure is the base of a resolved stat** — `qflops`, `quantabits`,
  `bandwidth`, `watts` and `power_draw` alike. Implants, skills, drugs and
  injuries are all expected to move them, so each resolves through
  `App\Stats\Resolver` with the stored column as the base and everything else
  as ordinary modifiers, in the layer order the rest of the system uses. None
  of it is a special case living in deck code.
- **So is what a program costs to run.** Programs consume memory and compute,
  and those figures are modified too — a character's training is as likely to
  change what a program costs as what the deck provides. Supply and demand are
  the same kind of number and go through the same resolver.
- **Which makes a deck's totals per character, not per item.** The same deck in
  two people's hands is two different sets of numbers. Nothing may cache a
  total on `items`, on `deck_modules`, or on an inventory row — derived stats
  are never stored, which is the rule the vitals already follow.
- **A deck may present more sockets than its supplies can feed**, and several
  do. Over-commitment is a choice the player balances, not a state the content
  prevents: a deck whose maximal loadout exceeds two of the best supply is
  working as intended and is not a row to correct. The Sturmlicht is the
  deliberate case, one supply socket in front of a Socket Z. Skills that cut
  draw are part of how that is meant to be solved.

Two things it needs when it is built: a stat id per measure in
`config/stats.php`, and a `ModifierSource` over the hardware a character has
fitted — the shape `App\Stats\Sources\ArchetypePerks` already sets.

### What is not built

Fitting a module into a deck a player owns. That needs a row joining an
inventory row to a `deck_slots` row, and it is the reason sockets are rows
rather than counts — a count cannot be pointed at. Two things follow it when
it arrives:

- `App\Decks\HardwareEditor` replaces a deck's sockets wholesale, the way
  skill prerequisites are replaced. Once a socket can be occupied, that has to
  become a diff.
- Decks are already refused permission to stack, for the same reason a
  container is. Modules are not, and will have to be at the same time.

**One deck is equipped; the rest are luggage.** A character may own several
decks and keep modules fitted in all of them, but only one is live at a time,
and that is what decides which numbers get resolved:

- **The equipped deck resolves.** Its totals go through the modifier system,
  per character, as *Deck numbers are bases, not values* describes.
- **Every other deck shows its base stats** — the plain sum of what is fitted
  in it, with no modifiers applied. Nothing about a deck in the stash depends
  on who owns it, so nothing about it needs the resolver.
- The sum is therefore the resolver's INPUT, not a second implementation of
  it: one function totals a deck's fitted modules, the stash renders that, and
  the equipped deck renders the same number after it has been through the
  resolver. There is no path where a total is computed twice by two different
  pieces of code.
- **"Equipped" wants to be a column on `characters`**, a nullable foreign key
  to the inventory row, rather than a flag on `inventory_items`. One column
  can only hold one value, so "only one deck at a time" stops being a rule
  anybody has to enforce — and the alternative is not available anyway, since
  the flag version needs a partial unique index and MySQL 8.0 has none. Null
  means jacked out, which is a real state.

None of the numbers mean anything yet. `qflops`, `quantabits` and `bandwidth`
are stored, validated and read by nothing — no stat, no check, no modifier. A
deck does not make its owner better at anything today.

## Recipes

Every recipe below is data — a config entry, or a row. **If adding content
requires new PHP, the design has leaked and the op set needs revisiting
instead.**

### Add an item

**Item definitions live in the database, not in config.** They are the second
exception to the mechanics-in-config rule, taken for the same reasons skills
were:
there will be hundreds, the market has to query them, and a skillbook has to
point at a skill row. `config/items.php` does not exist and is not planned.

1. An `items` row: `slug`, `container` (`physical` or `data`), `tier` 1 or 2, and
   whatever mechanical values apply. `database/seeders/ItemSeeder.php` is where
   the catalogue is kept.
2. Its `name`, and `flavor` if it has any — columns on the same row. Tier names
   are shared (`strings.items.tiers.1`–`2`) and never repeated per item.
3. If the market sells it: `rarity` and `seed_quantity` — see below.

**Tier is not rarity.** They are two axes and it is worth keeping them apart:
`tier` is what the thing IS — Tier One or Tier Two, and no finer than that —
it colours the card through `.t1` and `.t2`, and it carries no mechanics.
`rarity` is how often the market has one. Standard-issue ammunition is common;
a tuned bike part nobody makes any more is not.

**The scale was five and is now two.** Standard, Tuned, Milspec, Prototype and
Anomalous named a hierarchy nothing mechanical ever read, and the rows that
carried it were clamped (1 stays 1, everything above became 2) rather than
rescaled — there was no number to preserve. The UI kit still defines the full
`.t1`–`.t5` accent ramp; the game reaches the first two steps of it. Widening
the scale again means widening `Item::MAX_TIER`, the check constraint on
`items.tier`, and `strings.items.tiers` together.

**An item is stocked when it has both `on_market` and a `seed_quantity` above
zero.** They answer different questions — whether the thing is sold at all, and
how many of it a full shelf is — and the default shelf size is 0, so nothing
reaches the market by being forgotten.

**An item that holds other items** needs two more values on the row:
`capacity` (how much it holds; 0 means it is not a container) and `accepts`
(which `container` kind may go in it). A memory shard is `physical` with
`accepts: data` — matter that holds bits. The pair is constrained in both
directions, so an item cannot claim capacity without a kind or a kind
without capacity. Depth is capped at one: containers do not nest.

**Skillbooks need neither step.** There is exactly one book per skill, so
`ItemSeeder` generates them from the skills table and their name composes from
the skill's own title through `strings.items.skillbook.name`. Adding a skill
adds its book, named for free.

### Skillbooks and the training gate

A skill cannot be trained until its book has been owned and spent.

- A `character_skills` row means **this skill has been injected**, and
  `App\Inventory\SkillInjector` is the only thing that creates one. Injecting
  consumes one copy of the book and writes the row at 0 points, level 0, in a
  single transaction.
- **Level 0 is a real state**: injected, not yet trained. That is why the gate
  is a row-exists check and never a level check.
- Injecting a skill already on the sheet is **refused**, not repeated — it
  would either wipe the points or do nothing, and the player keeps the book.
- `TrainingDeposit` will **not** create the row. A queue entry naming an
  uninjected skill is a data error: the deposit refuses and logs it, the queue
  validator reports it, and `skills:validate` fails on it.

### Add an item that changes stats

Items carry no modifiers yet — this is the shape they will take.

1. `config/items.php` — add the definition:

   ```php
   'kiroshi_optics' => [
       'slot'      => 'ocular',
       'stacking'  => 'unique',       // sum | highest | unique | unique_by_source
       'duration'  => null,           // null = permanent while equipped
       'modifiers' => [
           ['op' => 'add', 'amount' => 4, 'target' => ['id' => 'perception']],
           ['op' => 'percentage_of_base', 'amount' => 10, 'target' => ['all_of' => ['check', 'perception']]],
       ],
   ],
   ```

2. `name`, and `flavor` if it has any, on the same row.

### Add a skill

A skill is both a **stat** (the character has a level in it) and potentially a
**source of modifiers** (having it grants something).

1. `config/stats.php` — declare `skill.<name>` with tags `['skill', …]`.
2. `config/stats.php` — declare the check it feeds, e.g.
   `check.intrusion = skill.hacking + attribute.intellect`, tagged
   `['check', 'mental', 'intrusion']`.
3. The skill's own row carries its `title` and `description` — there is no
   file to add them to.
4. If ranks grant more than their level, add `modifiers` to the skill
   definition the same way an item does.

Skill checks resolve through the same pipeline: the check stat is resolved
with the character's modifiers plus any context-dependent ones, and the result
is compared against a difficulty. There is no separate skill-check maths.

### Add a timed effect (a drug, a poison, a buff)

1. `config/effects.php`:

   ```php
   'boost' => [
       'duration'   => 600,      // seconds
       'stacking'   => 'highest',
       'max_stacks' => 1,
       'modifiers'  => [
           ['op' => 'percentage_of_base', 'amount' => 25, 'target' => ['id' => 'reflex']],
           ['op' => 'add', 'amount' => -5, 'target' => ['id' => 'focus']],
       ],
   ],
   ```

2. `config/strings.php` under `effects` — name, description, and the message
   shown when it expires. An effect has no row of its own to carry them.
3. Applying it inserts a `character_modifiers` row with `expires_at`.

Expiry is **lazy**: rows are filtered on read by `expires_at > now()`. A
scheduled sweep only keeps the table small. Do not write a job that ticks every
character every second.

### Add an archetype perk

Perks exist in two shapes while the migration runs, and the shape decides
whether the perk does anything:

A perk is a row in `archetype_perks` pointing at an `archetypes` row. The
database refuses one that is both shapes or neither.

**Live** — carries `modifiers` (a JSON list), produces real `Modifier` objects,
and its card text is generated from them so display cannot drift from
behaviour:

```php
ArchetypePerk::create([
    'archetype_id' => 2,
    'key' => 'scar_tissue',
    'label' => 'Scar tissue',
    'modifiers' => [
        ['op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'hitpoints_max']],
    ],
]);
```

**Display-only** — the older shape, now `display_modifier` / `display_unit`.
Renders on the card and **changes nothing**:

```php
['key' => 'damage_taken', 'display_modifier' => -15, 'display_unit' => 'percent']
```

Two perks are live: Netrunner's **Grey matter** (`+5% synaptic_integrity_max`)
and Solo's **Scar tissue** (`+5% hitpoints_max`). Exactly one display-only perk
survives — Solo's `damage_taken` — kept so the legacy shape stays documented and
covered by tests. Delete it once every perk is live.

The other four archetypes have no perks at all. Add new ones as **live**;
there is no reason to write a display-only perk now that the resolver exists.

`CharacterCreationTest::test_every_archetype_perk_is_valid_and_labelled`
checks both shapes and fails if a live perk targets a stat that is not declared.

Either way the label goes in the perk's own `label` column — except a perk
whose `display_unit` is `attribute`, which leaves it null and borrows the
attribute's name from `character.names.<key>`. See CLAUDE.md for the display
rules (signed values, U+2212 minus, neutral colour).

**The roster is inserted by its migration, not a seeder.** Its ids are written
into character rows, so it has to exist the moment the schema does — including
in a freshly migrated test database. `Archetype::roster()` reads it once per
request and caches it, because stat resolution asks for a character's perks
every time it runs; any write to either table drops that cache.

### Add an attribute

1. `Character::ATTRIBUTES` and the relevant group constant.
2. A migration adding the column **and** its `CHECK (<= 100)` constraint.
3. `config/stats.php` — id, tags, clamps.
4. `config/strings.php` — `character.names.<attribute>` and
   `character.descriptions.<attribute>`.
5. `php artisan test`. Existing tests cover three of the four steps and will
   fail if any is missed:
   - `CharacterTest::test_the_model_and_the_schema_agree_on_the_attribute_list`
     (model vs migration)
   - `CharacterCreationTest::test_every_attribute_has_a_description_string`
     (`character.descriptions`)
   - `CharacterCreationTest::test_it_shows_every_archetype_and_attribute`
     (`character.names`)

   Nothing yet checks `config/stats.php`, because it does not exist. Add that
   assertion when it does.

---

## Rules and traps

- **Never clamp mid-pipeline.** It breaks commutativity. Clamp once, at the end.
- **Never round mid-pipeline.** Rounding error compounds. Round once, at the end.
- **Never store a derived stat.** It will go stale, and nothing will tell you.
- **Never persist a resolved value** except as cache with an invalidation key.
- **Never delete a definition key.** Retire it.
- **Prefer `percentage_of_base` over `multiplier`.** Compounding is almost
  never what a designer means.
- **Prefer tags over lists.** `all_of: [attribute, mental]` survives a new
  attribute; an explicit list of six does not.
- **Keep the resolver free of Eloquent.** It is the one piece that must stay
  trivially testable.
- **Return the breakdown, always.** `ResolvedStat.breakdown` is what powers the
  "why is my Focus 14?" tooltip and is the only practical balancing tool. It is
  nearly free to build in and painful to retrofit.

---

## Open questions

These are decided enough to build around but not settled. Resolve them before
they acquire dependants.

1. **Does the 100 ceiling cap the stored base, the resolved value, or both?**
   If both, a character at 100 Focus gains nothing from a `+10` drug and every
   consumable becomes worthless at cap. Suggested: base caps at 100, resolved
   is uncapped or capped higher.
2. **Can `hitpoints_max` be driven to 0?** With clamp-down, a debuff that
   reaches 0 kills the character as a side effect of an inventory change.
   Suggested: hard floor of 1 on `hitpoints_max`; only damage reaches 0.
3. **Should anything be able to raise a ceiling?** "This implant lifts your
   Focus cap to 110" needs a fifth op that modifies the clamp rather than the
   value. Not needed yet, but the clamp should not be buried somewhere that
   makes it impossible to add later.
