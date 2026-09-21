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

**The gap this entry used to describe does not exist, checked 2026-09-21.** It
named the deck screen's fitting and stripping forms, the implant body map, the
stash's item actions and the admin editors' writes as unit-tested only. All
four post to their routes: `ModuleFittingTest` alone makes eleven writes and
twelve reads over HTTP, `ImplantTest` covers the body map and both surgery
routes, `ItemActionsTest` the stash, and the seven files in `tests/Feature/Admin`
hit the editors hard — including the delete that has to drop a skill's book
first. The entry was written from an impression rather than a count.

**What the count actually found: one route.** `run.start` — the door the
briefing's buttons post to, shipped in 0.12.0 as the only way a run begins —
had no HTTP test under any name, literal or routed. `RunStarter` was covered by
`RunTest` and the briefing by `RunBriefingTest`, and nothing in between ever
posted the form. `tests/Feature/Cyberspace/RunStartRouteTest.php` closes it:
every purpose walked off the enum, both backdoor kinds, every refusal as a
message rather than a 500, and what the form is allowed to say.

**And it found one thing about the code.** `RunRefusal::DeckNotReady` is
unreachable from `run.start` over HTTP: `EnsureDeckStillRuns` runs first on
every jacked-in request and turns a cyberspace route away with its own message,
so the controller's branch only ever answers callers that are not requests.
Not a bug — defence in depth, and `RunTest` proves the refusal — but the test
that assumed otherwise passed with the controller's refusal path deliberately
broken, which is the failure mode this whole entry exists to catch.

**To check it again rather than re-impress yourself:** list every route name,
grep the suite for each one as both `route('name')` and its literal URL (the
admin tests use literals, which is what made them look uncovered), and read
the count.

---

## Burn

**Status:** the only one of lockdown's four triggers still unbuilt, and it is
its own mechanic rather than a missing branch. `App\Cyberspace\Lockdown::shut()`
is public precisely so burn can call it — everything a machine does about being
violated is one write and one set of consequences, and burning is the loudest
version of finding out.

**What it needs, none of which exists:** a way to burn a system at all (a run
purpose, `RunPurpose::Burn` is not a case yet), what it costs the runner, and
what it leaves behind. CYBERSPACE.md says a burned system takes its address out
of existence rather than relocating — so it is NOT `RaidLedger::relocate()`, and
the position it frees stays empty for a while, which is the one thing that
consumes the grid's empty reserve.

---

## What backdoors still owe

**Status:** built, and both kinds do their job — a siphon pays nightly, a
routing hop goes on the route, and four separate things can take one away.

**What is left:**

- **Nobody is TOLD their backdoor was found.** The ageing roll logs it, because
  there is no notification centre and — unlike a cancelled run — no screen the
  player is about to land on that could carry the message. CYBERSPACE.md asks
  for a notification; this is the gap standing in for one.
- **Each hop hinders by its grade and nothing else**, which is decided rather
  than missing — see CYBERSPACE.md, *The routing chain*. What is NOT decided is
  what a SIPHON pays, which is the last thing backdoors owe.

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

## Idea for a last stage of a run
An idea would be to make the Operating System of a node be a challenge for any run on a node as well. Having level 5 in the same operating system as the node should make it more or less a 100% success (use the uncertainty floor - and make this a pass that takes 15 seconds each). Not having the skill at all would make it nigh on impossible to success (sub 5% chance), and scale up from there.


## The physical side of the game
Some ideas to develop later: A city map showing locations. Most locations are hidden from the player and must be discovered by exploring. This map shouldn't be a grid like the cyberspace map, but rather just districts that you can travel to and "walk the streets" (an action) to discover new stuff (can also be helped by skills later). Locations can hold certain specific markets (selling certain items), places where you can get treatment (heal HP/max HP, heal neural damage/SI etc), get cyber implants or augmentations etc. There can also be "random events" and quest chains in the city. And, of course, fighting NPCs (and even PVP later once we figure that out). Maybe heists/robberies/missions (similar to runs on a node but more demanding).

Maybe players can also buy "houses" and "apartments" - figure out what this would be more than "cosmetics". Also possibly buying into existing businesses to generate passive income (investments). Maybe crafting of items?

## Player Owned Systems ("Data Fortresses")
Systems in cyberspace that a player can "build". Should take quite a lot of skill, and time and resources to build. Works more or less like a normal system, but players can stash cash here, run "programs" that do things like generate passive income etc, use it as a very good route in the chain etc. Prime target for other players to attack. Players should here have to "construct" their own IDS and ICE, which should take skills and the higher the skill and the longer they work on it the stronger their systems become. A data fortress built by a skilled runner should more or less require a team to take down.
