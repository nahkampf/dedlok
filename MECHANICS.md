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
| Archetypes + perks as structured data (`config/archetypes.php`) | **built** |
| Live perks routed through the resolver — Netrunner's "Grey matter", Solo's "Scar tissue" | **built** |
| Display-only perks | one kept deliberately as the documented example; the rest removed |
| `hitpoints_max` and `synaptic_integrity_max` as stats | **built**, formulas in `config/stats.php` |
| `ModifierSource` + `ArchetypePerks` | **built** |
| `lang/en/items.php` | **built** as an i18n demo — lang entries only, no config definitions and no `items` table |
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
| Items, skills, implants, drugs, injuries | **specified only** |

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

Mechanics in `config/`, words in `lang/`, instances in the database. Same
split the rest of the project already uses.

| What | Where |
| --- | --- |
| Stat definitions: ids, tags, formula, clamps, rounding | `config/stats.php` |
| Item / implant / drug / skill definitions | `config/items.php`, `config/implants.php`, … |
| Every player-visible name and description | `lang/<locale>/…` |
| What a given character actually has | `character_modifiers` table |

`character_modifiers` holds `character_id`, `definition_key`, `source_type`,
`source_id`, `stacks`, `expires_at`, and a `data` JSON column for per-instance
values (a rolled quality, a remaining charge).

**Definition keys are permanent.** Rows in the database reference them, so a
deleted key orphans live data. Retire a definition with a `retired => true`
flag; never remove or renumber one. The same rule already applies to archetype
ids.

---

## Skills

Skills are the **one exception** to "mechanics in config, words in lang": their
definitions live in the database, rows and all, including `title` and
`description`. There will be hundreds of them, the market has to query them,
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
cap, gapped training queues. Run it in CI and after any bulk edit — it is to
skills what `i18n:check` is to translations.

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

## Recipes

Every recipe below is config plus lang. **If adding content requires new PHP,
the design has leaked and the op set needs revisiting instead.**

### Add an item that changes stats

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

2. `lang/en/items.php` — add `name`, `description` and any flavour text, keyed
   by the same slug. This file **already exists**: it holds the demo items used
   by the i18n example on the landing page, which have lang entries but no
   config definitions yet. New items go alongside them.
3. Copy the lang entry into every other locale.
4. `php artisan i18n:check`.

### Add a skill

A skill is both a **stat** (the character has a level in it) and potentially a
**source of modifiers** (having it grants something).

1. `config/stats.php` — declare `skill.<name>` with tags `['skill', …]`.
2. `config/stats.php` — declare the check it feeds, e.g.
   `check.intrusion = skill.hacking + attribute.intellect`, tagged
   `['check', 'mental', 'intrusion']`.
3. `lang/en/skills.php` — name and description.
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

2. `lang/en/effects.php` — name, description, and the message shown when it
   expires.
3. Applying it inserts a `character_modifiers` row with `expires_at`.

Expiry is **lazy**: rows are filtered on read by `expires_at > now()`. A
scheduled sweep only keeps the table small. Do not write a job that ticks every
character every second.

### Add an archetype perk

Perks exist in two shapes while the migration runs, and the shape decides
whether the perk does anything:

**Live** — carries a `modifiers` array, produces real `Modifier` objects, and
its card text is generated from them so display cannot drift from behaviour:

```php
[
    'key' => 'scar_tissue',
    'modifiers' => [
        ['op' => 'percentage_of_base', 'amount' => 5, 'target' => ['id' => 'hitpoints_max']],
    ],
],
```

**Display-only** — the older `modifier` / `unit` pair. Renders on the card and
**changes nothing**:

```php
['key' => 'damage_taken', 'modifier' => -15, 'unit' => 'percent'],
```

Two perks are live: Netrunner's **Grey matter** (`+5% synaptic_integrity_max`)
and Solo's **Scar tissue** (`+5% hitpoints_max`). Exactly one display-only perk
survives — Solo's `damage_taken` — kept so the legacy shape stays documented and
covered by tests. Delete it once every perk is live.

The other four archetypes have no perks at all. Add new ones as **live**;
there is no reason to write a display-only perk now that the resolver exists.

`CharacterCreationTest::test_every_archetype_perk_is_valid_and_translated`
checks both shapes and fails if a live perk targets a stat that is not declared.

Either way the label goes in `lang/en/archetypes.php`. See CLAUDE.md for the
display rules (signed values, U+2212 minus, neutral colour).

### Add an attribute

1. `Character::ATTRIBUTES` and the relevant group constant.
2. A migration adding the column **and** its `CHECK (<= 100)` constraint.
3. `config/stats.php` — id, tags, clamps.
4. `lang/en/character.php` — `names.<attribute>` and
   `descriptions.<attribute>`.
5. `php artisan test`. Existing tests cover three of the four steps and will
   fail if any is missed:
   - `CharacterTest::test_the_model_and_the_schema_agree_on_the_attribute_list`
     (model vs migration)
   - `CharacterCreationTest::test_every_attribute_has_a_description_string`
     (lang `descriptions`)
   - `CharacterCreationTest::test_it_shows_every_archetype_and_attribute`
     (lang `names`)

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
