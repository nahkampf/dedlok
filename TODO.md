# TODO

Deferred work, with enough context to pick up cold. Each item says what to change and
what will break if it is left alone.

---

## Working backwards from a skill to the steps that reach it

**Status:** the rest of the /skills screen is built — a character's own skills
and levels, what is training, and the queue, with `TrainingQueueValidator`'s
warnings on it. What is not built is the convenience on top: pick a skill and a
level you WANT, and have the queue filled in for you, prerequisites and
intervening levels included.

**What it needs.** A walk of `App\Skills\PrerequisiteGraph` from the target
back to what the character already holds, turned into the (skill, level) pairs
that satisfy it, ordered so every entry's requirements are met by the ones in
front of it — which is exactly the property `TrainingQueueValidator` already
checks, so the output can be verified by the thing that judges a hand-built
queue. `TrainingQueue::enqueue()` appends one level at a time and is the only
writer; a plan is several calls to it rather than a new door.

**Two things it has to decide, and neither is obvious.** The queue holds five
entries and a plan can easily be longer — so either it fills what fits and
says what is left, or the limit moves. And a skill has to be INJECTED before
it can be queued at all, so a plan naming a skill whose book the character
does not own has to say so rather than silently skipping it.

---

## Scans
A small fix: Scan's should not have just "5 passes". Keep the scan window open and just continue doing passes until all the unknowns have been revealed. 


## Switch `QUEUE_CONNECTION` to `database`

**Status:** `sync`, and **that is currently the right setting** — do not switch
yet. Kept here for the day it stops being right.

**Nothing is queued, because nothing can be.** Three events exist
(`SkillInjected`, `SkillLevelReached`, `TrainingEntryCompleted`), `app/Listeners`
does not exist at all, there are no jobs, notifications or mailables, and
**nothing in `app/` implements `ShouldQueue`**. Only a listener implementing it
moves off the request path, so switching the driver today would change the
behaviour of zero code paths — jobs would go to a table nothing writes to.

It would also cost something. It requires a worker, and without one jobs queue
silently forever, which looks exactly like nothing happening. The `database`
driver also POLLS, and this application's database is on the public internet:
a worker asking a remote host for work every second is a continuous stream of
round trips.

**The trigger to switch**, when one of these becomes true:

1. **`MAIL_MAILER` stops being `log`.** A password-reset request would then
   block the form submit on SMTP, which is the classic first thing to queue and
   is probably the real trigger here.
2. **A listener appears on one of the three events** that does slow work — a
   webhook, an image resize, a fan-out write across characters.
3. Anything else where a player waits on work whose result they do not need.

Right now `Event::dispatch()` executes every listener inline before the response is sent.
That is fine while listeners are trivial, but the moment one does real work (an HTTP call,
an image resize, a fan-out write) the player waits for it, and a failure takes the request
down with it.

The tables are already migrated (`jobs`, `job_batches`, `failed_jobs`), so the switch is:

```diff
# .env
-QUEUE_CONNECTION=sync
+QUEUE_CONNECTION=database
```

Then run a worker alongside the web container:

```bash
docker compose exec -u www-data web php artisan queue:work --tries=3
```

**Do not switch without adding the worker** — jobs will queue silently and never run,
which looks exactly like nothing happening. For production the worker needs a supervisor
(a dedicated `worker` service in `docker-compose.yml` with `restart: always` is the
simplest option here).

Note that only listeners implementing `ShouldQueue` move off the request path; the rest
stay synchronous regardless of driver. Decide per listener.

Consider `redis` instead if the queue gets busy — the database driver polls, and every
poll is a round trip to a MySQL host on the public internet.

---

## Lock down the database account

**Parked by explicit decision, 2026-09-05.** Still the single largest risk in
the project; to be picked up before anything goes near production.

**Status:** running as `lod@%` with `ALL PRIVILEGES ON *.*`, unencrypted, by explicit
decision to defer.

The account holds `SUPER`, `FILE`, `SHUTDOWN` and `CREATE USER`, is reachable from any
host, and can see two unrelated production schemas (`gryningsland`, `lod`). `FILE` alone
turns any SQL injection into file read/write on the database server.

Create a scoped account and enable TLS:

```sql
CREATE USER 'cyberpunk_app'@'<app-ip>' IDENTIFIED BY '<strong-password>' REQUIRE SSL;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON `cyberpunk`.* TO 'cyberpunk_app'@'<app-ip>';
```

Then point `config/database.php` at a CA bundle via `MYSQL_ATTR_SSL_CA`.

**Until then:** never run `migrate:fresh`, `migrate:reset` or `db:wipe` against the remote
connection. See CLAUDE.md for the safe local-override recipe.

---

## Write the 2FA and passkey screens

**Parked by explicit decision, 2026-09-05.**

**Status:** both features commented out in `config/fortify.php`.

The columns (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`)
and the `passkeys` table are already migrated. What is missing is the Blade — challenge,
QR/recovery-code display, confirm-password, and passkey registration — built from the
Ash & Ember kit like the rest of `resources/views/auth/`.

Enabling the features before the views exist gives 500s on those routes rather than a
clear error, so keep them commented until the screens are written.

---

## Mission files, and how a mission knows it was completed

**Status:** not built, and deliberately noted rather than designed. What exists
is the half a mission will hang off: `systems.stock_rule` says what a machine
must be holding, and `system_contents` can hold a file that nothing generated.

**The shape, as described:** a quest file is not on a system until the player
takes the mission. Starting one INJECTS the file into a target system, and the
file is then the check — the mission is complete when that specific file is in
the player's possession rather than when some counter says so. That is the
right way round: the object is the evidence, so there is nothing to keep in
step with it and nothing that can disagree.

**What it will need, none of which exists:**

- **A mission of some kind**, with a target system and a state.
- **A way to mark a file as that file.** A `system_contents` row is generated
  rather than authored today and carries no identity beyond its id; a mission
  file needs to be recognisable after it has been extracted, which means it
  has to survive becoming something the player carries — and how a file becomes
  a possession at all is phase 5's question, not answered yet.
- **Protection from housekeeping.** The nightly restock only fills EMPTY nodes,
  so an injected file is safe from it today. A relocation or a burn is not
  something it would survive, and a mission whose target relocated overnight
  needs an answer.

**What is already true and should stay true:** a node's items are drawn at
random unless it was told, and a machine that was told holds exactly that. The
two shapes are `fixed` and `any_of` — see the migration that added the column —
and `plus_random` keeps the ordinary draw for a node that should not look
special.

---

## Drugs, addiction and tolerance

**Status:** designed, not built. Nothing here exists — no item category, no
family vocabulary, no ledger, no decay.

**A drug is a physical item with effects on it.** `container` is `physical` and
`items.modifiers` already carries what it does, so a drug needs no new column for
its effects — the same channel an implant uses. What it needs beyond that is
three properties:

| Property | Is |
| --- | --- |
| **family** | which group it belongs to — opiates, stimulants, and so on |
| **addictive** | how fast taking it drives the addiction figure up |
| **tolerance** | how fast taking it drives the tolerance figure up |

**The books are kept per FAMILY and per character, never per drug.** A character
can be deep into opiates and clean of stimulants at the same time, so the ledger
is (character, family) → addiction, tolerance. That is a table nothing resembles
yet: `character_modifiers` is the wrong shape because these are two running
totals rather than an effect with a duration.

**Both figures decay on their own, slowly.** `characters:regenerate` is the
precedent for how — a per-minute pass with no stored clock, or a lazy catch-up
off elapsed time if the decay has to be exact. The choice is the same one that
distinguishes the training heartbeat from the market's delivery run: exact
catch-up when a player is watching the number, a trickle otherwise.

**Addiction above a threshold is withdrawal**, and that half already has its
machinery: a withdrawal effect is a `config/effects.php` definition — a cause
with no row of its own, which is exactly what those are for — written as a
`character_modifiers` row. Reductions to Focus are the example. It clears when
the character takes a drug of the right family, which is the only thing that
clears it.

**Tolerance reduces what a drug DOES, in bands** — above one threshold its
positive effects are worth 10% less, then 20%, then 30%.

### The part that does not fit the resolver yet

**Tolerance modifies a modifier, and `App\Stats\Resolver` has no such idea.** It
resolves a stat from a base and a list of modifiers; it has no notion of one
modifier scaling another. A drug granting +10 Focus at 20% tolerance is not a
-20% modifier on Focus — that would also scale the implant, the perk and the
attribute underneath it. It has to reach that one grant and nothing else.

Three shapes, none obviously right:

- **Apply it at the moment of consumption.** The drug writes a
  `character_modifiers` row already reduced, and the row is honest about what it
  is worth. Simplest, and it makes tolerance a property of the DOSE rather than
  of the character — so a dose taken while clean stays strong as tolerance
  climbs around it, which may be right or may be exactly wrong.
- **A `source` on the modifier and a scaling rule keyed to it.** The resolver
  learns to scale modifiers carrying a given source. Cheapest at read time, and
  it is a genuinely new idea in a resolver that has deliberately stayed
  arithmetic.
- **A drug's grant is its own layer.** Tolerance becomes a `percentage_of_base`
  against a tag every drug wears — which MECHANICS already supports, since
  percentages sum and apply once — but only if a drug's contribution can be
  separated from the base, which it currently cannot.

**Decide that before authoring any drug**, because all three change what a drug
row looks like.

### What else it will need

- **Stacking.** `App\Stats\Stacking` exists and its own docblock uses this
  case: two doses of a stimulant add up, two implants doing one job do not. A
  drug's effect wants the adding answer, and that is a choice on the definition.
- **An overdose.** `App\Stats\InjuryLedger` already says a future overdose
  should write into the same *neural damage* injury a freeze and an ICE flatline
  write into — one row that deepens, so a clinic heals one number.
- **A way to be told.** Crossing a withdrawal threshold is exactly the kind of
  thing the unbuilt notification queue is for.

---

## What heat actually does

**Status:** it accumulates and nothing reads it. `characters.heat` goes up by
one every time a trace completes, and that is the whole of the mechanic.

**What it is.** Heat is persistent, it belongs to the person, and it is what the
world knows about them — the lasting half of being traced, where the burned
routing chain is the immediate half. It is deliberately a number to MANAGE
rather than a punishment to endure.

**One per trace is a placeholder.** `cyberspace.trace.heat` holds it. A real
figure would depend on what the trace was following: the grade of the machine,
whether its owner is a faction, how long the run had been going. None of that is
decided.

**What CYBERSPACE.md already says it should do**, none of which is built:

- **Faction standing falls** when the traced system belongs to one. Factions do
  not exist; this is the hook they arrive on.
- **A player-owned system's owner learns who attacked them**, and that is the
  *only* way they find out. An untraced raid is anonymous, which is what makes
  stealth worth investing in on both sides.
- **Heat decays on its own, slowly.** Doing nothing is a valid way to cool off
  and a slow one. `characters:regenerate` is the shape that would credit it —
  a per-minute pass with no stored clock.
- **It can be CLEANED**, through processes not yet designed, and that is where
  the money sink is.
- **Later: heat draws rogue programs** while moving through cyberspace, and
  especially through subnets.

**The thresholds it crosses are the first thing to decide.** A number that only
goes up and does nothing is not a mechanic — what makes heat real is the point
at which something changes, and nothing names one.

---

## The gap that let a 500 ship

**Status:** closed for the run routes and open everywhere else.
`tests/Feature/Cyberspace/RunScreenSmokeTest.php` walks every run route over
HTTP — the screen in five states, every `RunAction` through `run.act`, the
standalone egress door, and the lock in front of the other screens. It was
written after two bugs shipped in 0.10.0 that fourteen hundred unit tests could
not see, because every one of them built its own `Simulator` and nothing ever
posted to the route.

**The rule it encodes.** A test that constructs its own collaborators cannot
find a container problem, and a mechanic tested only through its own class
cannot find a broken screen. Anything with a route wants at least one test that
takes what a real request takes.

**Where the same gap is still open** — no HTTP-level pass exists over the deck
screen's fitting and stripping forms, the implant body map, the stash's item
actions, or the admin editors' writes. The cyberspace map has one (the scan
dialog polls through `cyberspace.reading`, which a test exercises), and the run
screen now has one. The rest are unit-tested only.

---

## Lockdown, burn and relocation

**Status:** relocation is built and lockdown is not. `App\Cyberspace\RaidLedger`
moves a machine — new position, new address, counter back to zero — and the
third raid is the one trigger that calls it. CYBERSPACE.md's *Lockdown* table
lists four, and the other three have nothing behind them.

**What is missing.**

- **The lockdown WINDOW itself.** A state on `systems` with a duration, during
  which the machine is locked down rather than gone. The map already has the
  vocabulary for it: `cell--rebooting` is drawn as a dashed hairline and
  `MapCell::$isRebooting` is a parameter nothing sets.
- **The three other triggers.** *The IDS fired* and *the ICE activated* are
  facts a run already knows — `runs.detected` and `runs.ice_id` — and both are
  applied AFTER the run, so they are one call each at the same moment the raid
  counter is checked. *Burn* is its own mechanic and does not exist.
- **Cancelling a run the machine went out from under.** `RunEnding::Cancelled`
  exists with no caller, and its doc says it costs the flat point of Synaptic
  Integrity a readiness loss costs — which also needs a `DamageOrigin` case of
  its own, since an origin is the axis a resistance is written against. Until
  it exists, `RaidLedger::retireIfSpent()` holds a machine in place while
  anybody is inside it rather than inventing half of this.

**Why they belong in one pass.** All four triggers want the same state, the
same duration and the same map treatment, so building the window for one of
them privately is building the thing the pass has to replace.

---

## The routing chain

**Status:** the SHAPE exists and nothing is IN it. `Run::routingGrades()`
returns `[]` always, which is why a trace currently walks unopposed from the
first turn — the empty case CYBERSPACE.md describes for "no routing", and the
only case that has ever been true.

**What is already built around the gap.** `Simulator::traceGetsThrough()` and
the whole trace-walking mechanic (`runs.trace_hop`, one attempt at the NEXT
hop per turn) were written to take a `list<int>` of grades from
`routingGrades()` — nothing about the trace mechanic changes when this lands,
only what that one method returns.

**What is missing, per CYBERSPACE.md's own *Backdoors* and *The routing
chain* sections:**

- **Backdoors do not exist at all.** No install action, no `backdoors` table,
  no way to hold access to a system. `RunPurpose::Backdoor` is a case in an
  enum and nothing else.
- **Routing and Advanced Routing are skill rows with no reader.** They exist,
  they have books, nothing asks them how long a chain a character may run.
- **A run does not let a player CHOOSE a chain.** CYBERSPACE.md: "the chain is
  declared per run, not held standing" — a run needs to pick its route from
  the backdoors a character holds, at the moment it starts, alongside purpose
  and target.
- **A completed trace does not burn anything**, because there is nothing to
  burn. `Simulator::burnTheChain()` is already called at the right moment and
  is a deliberate no-op with a comment saying so.

**The size of this one.** Larger than Surge's numbers or heat's thresholds —
it is a whole unbuilt purpose (Backdoor) plus the two skills that gate it
plus the run-creation screen learning to ask for a route. Worth breaking into
its own pass rather than picking up as a side effect of something else.

---

## Housekeeping

- **`php artisan storage:link` will fail** — `symlink` is in `disable_functions` and that
  setting is `PHP_INI_SYSTEM`, so it cannot be scoped per-SAPI. If public file storage is
  needed, create `public/storage` from the host with `ln -s ../storage/app/public`.
- **Raise the PHPStan level.** No `phpstan.neon` exists, so it runs at level 0 and catches
  almost nothing. Level 5 is a realistic first target for a Laravel app.
- **Enable `session.cookie_secure`** before any HTTPS deployment — it is commented out in
  `docker/php/php.ini:50` for local HTTP.
- **`training_queue.last_deposited_at` is a MySQL `TIMESTAMP`**, so it cannot
  hold a date past 2038-01-19 — as with every `timestamps()` column in the
  schema. It always stores a moment near now, so nothing breaks today, but a
  migration to `DATETIME(6)` is the fix if a date that far out is ever stored.
- **Enable "block headless browsers" on the Cap site key** before launch. It is off on
  the local key because it breaks agent/QA browsers, and it is one of the two settings
  (with instrumentation, already on) that do most of the work against real bots.
