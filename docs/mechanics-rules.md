# Game mechanics rules

_Split out of CLAUDE.md. Heading levels are as they were there._

## Game mechanics

**`MECHANICS.md` is the reference for anything producing or altering a number.** Read it
before adding an item, skill or effect. Short version:

- Every modifier is data, never PHP — a `config/` entry or a row. If new content needs
  new PHP, the design has leaked; fix the op set.
- One pure resolver does the arithmetic, fixed order: `add` → `percentage_of_base` →
  `flat` (passive hardware, untouched by percentages) → `multiplier` → `override` → clamp → round.
- `percentage_of_base` applies to **base + flat**; percentages are **summed then applied
  once**, never compounded.
- **Clamp and round once, at the end.**
- Derived stats are never stored; resources are stored and clamp down (never scale) when
  their maximum drops.

MECHANICS.md's status table says which parts exist — check it before calling anything
working.

### Rolling a check

**`App\Checks\CheckRoller` is the only die in the game**, separate from
`App\Stats\Resolver` so a screen can ask what a character's chances ARE without spending
them.

- **A logistic on the MARGIN** — `1 / (1 + exp(-(value - difficulty) / spread))` — so
  only the gap counts and equal value and difficulty is a coin flip.
- **`checks.spread` is how decisive the whole game is, in one number.** Unmeasured.
- **No check is ever certain.** Clamped into `[floor, 1 - floor]`. A kind may name its own
  floor in `checks.floors` — **config, never an argument**.
- **`chance()` is public and separate from `roll()`**, so a warning and the roll cannot
  disagree.
- **The die is injected.** Pin it in tests.

### Where a modifier comes from

**`Character::modifiers()` is the whole list; each entry is a `ModifierSource`.** Eight
today (`WornArmour` is the seventh, under *Physical combat* in `docs/fatigue-and-combat.md`; `FittedModules` the eighth), and the resolver learns nothing new when a ninth arrives.

| Source | Is |
| --- | --- |
| `ArchetypePerks` | the role's live perks |
| `RunningPrograms` | software running on the equipped deck |
| `SkillEffects` | `skills.modifiers` |
| `InstalledImplants` | `items.modifiers` |
| `CharacterModifiers` | injuries and statuses, `character_modifiers` table |
| `ActiveDoses` | drugs in effect, `character_doses` table |
| `WornArmour` | worn armour's damage modifiers, wrapped in `hit_location_in` |
| `FittedModules` | `items.modifiers` of every module fitted in the EQUIPPED deck (an expansion card's effect; a module's own measure is still its `deck_modules` column) |

- **A thing with a row carries its modifiers ON that row** — hence no
  `config/implants.php` and no `skill_effects` table. **`config/effects.php` is only for
  causes with no row of their own**: injuries, statuses, lingering effects.
- **Definition keys in `config/effects.php` are permanent.** Retire with `retired => true`.
- **A per-instance amount is the string `':amount'`**, filled from the row's `data`.
- **Stacking belongs to the DEFINITION and collapses at READ time.** `App\Stats\Stacking`
  has four answers. Never at write time — "the strongest wins" stops being true the moment
  the strongest expires.
- **`App\Stats\InjuryLedger` is the only writer of an accumulating injury**, and it
  DEEPENS one row rather than adding a second (-5 plus -3 is one row at -8). A row that
  reaches zero is deleted.
- **Resolution can be asked AS OF a moment.** `TrainingDeposit` cuts the segment at every
  modifier change; `CharacterModifiers` filters rows to that instant. Sources that cannot
  honestly look back say so — nothing records when a program STOPPED.
- **Damage is a stat too, and its base arrives with the event.** One stat per resource and
  origin (`damage.synaptic_integrity.ice`), generated from two enums by
  `App\Stats\DamageStats`, tagged with both axes plus `damage` — so a resistance is
  `percentage_of_base -15` against `all_of: ['damage', 'ice']`, immunity is
  `multiplier 0`. `DamageResolver` is the only door; `taken` is for saying out loud, never
  for deciding whether anything happened.
- **Build a `Sheet` and read it; do not call `modifiers()` per stat.** Most of the
  sources query. A sheet is bound to its context and cheap to rebuild rather than hold.
- **Pools are not formulas.** A formula divides each attribute, rounds each up and adds; a
  pool adds first and divides once. Pools are kept out of `stats.formulas` because that
  array is shipped to character creation and recomputed in JS (one shape, asserted by
  `DerivedStatTest`). They are in the stat graph all the same.
- **`stats.bases` is the third map `Sheet::baseFor()` walks**, for stats that are neither
  attribute-derived nor stored (`routing_hops`). Older stats of that family
  (`icebreaker_damage`, `scan_duration`) still hand a base in at their call sites.
- **"Per level of something else" is FIVE MODIFIERS**, each conditional on one more level
  — a modifier's amount is a number, never an expression. `skill_at_least` is the
  predicate; `Sheet::contextFor()` puts levels into the context as `skill.<slug>`, for the
  skills a character actually has (absent and zero are the same answer).
- **Hit points and Synaptic Integrity are carried to TWO DECIMALS** (`current_hitpoints`, `current_synaptic_integrity`
  are `DECIMAL(8,2)`; `hitpoints_max`, `synaptic_integrity_max`, both regens and every `damage.*` stat round to a
  `hundredth`). **`App\Support\Points::of()` is the one rounding, and a whole figure is an `int`** (a fraction the only
  thing that is a float), so every `=== 0` and `assertSame(30, …)` still holds. `Character` casts them through
  `PointsCast`; `takeDamage()`/`recover()` take and return `int|float`. **Never `(int)` a resource, a maximum or a
  taken amount** — it silently drops the fraction and puts somebody on 0.4 HP on the floor. Creds stay whole: a clinic
  bills `ceil(points × price)`. The regen command works in hundredths so an hourly rate is exact.
- **ICE and icebreakers are carried to two decimals too** (`ice.integrity/attack`, `runs.ice_integrity/ice_attack/ice_damage`, `hunts.ice_integrity` are `DECIMAL(8,2)` through `PointsCast`; `icebreaker_damage` rounds to a `hundredth`; `CriticalHit::scale()` and the fight code use `Points::of()`), so a skill's few percent is not rounded away. A fortress's kernel (`kernel_integrity`, burned by `Simulator`) is still whole.
- **Any pool stat asked for outside a Sheet reads as zero.** `Resolver::resolve('stealth',
  0.0, …)` returns modifiers and nothing else. `Character::stat()` is the way in.
