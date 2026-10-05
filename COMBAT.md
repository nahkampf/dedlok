# Physical combat

**Status: steps 1–5 are BUILT — items, the engine and its simulator, NPCs, the stored fight and its screen, and encounters that start fights. Step 6 (what a win pays, what a loss costs, tags) is not.** Phase 5 of `CITY.md`.

Built (2026-10-03/04): the eight damage types and their families, armour per body part with
location-aware resistance, weapons, ammunition (with a type), riders (authored only), wielding and
magazines — items, editors, stash actions and the sheet. **Not built**: the engine, `aim` and
`weapon_damage` stats, NPCs, fights, body-part rolls, mugging. Sections say **BUILT** where they are.

**Scope is FULL** (decided): hit locations, per-slot armour, two ranges, magazines and ammunition
types. Every number is an unmeasured default the author has not seen; the numbers are set with
`fight:simulate`, not on paper.

Physical combat and cyberspace combat are **separate engines** (`MECHANICS.md`, *Two combat
engines, deliberately*): they share a character, the resolver and the modifier system and nothing
else. What they share is the *shape* — a pure function over a plan, a snapshot and an injected die,
returning a log and an outcome — so the whole thing is tested against pinned dice. **ICE is not
touched by any of this**: its rules (a miss does nothing at all, one fixed hit) are the cyberspace
engine's own.

## What already exists, and is therefore free

- **Hit points** (`hitpoints_max`, about 40 at attributes of 20), `Character::takeDamage()` and
  `Character::recover()`, **clinics** that restore them (`Clinic`) and mend `physical_trauma`, the
  injury that lowers the maximum (`InjuryLedger`).
- **`DamageResolver`** and the damage stats generated from `DamageOrigin`: a resistance is a
  modifier against `all_of: ['damage', '<origin>']`.
- **`CheckRoller`**: the logistic on the margin, a floor on certainty, an injected die, a
  `multiplier` on the raw curve (Ensnare's), and `chance()` separate from `roll()` so a button can
  quote what the roll uses.
- **Modifier conditions** (`Condition`, named `Predicates`) and a `Context` the resolver already
  takes — which is what lets armour count only for the part of the body it covers.
- **The waiting room** for 0 HP, with `DownedCause`, and the rule every bad ending follows:
  damage, consequence, the floor.
- **The exchange shape** `HunterCombat` and `Simulator::iceStrikes()` keep: one transaction per
  round, a durable row, a column pointing at it (`current_hunt_id`), a middleware lock
  (`EnsureHuntIsResolved`).
- **Defeat tags** (`DefeatResolver`), **loot tables**, **`ItemGranter`**, **`Wallet`**.
- **Implants, drugs and skills as modifier sources**: anything that should make a fighter better
  is a modifier on the right stat, with no new code.
- **Encounters** (`CITY.md`) — the door into a fight.

## Settled

| | Decision |
| --- | --- |
| Scope | **Full** |
| Who goes first | **PvP:** each player's Senses + Perception + Reflex (resolved), highest first, a **tie is a coin toss**. **Encounters:** the **enemy always goes first**. |
| Armour | **Per body part**, six slots; sets cover several; hits land on **body parts**; a piece has **several resistances** |
| Losing | A standard enemy puts the player at **zero HP** and takes **30–50% of all the creds they carry**; special enemies carry **tags** with extra effects (e.g. *Maim*) |
| How it plays | Start at **Ranged**; after one round it closes to **Close**; *Create distance* (a chance) returns to Ranged; *Disengage* (a chance) leaves |
| Damage | Eight damage types, **combinable**; **fixed damage per hit**, scaled by **hit quality**; rounds can change it |
| Hit quality | **Five tiers**: Near miss 10%, Glancing 25%, Partial 50%, Clean hit 75%, Dead-on 100% of full damage. **No outright miss.** |
| Burst fire | A weapon's shots per action are separate attacks; **the first uses the full chance, each later one is 7% worse than the one before** (recoil) |
| Riders | **Stun, burning, poisoned**; roll **only on a Clean hit or Dead-on**; re-applying **refreshes**; **stunlock is allowed** |
| Magazines | Rounds are stackable items in the stash; **loaded rounds are a count on the weapon**; at zero you **reload or cannot fire**; reload (empty) and switching round type each cost **a whole turn** |
| NPCs | **Do not count ammunition** |
| Resistances | **Sum, then apply once** — no diminishing |
| Dodge | **Parked.** A flat `defence` number stands in (below) |
| Skills | **Parked** |

## Calls still open

1. **Location damage.** Should a location change the damage (a headshot hits harder)? Default:
   **no — location matters through armour only**, with a per-location multiplier in config at 1.0
   as a later knob.
2. **Cover.** *Dropped.* With one ranged round before the fight closes there is nothing for it to do.
3. **HP regeneration.** Nothing mends HP except clinics. Default: **leave it that way**, so a lost
   fight has a price.
4. **PvP.** Initiative is decided; consent and the bounty collection `characters.bounty` waits for
   are not. Default: **the engine is symmetric now (`Combatant`); the opening is later**.
5. **Heat from violence.** Default: none (heat is a cyberspace quantity).
6. **Using items mid-fight** (stims, healing; `DrugTaker`). Default: later.
7. **The numbers**: the tier `spread`, opponent HP and damage by grade, fight length — set with
   `fight:simulate`.
8. **Dodge and skills**, parked on purpose.

*Settled since:* neural damage against an NPC does nothing; an NPC that flees does so by a Disengage roll, and
a success ends the fight with no gains. Whatever replaces `defence` must keep the engine's shape.

## Damage types — BUILT

Eight, a fixed vocabulary, in two families. **They are `DamageOrigin` cases** beside `ice`,
`overdose` and `encounter`. The origin is already "what hurt somebody": the axis a resistance is
written against. So nothing in the damage system learns anything new: the stats
`damage.<resource>.<type>` are *generated* from the two enums, and `all_of: ['damage', 'slashing']`
already means "everything that cuts".

| Type | Family | Hits | Typically |
| --- | --- | --- | --- |
| `blunt` | kinetic | hit points | bats, fists, a fall |
| `slashing` | kinetic | hit points | knives, katanas |
| `penetrating` | kinetic | hit points | firearms |
| `fragmentation` | kinetic | hit points | grenades, shotguns |
| `electrical` | specialty | hit points | stun batons, tasers |
| `thermal` | specialty | hit points | incendiaries, plasma |
| `chemical` | specialty | hit points | toxins, acid |
| `neural` | specialty | **Synaptic Integrity** | neural stunners, hostile hardware |

- **The family is a second tag** (`kinetic`, `specialty`) on each generated stat, so one modifier
  can cover four: "everything kinetic" is `all_of: ['damage', 'kinetic']`. ("Kinetic" and not
  "physical": the game already calls damage to the body physical damage.)
- **Which resource a type hits is the type's default** (`DamageOrigin::resource()`): seven hurt the
  body, `neural` the mind.
- **A weapon carries a PROFILE, so damage combines.** `weapons.damage_profile` is a JSON map of
  type to share, summing to exactly 100 (`App\Combat\DamageProfile` judges it): a katana
  `{slashing: 100}`, a stun baton `{blunt: 70, electrical: 30}`, a shotgun
  `{penetrating: 30, fragmentation: 70}`.
- **A hit is split, and each part is resolved on its own**: the hit's damage times each share
  (rounded; a part that rounds to nothing is dropped) goes through `DamageResolver` as its own
  event under its own type, and the sum is what lands. The log says each: "12 slashing, 5 electrical".
- **Resistance comes from everywhere that is already a modifier source**: armour, implants, skills,
  drugs, injuries and statuses. A resistance is `percentage_of_base` negative; immunity is
  `multiplier` 0; a vulnerability is positive. **They sum and apply once.**
- **An NPC's resistances are a map of type to percent on its row** (with an optional override per
  body part), turned into modifiers on the same stats and resolved by the same call.

## Body parts and armour — BUILT (the roll is not)

**Six slots**, a fixed vocabulary — `App\Combat\ArmourSlot`: `head`, `torso`, `arm_left`, `arm_right`,
`leg_left`, `leg_right`.

- **Every hit lands on a body part**, by a die of its own: **head 9%, each arm 9%, each leg 18%, the
  rest on the torso** — torso 37%. The weights are `config/combat.php`. Rolled once per shot, after
  the hit roll, and named in the log: "A clean hit to the left leg for 4."
- **An armour piece covers a set of slots** (`armour.slots`, six checkboxes in the editor). A set
  is **one item** that takes several slots. Its resistances are modifiers on the item
  (`items.modifiers`) and a piece may carry several, different per damage type.
- **They count only when the hit lands in a slot the piece covers.** `WornArmour` is the seventh
  modifier source: it wraps each **damage** modifier of a worn piece in `hit_location_in` with the
  slots the piece is worn in, and `Damage` carries an optional `?ArmourSlot $location` that
  `DamageResolver` puts in the context as `hit.location`. **A hit with no location never meets worn
  armour**: ICE, an overdose, an encounter's damage outcome, a burn and a rider's tick strike no
  body part. Other modifiers on a piece (a plate slowing its wearer) apply whenever it is worn.
- **Wearing** is `worn_armour` (unique on character and slot); `ArmourWearer` is the only writer.
  Worn armour stays in the stash grid tagged *Worn* and cannot be listed, sold or put away.
- **An NPC's armour** is its resistance map, optionally by slot ("a helmet, no vest").

## Weapons, ammunition and magazines — BUILT

An *item with a row*, as software has `programs`.

- **`weapons`**: `class` (`WeaponClass`, which also says Ranged or Close), `damage` (the ONE fixed
  figure a hit does, never a range — with its standard round for a firearm), `damage_profile`,
  `riders`, `accuracy` (a signed modifier to the shooter's aim), `rate` (shots per action; above 1
  is a burst or automatic weapon), and for firearms `magazine` and `calibre`, set together or not
  at all. `WeaponEditor` is its only writer.
- **`ammunition`**: `calibre`, `type` (`AmmoType`, a label: standard, full metal jacket, hollow
  point, armour piercing, incendiary, shock), `damage` (a signed percent on the hit), `penetration`
  (a signed percent of the target's resistance it ignores), an optional replacement
  `damage_profile`, and `riders`. **Unique on (calibre, type): one item per kind of round**, since
  ammunition stacks per item. `AmmunitionEditor` is its only writer; ammunition must stack and a
  weapon must not.
- **Two hands**: `characters.equipped_ranged_id` and `equipped_melee_id`; `WeaponWielder` is the only
  writer. A weapon's class says which hand it fills. Bare hands are NULL.
- **Rounds live in two places.** In the stash a round is an ordinary stackable item. In a weapon
  it is two columns on that COPY's inventory row — `loaded_ammo_id` (the item, because a pile's row
  id does not survive merging) and `loaded_rounds` — never on `weapons`, which is the definition.
  The weapon's `magazine` is the ceiling. `WeaponLoader` is the only writer; rounds come off the
  loose pile and return through `ItemGranter`. A NULL pointer is an empty magazine whatever the
  count says (no CHECK on a column with a delete rule: MySQL error 3823).
- **A weapon holding rounds cannot be listed, sold or put in a container** (`isOnPerson()`),
  destroying it returns them, and the editors hand them back when a weapon changes calibre or stops
  being a firearm, or when a round does.
- **A round carries part of the damage; the engine learns no round by name.** Illustrative
  defaults:

| Round | Damage | Penetration | Also |
| --- | --- | --- | --- |
| Full metal jacket | 0% (the baseline) | 0 | — |
| Hollow point | +25% | −50 | worse against armour, better against none |
| Armour piercing | −15% | +50 | cuts through resistance at a cost in damage |
| Incendiary | −10% | 0 | a `thermal` share and a *burning* rider |
| Buckshot / slug | per shell | 0 / +25 | a shotgun's `fragmentation` / `penetrating` profiles |

- **A round's profile REPLACES the weapon's while loaded; its riders ADD to the weapon's**
  (`Riders::combine()`). An incendiary round turns a plain pistol into a burner without taking its
  stun away.
- **Penetration** is the one new piece of arithmetic, in `DamageResolver`: resistance counts for
  `resistance × (1 − penetration / 100)`. +50 halves what a vest is worth; −50 makes it count half
  again; +100 ignores it. `Damage` gains a `penetration` field (default 0) and the resolver scales
  only the matching *negative percentages* with `Modifier::scaledBy()`. **A vulnerability is not
  softened and an immunity (`multiplier` 0) is left alone.** ICE never passes one, so it is untouched.

## Hit quality

**Damage is fixed; how well it lands is not.** A hit does one number — the weapon's, as the round
loaded changes it — and the tier says how much of it lands.

| Tier | Share of full damage |
| --- | --- |
| Near miss | 10% |
| Glancing | 25% |
| Partial | 50% |
| Clean hit | 75% |
| Dead-on | 100% |

(`App\Combat\HitQuality`, `config/combat.php` `hit_quality` — BUILT as numbers and labels.)

**There is no outright miss**: the lowest tier still does a tenth. A "miss" is a Near miss.

**Which tier — one die per shot, planned:**

```
c = the chance, from CheckRoller: aim against the defender's defence
v = c + spread × (0.5 − u)          u is the die, uniform in [0, 1)
tier = the fifth that v falls in    clamp v into [0, 1) first
```

`spread` is `config/combat.php` `spread`, **0.6**, one number for how much luck matters. At 0.6
the average damage of a shot is almost exactly *chance × damage* (15%, 25%, 50%, 79% and 92% of
full damage at chances of 10%, 25%, 50%, 75% and 90%), so the tier is variance around a number a
button can quote. Raise it and luck matters more; the real figure is set with `fight:simulate`.

`HitQuality::fromRoll($u, $c, $spread)` is pure and tested alone. The die stays injected.

### Burst and automatic fire

A weapon's `rate` is how many shots one Attack fires. **Each shot is its own attack** — its own
roll, tier, body part, damage and riders — and uses one round, stopping when the magazine reaches
zero (a weapon that fires 3 with 2 left fires 2).

**The first shot uses the full chance. Each later shot in the same action is 7% worse than the
one before it** — recoil, and the difficulty of keeping aim on automatic. The chance for shot `k`
(the first is `k = 0`) is the usual chance with a `multiplier` of `0.93^k`, which is
`CheckRoller`'s own `multiplier` (the shape Ensnare uses): the factor applies to the raw curve and
the certainty floor applies again afterwards, so a long burst can fall to the floor and no
further. The falloff is `combat.burst_falloff`, **0.07**. It compounds (a ten-shot burst's last
round is at 0.93⁹ ≈ 52% of the first's chance). The count resets with every action.

### The order of arithmetic, per shot

1. **Chance** — `CheckRoller::chance(aim, defence, 'attack', 0.93^k)`; quoted on the button.
2. **Tier** — the first die, via `fromRoll`.
3. **Damage** — `weapon_damage.<class>` (the weapon's `damage` × the round's percent, handed in as
   the base, so an implant or a stimulant is a modifier on it) × the tier's share.
4. **Split** by the effective profile (the round's if it has one, else the weapon's); each part
   `round(amount × share)`, a part that rounds to nothing is dropped.
5. **Body part** — the second die.
6. **Each part through the resolver** with the location and the round's penetration.
7. **Riders** — only on a Clean hit or Dead-on (below).

Rounding is done once where a number becomes an event; the resolver rounds what it returns.

## Riders: stun, burning, poisoned — authored (BUILT), not applied

Multi-round effects a weapon, or the round in it, can put on its target. `RiderKind` is the fixed
vocabulary; **a new kind is a case and a string**. A rider on a weapon or a round is `{kind,
chance, turns, amount}`, at most one of each kind per list (`Riders` judges it).

| Kind | Does | Damage type |
| --- | --- | --- |
| `stun` | the afflicted side **loses its action** for the turns | — |
| `burning` | `amount` damage at the start of each of its turns | thermal |
| `poisoned` | `amount` damage at the start of each of its turns | chemical |

- **It rolls only on a Clean hit or Dead-on**, one extra die per rider, against its `chance`. A
  weaker hit never sets anyone alight. A burst rolls every shot that qualifies.
- **`turns` count the afflicted side's own turns**, and a status **ends with the fight**.
- **A tick is a hit with no location** (a burn is not a blow): it goes through `DamageResolver`
  under its type, so resistances a character carries everywhere apply and worn armour does not.
  It has no penetration.
- **Re-applying refreshes**: the longer duration, the larger amount (`Stacking::Highest`).
- **Stunlock is allowed**, deliberately — nothing stops a side being stunned again the round it
  recovers. Its price is on the author: a stun's chance and turns are the numbers to keep modest,
  and the floor (0 HP) still ends a fight a stunned player never acted in.

## Defence, until dodge exists — BUILT

**Dodge is parked.** What an attack rolls against is a single number, the target's `defence`: the
NPC's row, and for a player the declared stat `defence` (`stats.defence`, base 8 in `stats.bases`),
so an implant or a drug can already move it. It is the `difficulty` in
`CheckRoller::chance(aim, defence)`. *Create distance* and *Disengage* roll the same `defence`
against the opponent's `pursuit`. When dodge arrives it becomes a pool in `stats.pools` that takes
the base's place, and the engine does not change.

**No `checks.floors` entry for attack.** That list is deliberately a handful of exceptions, and the
global floor already stops any shot being certain either way.

## The numbers are stats, so chrome and drugs reach them for free

Generated from the weapon class, the way `DamageStats` is generated from two enums:

- **`aim.<class>`** — **BUILT.** A **pool**, `(motor + senses) / 3`, ONE pool shared by every class
  (`stats.pools.aim`, about 13 at attributes of 20), read through a Sheet. The weapon's `accuracy`
  is added by the engine at the call. Generated by `App\Stats\CombatStats`.
- **`weapon_damage.<class>`** — **BUILT.** The base handed in at the call site, the way
  `icebreaker_damage` is; **unrounded**, because a tier scales it before it is a whole number.
- **`defence`** — **BUILT**, above.
- All of them are named in `character.names`, so the modifier picker offers them (per class, and
  `aim` / `weapon_damage` for every class at once).
- **Anything invented here is small**: 2–5% a level, never 10–15%.

The class-to-skill mapping is parked with skills.

## The fight

**Two ranges: Ranged and Close.** A fight opens at **Ranged**, and **after one round it closes**.

- **Ranged:** firearms are effective; melee weapons and bare hands cannot be used.
- **Close:** only melee weapons and bare hands.
- **Create distance** is a chance (`defence` against the opponent's `pursuit`, quoted on the button
  like Egress's). On a success the fight is at **Ranged again for the next round — one round,
  after which it closes**. On a failure the round is spent.
- **Disengage** is Egress by another name: **always available, never certain**, a failure spends
  the round, a success ends the fight with nothing lost.
- **Bare hands** are the fallback at Close for anyone without a melee weapon: a config weapon
  (`combat.unarmed`: damage, a blunt profile, no riders). An NPC with no melee weapon uses the same.
- **A side with nothing usable at the current range** (no ranged weapon at the opening Ranged
  round, an empty magazine and nothing to load) may **Brace**: the round is spent and nothing
  happens. Disengage is still offered.

**Who acts first.** Once, at the start, and the order holds. In an encounter **the enemy always
goes first**, including the ranged round. Player against player: Senses + Perception + Reflex,
resolved, higher first, a tie a coin toss with an injected die.

### Actions

| Action | When | Costs |
| --- | --- | --- |
| Attack | a usable weapon for the range (and rounds, for a firearm) | the action; `rate` shots |
| Load | the magazine is **empty**, or you are **switching to a different round type** | the action |
| Create distance | at Close | the action |
| Disengage | always | the action |
| Brace | nothing else is possible | the action |

**Load** is one action covering both of your rules: reloading an **empty** magazine (the same
round or another) and **switching type at any time**, each a whole turn. Topping up a part-full
magazine of the same round is not offered mid-fight. The player picks the round from compatible
rounds in the stash. Firing takes rounds off the magazine (`weapons.rate` at most, fewer if fewer
remain); **at zero the player cannot fire — they reload, or do something else**. **NPCs do not
count ammunition**: their weapons never run dry, and an NPC may name a standing round for its
penetration, profile and riders.

`WeaponLoader` knows nothing of turns. The engine reports what it did and the persistence layer
(step 4) applies it: rounds fired lower `loaded_rounds`, and a Load goes through `WeaponLoader`.

### A round, in this order, for the side whose turn it is

0. **Start of its turn**: it takes what burning and poison do, and a stunned side loses its action.
1. **Its action** (above). An Attack builds each shot as in *Hit quality*.
2. **The floor.** If either side is at 0 HP: the opponent is down, or `WaitingRoom::checkFloor()`
   is asked for the player — damage, consequence, the floor.
3. **The other side acts** the same way. An NPC flees below its `flees_at`, shoots at Ranged with
   its ranged weapon (if it has one), and fights with its melee weapon or fists at Close.
4. **Close the range** if this was the ranged round.

A fight should run **4–10 rounds** and cost a fifth to most of a character's HP at grades 1–5;
those two ratios are what the balance pass tunes.

## The engine's shape (step 2) — BUILT

**As built (`App\Combat\Fight`).** Everything the engine needs is a plain-data snapshot, so a fight is stored as
JSON and rebuilt every request (`FightState::toArray()` / `fromArray()`, round-trip tested). The engine **never
writes**: hit points are numbers in the state, resistance is a `Mitigation` passed in (a player's will be
`DamageResolver::preview()`, never `apply`), and the dice are one injected callable. Step 4 applies the outcome
(`Result`: A down, B down, disengaged) through `WaitingRoom`, `WeaponLoader` and the rest.

- **`FightEngine::round(state, actions, dice, mitigation)`** is a whole round: each side's turn, `state.first`
  first, then the range moves on. A side with no chosen action takes `choose()`'s (an NPC's). The action is
  **validated when it executes**, so a choice made against a state that has moved on becomes a Brace and says so.
  A fight that ends mid-round ends there: the second side never acts and no dice are spent on it.
- **Dice order, per shot:** the tier die, the body-part die, then one per rider that qualifies. Flight is one die;
  Load and Brace roll nothing. `chance()` only, never `CheckRoller::roll()`. `quote()` and `quoteFlight()` give
  exactly the numbers a roll uses.
- **Turn order inside a turn:** start-of-turn ticks (burning, poison), a stun taking the action, the floor, the
  action, the floor. A status applied on your turn is already running when the other side's turn begins in the
  same round.
- **Range:** a round at Ranged closes; a successful Create distance (at Close only) sets `reopen`, so the next
  round is at Ranged, and then it closes again. **`Load` works on the ranged weapon at either range.**
- **Magazines in a fight are a snapshot copy** (`RoundSpec::stock`, `Arm::rounds`); step 4 replays a Load through
  `WeaponLoader`, which does the same sum. A side with `rounds` NULL never runs dry (NPCs).
- **Neural damage lands on Synaptic Integrity, and does NOTHING to a side that has none** (an NPC) — decided.
  The part is not even resolved.
- **An NPC below its `flees_at`** (a share of `hpMax`) tries to **Disengage**: a roll like any, so it can fail
  and cost its turn. A success ends the fight with **no winner and no gains for the player** (`Result::BEscaped`,
  `winner()` null); only standing, if anything, moves — step 6's business.
- **The builder owes the engine a melee arm.** There is no bare-hands fallback in the engine; whoever builds a
  `Combatant` supplies one from `combat.unarmed` (4f). A missing arm is a Brace.

**The adapters** (`App\Combat\Fight`): `CombatantBuilder::forCharacter()` works a character's snapshot out of
their stats, hands and stash (one `RoundSpec` per compatible round held plus the one loaded; bare hands from
`combat.unarmed` as the Close fallback; riders combined; the weapon's `accuracy` added to `aim`), and
`fromSpec()` does the same from a plain description. `CharacterMitigation` is `DamageResolver`'s own sum
(`DamageResolver::against()`, which `preview()` also uses) with the modifiers fetched ONCE; `NpcMitigation` is a
map of type to percent, optionally by body part, summed then applied once, where penetration scales a
resistance but never a vulnerability or an immunity (100 or more). `Simulation` plays the fights with a seeded
die and a simple player (attack, else load, else brace).

The sketch that guided it:

Pure, test-first, pinned dice — the whole of it can be exercised with no database.

- **`Combatant`**: an immutable snapshot of one side — name, hit points, `defence`, `pursuit`,
  the ranged and melee arms (resolved aim, damage base, profile, riders, `rate`), what is loaded
  and how many rounds, how many of each compatible round are in the stash, statuses, and a
  **mitigation** that turns a `Damage` into a resolved integer. A player's mitigation wraps
  `DamageResolver` on a character; an NPC's wraps its resistance map. The engine knows neither.
- **`FightState`**: both combatants, the range, the round, whose turn it is, the log.
- **`FightEngine::round(FightState, Action, Dice): FightState`**, and a separate
  **`FightEngine::quote(FightState)`** returning every chance a button shows — a quote and a roll
  must not disagree.
- **Dice are one injected source** (`Dice::unit()`), with a documented order per shot — hit, body
  part, then one per qualifying rider.

## Winning, losing, leaving

- **Win.** The opponent's loot table rolls, creds are paid through `Wallet`, standing with its
  owner moves through `StandingLedger`. No experience: skills train by time.
- **Lose.** A standard enemy **reduces the player to zero HP** (the waiting room, as always) and
  **takes 30–50% of all the creds they are carrying** — a uniform roll with an injected die,
  bounds in `config/combat.php`, charged through `Wallet` under a `Reason::Mugged`.
- **Tags.** A special enemy carries **tags** with further effects, exactly like ICE's defeat tags —
  a fixed vocabulary, `CombatTag`: **a new tag is a case and a string**. First is `mugged` (every
  standard enemy has it, so it is just the default); next is **`maim`**, which deepens
  `physical_trauma` and so lowers the player's maximum HP until a clinic mends it. A tag can also
  *replace* the default for an enemy that takes something other than money.
- **Disengage** ends the fight with nothing lost; the encounter goes to its flight destination.
- **From an encounter — BUILT (step 5).** A choice names an opponent and three destinations. The encounter
  row **stays** while the fight is live, and when it ends the runner resumes at `win_key`, `lose_key` or
  `flee_key`. This is a *choice* kind, not an outcome: an outcome cannot lead anywhere.
  - **`flee_key` is the nobody-won screen**: the player got away, OR the opponent did. Either way there are no
    gains, because the win screen is where authored rewards sit — **a reward is the win screen's arrival
    outcomes**, which are data like every other.
  - **A fight choice carries no roll, no next screen and no outcomes of its own** (`EncounterRequest`), only a
    cost and a required flag; each of the three keys may be blank, which closes the encounter.
  - **The destinations are KEYS snapshotted onto the fight, never a choice id** — the editor recreates every
    choice on each save. The fight also records the `character_encounters` row it came from (a plain column) and
    the runner acts only if that row is still the character's live encounter. A key that names a screen that
    no longer exists closes the encounter, as everywhere else.
  - **The fight opens BEFORE the cost is charged**: a refusal returned inside a transaction still commits.
  - **A loss goes to the lose screen after the waiting room has them** — they read it when they get up, with the
    fight's ending as the first line of that entry in the encounter log.
  - **`fight_npc_id` restricts the NPC's deletion** (the editor says which encounters use it).
  - **The two locks do not fight each other.** The fight lock runs first; with a fight live the encounter lock
    stands down, or `/fight` and `/encounter` would redirect to each other for ever.

## The fight as a stored thing — BUILT (step 4)

`App\Combat\Fights` is the seam between the pure engine and the game, and the only writer of `fights` and
`characters.current_fight_id`.

- **One transaction a round, both rows locked** (the character and the fight), so two presses serialise and the
  second finds the fight moved on or over and changes nothing.
- **The player's body is the one source of truth.** Side A's hit points and SI are overwritten from the locked
  character row at the START of every round (SI regenerates every minute, so a stored snapshot goes stale),
  then the round's drop is applied with `Character::takeDamage()` — every round, so the topbar dial stays true.
  **Never `DamageResolver::apply()`**: the mitigation already resolved the hit. Side B is never rebuilt from the
  NPC row.
- **The opponent is a snapshot on the fight row** (name, grade, both resistance maps), so an NPC edited or
  deleted mid-fight changes nothing; `npc_id` is a memory (`nullOnDelete`), never a cascade.
- **Ammunition goes through `WeaponLoader`.** The engine's arms carry no row ids, so the ranged and melee rows
  are recorded when the fight opens (`ranged_row_id`, `melee_row_id`, plain columns). Each round the arm is
  compared before and after: a different round or more of it is a Load, replayed through `WeaponLoader::load()`
  and checked against the engine's count; fewer rounds is shots fired, `WeaponLoader::spend()`. A weapon no
  longer in hand, or a mismatch, THROWS and the round is rolled back — a desync is a bug and is loud.
- **`open()` refuses** a character who is down, jacked in, in a run, hunted, already in a fight, or in the
  middle of a City activity (a trip would settle under a fight). **It does NOT refuse one in an encounter**:
  step 5 opens fights inside encounters.
- **Everything that could start something refuses during a fight**, not only the middleware: `Navigator::jackIn`,
  `RunStarter::start`, `Traveller` (quote and begin), `Explorer::begin`, `Presence` (shops, clinics, surgery) and
  `Encounters::canHappenTo()` — without the last, the five-minute sweep could open an encounter under a fight.
- **`EnsureFightIsResolved`** is the hunt lock's shape: after the waiting-room lock, before the encounter lock,
  allow-list `login`, `logout`, `fight.show`, `fight.act`, admin not exempt, 409 and a URL for JSON.
- **The screen** (`fight/show`) draws every chance from `Fights::options()`, so a quote is what the next roll
  uses. One form per action; Attack is not offered when nothing can fire (a Brace is), Load lists the
  compatible rounds in the stash, Create distance appears only at Close. No hazard stripe: that ember fill is
  the hunt's alone.

## The model still to build

- **`npcs`** — **BUILT.** `slug` (permanent), `name`, `flavor`, `grade` 1–5, `hitpoints`, `aim`, `defence`,
  `pursuit`, `flees_at`, `ranged_weapon_id`, `melee_weapon_id` and `ammo_item_id` (the standing round) — all
  three pointers at ITEMS, so a weapon's numbers live on the weapon — and `resistances` / `slot_resistances`
  (JSON: percent off by type, and extra by body part). Pictures by convention, 3:4 like a person
  (`public/images/npcs/<id>.jpg`). **Deliberately not on the table yet:** `entity_id` (whose it is, for
  standing), a loot table and `tags` — columns modelled against code that does not exist get modelled wrong, so
  they arrive with step 6. **No CHECK reaches the three item pointers** (MySQL error 3823): which kind of weapon
  goes in which hand, and that a round fits the weapon, are `NpcRequest`'s. **Deleting a weapon item leaves
  its opponents unarmed** (`nullOnDelete`), and the item's delete dialog says how many.
- **`fights`** — durable, `runs`'s own reason: the character, a snapshot of the opponent's hit
  points, the **range**, the round, the order, what each side has loaded and how many rounds are
  left, each side's statuses, the log (JSON, append-only), and the encounter it came from.
  **`characters.current_fight_id`** is what "in a fight" means. `Fights::open()` is the only
  writer; `EnsureFightIsResolved` is the lock, after the waiting room and before the encounter
  lock (a fight is *inside* an encounter, not beside it).

## Screens

- **The fight** — `hunt/show`'s own shape: an opponent panel (portrait, name, grade tag, HP bar),
  **the range** as a tag, your HP and the order, an **Actions** panel (the buttons, the chances
  quoted as on Egress), and a **Fight log** with a "Round X" tag per line and the tier and body
  part named. The Load action lists the compatible rounds you hold.
- **The sheet** shows the weapons and the worn armour (BUILT, in one Equipment panel) and, with
  the engine, the resolved `aim` and damage — attributes and anything derived are *resolved*.
- **Editors**: Weapon, Ammunition and Armour panels (BUILT), an `admin/npcs` editor, and a *Fight*
  choice type in the encounter editor.

## Build order

1. **Vocabularies, stats and item rows** — **BUILT**: damage types, `ArmourSlot`, `WeaponClass`,
   the `weapons`/`ammunition`/`armour` tables and editors, `worn_armour`, the hands, the
   `WornArmour` source and `hit_location_in`, ammunition types, riders (authored), magazines, the
   sheet.
2. **The engine and the balance tool**, in slices, each green on the full suite before the next:
   1. **Pure pieces** — **BUILT**: `HitQuality::fromRoll()`, `ArmourSlot::roll()`, `Burst::multiplier()`
      and `Burst::shots()`, `HitDamage::parts()` (tested in `HitRollTest`; the three knobs are
      `combat.spread`, `combat.burst_falloff` and `combat.body_parts`).
   2. **Penetration in `DamageResolver`** — **BUILT**: `Damage::$penetration` (a signed percent, default 0),
      scaling only negative `percentage_of_base` modifiers by `1 − p/100` for that hit. ICE and every older
      source pass none; `PenetrationTest` pins it (and scans the cyberspace, drug, city and character code
      for the word).
   3. **Combat stats** — **BUILT**: `aim.<class>`, `weapon_damage.<class>`, `defence` (`CombatStatsTest`).
   4. **`Combatant`, `FightState` and `FightEngine`** — **BUILT** (`App\Combat\Fight\*`, tested in
      `FightShotTest`, `FightRoundTest`, `FightRangeTest`, `FightLoadTest`, `FightRiderTest`): shots and bursts,
      round order and the floor, ranges, Create distance and Disengage, Load and magazines, riders and
      their ticks. The adapters are built too (see below).
   5. **`fight:simulate`** — **BUILT.** N fights of a character's real loadout against an opponent
      described by options (NPCs are not a table yet), printing win rate, rounds and HP cost.
      `php artisan fight:simulate <handle> --runs=500 --seed=1 --foe-hp=40 --foe-ranged=pistol:5
      --foe-melee=blade:4 --foe-resist=penetrating:30 --foe-flees-at=0.25`. It reads and writes nothing. *The balance tool is built before the content*, because the ratios above are
      the whole design.
3. **NPCs** — **BUILT**: the `npcs` table, `admin/npcs`, the picture convention, `CombatantBuilder::forNpc()`,
   and `fight:simulate --foe=<slug>`. Whose NPC it is, its loot and its tags arrive with step 6.
4. **The fight itself** — **BUILT**: the `fights` table, `characters.current_fight_id`, `App\Combat\Fights`
   (the only writer), `EnsureFightIsResolved`, `/fight`, and `php artisan fight:start <handle> <npc-slug>` as the
   only way to open one until step 5. **Endings are minimal**: the player down goes to the waiting room, every
   other ending just releases them. **No payout, no mugging, no tags yet — that is step 6.**
5. **Encounters** — **BUILT**: a *Fight* choice kind (`encounter_choices.fight_npc_id` + `win_key`, `lose_key`,
   `flee_key`) and the resume (`EncounterRunner::afterFight()`). See *From an encounter* below.
6. **Defeat and loot**: mugging, `CombatTag`, `maim`, loot, standing — then a balance pass.
7. **Later**: PvP's opening, hunters on the street, a place that offers a fight (phase 6), dodge.

## Not decided

- Natural HP regeneration, medkits, items mid-fight; street heat; group fights; whether carrying a
  weapon is legal by district; an aimed shot at a chosen body part.
- More riders (a neural scramble, bleeding) and resisting a status directly; whether poison
  lingers after a fight as a real-time status.
- Area damage (a grenade hits one opponent while fights are one-on-one).
- Whether a limb hit should ever *do* something beyond its armour (a crippled leg hurting *Create
  distance*), which is where `maim` is headed.
- Skills: which skill aims which class, and what each of the six grants.
- Dodge: its attributes, whether armour weight costs it, whether it counts for less against
  firearms.
