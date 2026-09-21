# TODO

Deferred work, with enough context to pick up cold. Each item says what to change and
what will break if it is left alone.

---

## Switch `QUEUE_CONNECTION` to `database`

**Status:** `sync`, and **that is currently the right setting** — do not switch
yet. Kept here for the day it stops being right.

**Almost nothing is queued, because almost nothing can be.** Three events exist
(`SkillInjected`, `SkillLevelReached`, `TrainingEntryCompleted`) and `app/Listeners`
now holds exactly one — `NotifyOfSkillLevel`, which writes one row and has no
business on a queue. There are no jobs and no mailables, and **nothing in `app/`
implements `ShouldQueue`**. Only a listener implementing it moves off the request
path, so switching the driver today would change the behaviour of zero code paths
— jobs would go to a table nothing writes to.

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

**Mostly done, re-verified 2026-09-21.** The 2026-09-01 audit's worst findings
are fixed and this entry no longer describes the account. It ran as `lod@%`
with `ALL PRIVILEGES ON *.*` — `SUPER`, `FILE`, `SHUTDOWN`, `CREATE USER`,
`PROCESS` — able to see two unrelated production schemas. `FILE` alone turned
any SQL injection into file read/write on the database server. None of that is
still true.

**Where it stands:** `dedlok@%`, global privileges `USAGE` only, `ALL
PRIVILEGES` scoped to `cyberpunk`.* and nowhere else. `SHOW DATABASES` returns
`cyberpunk` and the two system schemas; `gryningsland` and `lod` are out of its
view.

**What is left, and it is the half that needs the server:**

- **The host mask is still `%`** — connectable from any host on the internet.
- **The connection is still unencrypted.** `Ssl_cipher` is empty on a live
  session and `require_secure_transport` is 0, so credentials and every query
  cross the internet in clear text.

```sql
ALTER USER 'dedlok'@'%' REQUIRE SSL;   -- or recreate it on a narrow host mask
```

Then point `config/database.php` at a CA bundle via `MYSQL_ATTR_SSL_CA`.

**Still true regardless:** never run `migrate:fresh`, `migrate:reset` or
`db:wipe` against the remote. The scoping means they can no longer reach
another project's data, so it is now a rule about losing your own — reason
enough. See CLAUDE.md for the safe local-override recipe.

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
  thing the inbox is for — `App\Notifications\Notifier`, which needs a
  `Category` case for it and a sender.

---

## What heat actually does

**Status: it has a first consequence, and that is all.** `characters.heat` goes
up by one every time a trace completes and two for a burn, and since
2026-09-21 `App\Characters\Bounties` reads it back: every character carrying
any is rolled once a night at five against the chance somebody decides they
are worth money. The uncertainty floor under five points, a straight line to
certain at a hundred.

**What lands is a notification and nothing else.** There is no bounty row, no
hunter and no price. The message says the word is out and stops where the
mechanic stops — which is the honest shape for a threshold whose consequence
has not been designed, and the inbox is what made even that possible.

**What it is.** Heat is persistent, it belongs to the person, and it is what
the world knows about them — the lasting half of being traced, where the burned
routing chain is the immediate half. It is deliberately a number to MANAGE
rather than a punishment to endure.

**One per trace is still a placeholder.** `cyberspace.trace.heat` holds it. A
real figure would depend on what the trace was following: the grade of the
machine, whether its owner is a faction, how long the run had been going. None
of that is decided.

**The curve is a straight line because nothing is balanced against it.**
`Bounties::chanceFor()` is one method, and the numbers are
`cyberspace.heat.bounty`. Anything shapelier would be a curve somebody has to
defend before there is a game to defend it against.

**What is still missing, and it is most of it:**

- **What a bounty IS.** A row, a hunter, a price, somebody who can collect it.
  Until then the roll is a rumour with nothing behind it.
- **A cooldown.** At high heat the roll is certain, so it says so every night.
  That is honest about a manhunt and intolerable the day a bounty is a thing
  you carry — the fix is a memory on the character, not a gentler curve.
- **Faction standing falls** when the traced system belongs to one. Factions do
  not exist; this is the hook they arrive on.
- **A player-owned system's owner learns who attacked them**, and that is the
  *only* way they find out. An untraced raid is anonymous, which is what makes
  stealth worth investing in on both sides. The inbox is where that message
  goes now.
- **Heat decays on its own, slowly.** Doing nothing is a valid way to cool off
  and a slow one. `characters:regenerate` is the shape that would credit it —
  a per-minute pass with no stored clock.
- **It can be CLEANED**, through processes not yet designed, and that is where
  the money sink is.
- **Later: heat draws rogue programs** while moving through cyberspace, and
  especially through subnets.

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

**Built, 2026-09-21.** `RunPurpose::Burn` was a case with nothing behind it and
`RunAction::Burn` did not exist; both do now, and the briefing's Burn button is
a real submit rather than a disabled one wearing a *Not built yet* tag.

**What it is.** A core touch, like extracting and installing, so it takes the
IDS's second look and wastes the turn silently on any other purpose.
`App\Cyberspace\Burner` is the only thing in the game that deletes a system:
the row goes, the address goes out of existence with it, backdoors cascade,
contents go, and the position is simply empty until a seeding pass puts
something new there. The run ends `Completed` — there is no machine left to
strike back, advance a trace or be locked down.

**Why it is not `Lockdown::shut()`**, which this entry used to predict. A
lockdown is a WINDOW and a burned machine has no window because it has no row;
writing `locked_down_until` onto a row about to be deleted is two statements
that disagree, and the sweep would find nothing to settle. What the two share
is the consequence — everybody else inside is thrown out — and that now lives
once, on `Simulator::cancelEveryRunOn()`, which both call.

**The order inside `Burner` is load-bearing: delete, THEN cancel.** Cancelling
first walks every other runner's ending through `Lockdown::afterRun()` on a
machine still standing, and one that had noticed any of them would shut, which
throws out everybody inside, which re-enters the loop already in progress.
Deleting first makes that unreachable by making the answer honest.

**What was decided rather than specified**, because CYBERSPACE.md left it open
and said only "what it costs the runner":

- **Heat, two points**, against the completed trace's one. The same placeholder
  on the same undesigned axis, and the only one that obviously fits a burn.
  `config/cyberspace.burn.heat`.
- **Nothing else.** The real price is the machine never being worth anything
  again, and a second invented cost would be balancing against a number nobody
  has set.

---

## What backdoors still owe

**Status:** built, and both kinds do their job — a siphon pays nightly, a
routing hop goes on the route, and four separate things can take one away.

**What is left:**

- ~~**Nobody is TOLD their backdoor was found.**~~ Done 2026-09-21: the ageing
  roll sends an inbox message (`App\Notifications\Category::Cyberspace`) beside
  the log line it already wrote. The log is for whoever reads the nightly job's
  output; the message is for the player, who is asleep.
- **Each hop hinders by its grade and nothing else**, which is decided rather
  than missing — see CYBERSPACE.md, *The routing chain*. What is NOT decided is
  what a SIPHON pays, which is the last thing backdoors owe.

---

## Housekeeping

- **`php artisan storage:link` will fail** — `symlink` is in `disable_functions` and that
  setting is `PHP_INI_SYSTEM`, so it cannot be scoped per-SAPI. If public file storage is
  needed, create `public/storage` from the host with `ln -s ../storage/app/public`.
- ~~**Raise the PHPStan level.**~~ Done 2026-09-21: `phpstan.neon` runs larastan
  at level 5, clean. Raising it further is possible but each level buys less;
  6 would mean annotating every iterable's value type.
- **Enable `session.cookie_secure`** before any HTTPS deployment — it is commented out in
  `docker/php/php.ini:50` for local HTTP.
- **`training_queue.last_deposited_at` is a MySQL `TIMESTAMP`**, so it cannot
  hold a date past 2038-01-19 — as with every `timestamps()` column in the
  schema. It always stores a moment near now, so nothing breaks today, but a
  migration to `DATETIME(6)` is the fix if a date that far out is ever stored.
- **Enable "block headless browsers" on the Cap site key** before launch. It is off on
  the local key because it breaks agent/QA browsers, and it is one of the two settings
  (with instrumentation, already on) that do most of the work against real bots.

## The physical side of the game
Some ideas to develop later: A city map showing locations. Most locations are hidden from the player and must be discovered by exploring. This map shouldn't be a grid like the cyberspace map, but rather just districts that you can travel to and "walk the streets" (an action) to discover new stuff (can also be helped by skills later). Locations can hold certain specific markets (selling certain items), places where you can get treatment (heal HP/max HP, heal neural damage/SI etc), get cyber implants or augmentations etc. There can also be "random events" and quest chains in the city. And, of course, fighting NPCs (and even PVP later once we figure that out). Maybe heists/robberies/missions (similar to runs on a node but more demanding).

Maybe players can also buy "houses" and "apartments" - figure out what this would be more than "cosmetics". Also possibly buying into existing businesses to generate passive income (investments). Maybe crafting of items?

## Player Owned Systems ("Data Fortresses")
Systems in cyberspace that a player can "build". Should take quite a lot of skill, and time and resources to build. Works more or less like a normal system, but players can stash cash here, run "programs" that do things like generate passive income etc, use it as a very good route in the chain etc. Prime target for other players to attack. Players should here have to "construct" their own IDS and ICE, which should take skills and the higher the skill and the longer they work on it the stronger their systems become. A data fortress built by a skilled runner should more or less require a team to take down.
