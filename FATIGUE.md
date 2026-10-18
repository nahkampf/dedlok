# Fatigue (designed 2026-10-18, nothing built)

Fatigue is a third resource beside hit points and Synaptic Integrity, but it runs the other way: you start at 0, almost every
action raises it, a clock lowers it, and reaching the maximum is a **Collapse**. Rest, stimulants and food push it back down.

**Four phases, each its own build.** Drug interactions are held for a separate design (below).

1. **Core**: the resource, its maximum, what costs it, the minute clock, Collapse and Exhausted. *Decided, below.*
2. **Resting**: rest locations, the timed lock, and stimulants blocking rest. *Open.*
3. **Food and fullness.** *Open.*
4. **Drug interactions and potentiation.** *Its own design, after 1 to 3.*

## 1. Core (decided)

### The resource

- `characters.current_fatigue`, `DECIMAL(8,2)` like HP and SI, **0 for a new character**; the maximum is the stat `fatigue_max`.
- **Maximum = (Focus × 2 + Meat × 2 + Social ÷ 3) × 3**, a formula on `stats.formulas` like the other derived stats. A stat, so skills, implants and drugs move it (stimulants raise it for a while). Attributes of 20 give about 260.
- It is a resource that **clamps down when its maximum drops** (never scales), like the other two; unlike them it **clamps at the maximum going up**. Fatigue is carried to two decimals through `Points::of()`.
- `Character::RESOURCES` pairs a stored figure with its maximum, but the dial reads the other way round (full is bad). The topbar's `.dial` takes it with the hot state at the HIGH end (`data-hot-high`, a mirror of `data-hot-low`) and shows ember when high.
- **One writer, `App\Characters\Fatigue`**: `add(Character, float $points, string $reason)` raises it (through the `fatigue_taken` stat below), `ease()` lowers it, and both clamp. Nothing else writes the column.

### What costs fatigue, first build

| Action | Points |
| --- | --- |
| Grid step | 0.1 |
| Scan | 0.5 |
| Run turn | 1 |
| Physical fight round | 2.5 |
| City travel, per district pass | 5 |
| City travel by pod | −1 (it eases) |
| Explore pass | 7 |

`config/fatigue.php` holds the table. Everything else is **later**: "almost every action" arrives a kind at a time, each added at its own writer
(`Navigator::step()`, `Probe`, `Simulator`, `Fights`, `Traveller`, `Explorer`). A **pod fare eases** fatigue by 1 rather than adding.

### Stats

- `fatigue_max` (derived, above).
- `fatigue_recovery`: points per minute, **base 0.5**, the stat-driven clock. At 0.5 a 260 bar empties in about 8 hours.
- `fatigue_taken`: a percentage on every point added, so a drug or skill can reduce "taken" fatigue (a stimulant's effect, a Meat skill).
- `characters:regenerate` (every minute) is where the clock runs, in hundredths like the other two, and **skips a character in a fight** like them.

### Collapse

Reaching or crossing the maximum puts the character on the **Collapse screen**, built on `WaitingRoom` with its own heading and text:

> **Collapse** — You have collapsed from the combined effects of stress and exertion.

- **Down for 2 minutes.** Anything in progress ends the way the other bad endings do (jack out, a run or fight lost, `WaitingRoom::checkFloor()` asked).
- **Wake at 95% of the maximum.** Collapse is not a reset: the player must **rest, take stimulants or eat straight away**, or collapse again.
- **A collapse costs, beyond the wait:**
  - **Damage to one of HP or SI**, by what you were doing: physical activity (a fight, travel, exploring) hits HP, cyberspace hits SI; ambiguous goes to SI. Through `DamageResolver` as a new origin (`exhaustion`, no family). **Fixed, no roll: 20% of that resource's maximum** (`config/fatigue.php`), lethal (`checkFloor()` is asked after), not scaled by overshoot, no cooldown or escalation on repeat collapses.
  - **Order of a collapse**: jacked out (always), damage, **the mugging check (happens where you fell, before the move)**, then **you wake at your Safehouse**, in its district, for free (no fare, no travel activity, no encounter roll; a small second door on `Traveller`, the only writer of `district_id`). So a player who wakes at 95% can rest at once and is never stranded. See *Safehouses*.
  - **A 20% chance of being mugged**, taking a random 10 to 33% of creds carried, **unless the character is at a safe location**. Home and safe locations are **not built**, so until they are every collapse can be a mugging; the check is written against a `Safety` question that answers "no" for now.
  - **Exhausted**: −10 to every attribute, granted by the collapse and lasting **until fatigue falls below 50% of the maximum**. A character modifier row (`config/effects.php`, stacking `Strongest`), removed by the minute clock or on the next read once fatigue is below half.

### Breakpoints: Tired and Drained (decided 2026-10-18)

- **At 75% of the maximum: Tired** (−3 focus, −3 intellect, −3 reflex). **At 90%: Drained** (−7 focus, −5 intellect, −5 reflex). Drained **replaces** Tired, it does not add to it. Both are `config/effects.php` definitions (one stacking family, `Strongest`), and Exhausted is a third, separate definition.
- **They follow the figure in both directions**: applied when fatigue crosses up, removed when it falls below, by the minute clock and by every `Fatigue` write (and on read, like Exhausted, so a stale row never lies).
- **The bar shows them**: `.meter__mark` ticks at 75% and 90%, as the standing bar does on an exchange. The fill is **yellow (`--warn`) from 75%** and **ember (`--crit`/hot) from 90%**, through the same `data-hot-high` mechanism, so the kit and the server agree.
- **The maximum recalculates whenever attributes change** (an implant, a drug, a skill, these very effects), because it is a resolved stat, never stored. Fatigue itself is clamped DOWN if the maximum drops below it (never scaled).
- **The loop, closed (decided)**: Tired, Drained and Exhausted lower focus and meat, which are inputs to `fatigue_max`, so left alone a lower maximum would raise the percentage and deepen the effect (and Exhausted's −10 would collapse someone who wakes at 95%). **`fatigue_max` is resolved ignoring those three effects** (a filter on `CharacterModifiers` by definition key, for that stat only); every other source (implants, drugs, skills, perks) still moves it. The three effects still lower the attributes everything else reads.

### Open inside phase 1

- What a Safehouse is, who has one, and where `Safety` comes from (see below).
- **The lock** (delegated to Claude): Collapse is the waiting room with a second cause (its own heading, text and 2-minute length), not a new middleware, so the allow-lists, the floor and the wake-up already exist.

## Safehouses (shape decided 2026-10-18, details open)

A safehouse is a **dataset (a type), defined first and then attached to a district**; not every district has one, and a district may have none, one or several variants. **As built, a type belongs to ONE district** (`safehouses.district_id`), so a character's pointer (`characters.safehouse_id`, NULL meaning the fallback) names exactly one place. A type reused across districts would mean pointing at an attachment row instead. **Not unique to a player**: many players can hold the same type in the same district.

- **A type carries**: a name and description (on the row), a **rent per 24 hours**, a **security** value (how hard to break into) and a **stealth** value (how hard for other players to locate you in it), and **one or more actions**.
- **Each type has its own rest rate** (fatigue removed per minute while resting), so a comfortable home rests you more than a cardboard box. A column on the type, read by the Rest action in phase 2.
- **Security and stealth are stored and unread** until player-versus-player break-ins and locating exist (the project's usual "nothing reads it yet").
- **Actions** (to be fleshed out later; a safehouse has one or more):
  - *Eat something*: fills **fullness**, an invisible bar (phase 3).
  - *Rest*: brings fatigue down, locks the player out of the City and cyberspace while it runs (phase 2).
  - *Patch yourself up*: heals some HP.
  - *Meditate*: heals some SI and reduces fatigue.
  - *Take a cold shower*: reduces fatigue and slightly lowers addiction, with a **cooldown**.
- **The lowest tier is a cardboard box under a bridge**, the type a player **reverts to whenever they lose their safehouse**. A new character starts with it.
- **Collapse wakes you at your safehouse's district** (above); `Safety` for the mugging check is still "no" until safe locations exist.
- **The box lives in Knightsbridge Projects** (`knightsbridge-projects`), one fixed place, and **is an authored safehouse type like any other** (a row, attached to that district, rent free or nominal). Losing a safehouse sends you home to the box, so the collapse wake-up and a revert both land there. Which type is the fallback is one config slug (`safehouse.fallback`), never a column.
- **Getting one**: you need **familiarity with the district, at least five explore passes there** (config). The district screen gets a button: **"Get a safehouse here"** if you have none in this district, **"Your safehouse"** if it is yours. Rented from that screen, so you are in the district. **One at a time**: renting a new one drops the old one.
  - *Explore passes per district are not counted anywhere today* (`Explorer` keeps no tally), so this needs a per-character, per-district counter written by `Explorer::settle()`. It counts passes, not discoveries (a pass that finds nothing still counts). **Clean start (decided): nothing is back-filled**, everyone begins at zero and the tally is kept from the build onwards.
- **Rent** is per 24 hours, charged lazily and swept, as an ongoing wallet entry: `Reason::Rent`, note **"Rent: <safehouse title> [<district>]"**. Never charged into debt.
- **Losing one**: unpaid rent (after a grace period, one rent period by default, config), and later through player action (**raiding** and **burning**, not designed). Losing it by ordinary means puts **everything stored there into storage for 48 hours** (decided). The player gets an inbox message: **"Your belongings at <safehouse name> [<district>] has been put in storage. Collect them within 48 hours or lose them."** with a **Collect** button that moves the items to the stash if they fit (`ItemPlacer`/`Storage::fits()` rules; what does not fit stays held); after 48 hours whatever is left is **deleted** (a sweep). A burn will be different. **Safehouse storage itself is not designed and is a dependency**: nothing is stored in a safehouse until the actions are designed, so this ships with it, not with phase 1.

## 2. Resting (open)

A rest is a **timed City activity at certain locations** (`city_activities`, like travel), during which cyberspace and the City are locked. It eases fatigue at a rate per minute.

- Rest is **impossible while on enough stimulants** (or certain kinds). That needs a number per stimulant dose, a threshold, and a refusal.
- An **opiate (or a medical) lets you rest despite stimulants** — which is the first interaction, and why phase 4 follows.
- Open: which locations offer rest and at what rate, rest price, how it is cancelled, whether it can be interrupted by an encounter.

## 3. Food and fullness (open)

- Eating happens at certain locations or from **Food** items in the stash (a new item kind with a row, like consumables or drugs).
- Food **eases fatigue slightly** and fills a second resource, **fullness**, which has a **maximum** (you cannot eat past full) and **falls over time** on the minute clock. Food is therefore not a full answer to recovery.
- Open: the fullness stat and its maximum, what happens when full (refusal or a penalty), prices, a Food editor panel.

## 4. Drug interactions and potentiation (its own design)

Two mechanics that do not exist yet:

- **Interaction**: some combinations give negative modifiers, raise tolerance, or cost HP or SI.
- **Potentiation**: some combinations make one or both drugs stronger or longer-lasting.

Both touch `character_doses`, `DrugTaker`, tolerance and overdose, so they need a design first (a matrix of family pairs, or per-drug pairs; where it lives as data; how it composes with tolerance's frozen `potency`).

## Build order, phase 1 (each step ships and passes tests on its own)

1. **Resource and writer.** Migration: `characters.current_fatigue DECIMAL(8,2) DEFAULT 0`, commented. `Character` cast through `PointsCast`. `App\Characters\Fatigue` with `add()` and `ease()`, clamping, rounding through `Points::of()`. `config/fatigue.php` (the cost table, thresholds 0.75 / 0.90, collapse damage 20%, wake 95%, down 2 minutes, mugging chance and range). Tests for clamping and rounding.
2. **Stats.** `fatigue_max` (formula in `stats.formulas`, so it ships to creation and is recomputed in JS; `DerivedStatTest` shape), `fatigue_recovery` (base 0.5), `fatigue_taken` (a percentage on every point added). `fatigue_max` ignores the three fatigue effects (filter on `CharacterModifiers`). Fatigue joins the resources so the max clamps it down when it drops.
3. **The clock.** `characters:regenerate` eases by `fatigue_recovery` in hundredths and skips a character in a fight. Tests: exact over an hour, skip in a fight.
4. **Tired, Drained, Exhausted.** Three `config/effects.php` definitions (Tired and Drained one `Strongest` family, Exhausted its own). `Fatigue` applies and removes them on every write and on the clock, and on read so a stale row never lies. Tests at 74.99 / 75 / 89.99 / 90 percent and on a maximum that moves.
5. **Costs.** One hook per writer, from the table: `Navigator::step()`, `Probe` (scan), `Simulator` (run turn), `Fights` (round), `Traveller` (pass, pod fare eases), `Explorer` (pass). Each calls `Fatigue::add()` with a reason. Also `Explorer::settle()` keeps the **per-district pass tally** (new table, clean start, nothing back-filled).
6. **Safehouse foundation, only what the wake-up needs.** `safehouses` (types: title, description, rent, security, stealth, actions list; all on the row), attachment to districts, `characters.safehouse_id` nullable (NULL means the fallback), a minimal admin editor, and the **cardboard box authored by migration and attached to Knightsbridge Projects**. No renting, rent, storage or actions yet. `Traveller::relocate()` is the free move.
7. **Collapse.** Waiting room gets a second cause with its own heading and text. On reaching the maximum: jack out, damage (20% of HP or SI by what you were doing, `DamageOrigin::Exhaustion`, through `DamageResolver`), mugging check (20%, skipped when `Safety` says yes, which it never does yet), relocate to the safehouse district, Exhausted applied, down 2 minutes, wake at 95%, `checkFloor()` asked. Tests for each branch, with the dice injected.
8. **Screens.** The topbar dial (`data-hot-high`, yellow at 75%, ember at 90%), a character-sheet meter with `.meter__mark` ticks at both breakpoints, the strings under `config/strings.php`, the creation screen's maximum.
9. **Docs.** `CLAUDE.md` section, `MECHANICS.md` status row, `CHANGELOG.md`, `PATCHNOTES.md`.

Step 6 is the one that grows: it pulls a slice of safehouses into phase 1, and everything else about them (renting, rent, the district button, storage, actions, losing one) is its own phase after it.

## Also still to decide

- Stimulants' exact effect on max fatigue and on "taken" fatigue (numbers per drug, authored in the editor as ordinary modifiers).
- **Fatigue is listed on the character sheet** (decided), as a meter with the 75% and 90% markers, beside the other resources.

## Built since (2026-10-19)

Renting, the rent clock, losing a home for unpaid rent, the district button, the `/safehouse` screen, and the actions Rest, Patch, Meditate and Cold shower (figures in `config/safehouse.php`, placeholders). Fullness, Eat and safehouse storage (with the 48-hour Collect message) followed the same day. Food at locations (a menu of `Meal`s) as well. Stimulants blocking rest followed. Raiding and burning followed as leads (`Leads`: sense while exploring, stakeout / ask around, scope out, break in, rob or burn). **Still open:** nothing in this design.
