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

## Entities, standing, loyalty and the Exchange

**Status: designed 2026-09-23, none of it built.** Hashed out in full; what is
missing below is named at the end. The mission entry that follows this one
still holds the completion mechanic, and nothing here replaces it.

### Entities are `corps`, renamed and grown

`corps` already owns 2,866 of 2,867 systems and `systems.owner_id` is already
named generically, so this is a rename and two columns rather than a table.

- `name`, unique, as now — `SystemNamer` composes system names from it.
- `category` — corporation, government, military, fringe. **A PHP enum**, the
  call `Family`, `BodyRegion` and `SlotType` make: a fixed vocabulary of four
  is not rows, and a fifth is a case and a label.
- `interactable`, **default false** — the call `archetypes.playable` and
  `seed_quantity` make. Nothing becomes real by being forgotten.
- `business` — the existing one-liner, on a system card.
- `description` — new, nullable, long, for the Exchange screen.

**All 121 existing rows become flavour corporations**, and flavour entities
generate nothing: no missions, no standing, no store. They still own systems,
still name them and still appear on cards. **Six to ten get promoted** with
real descriptions and categories, which is what gives the world stakes on day
one, since they already own hundreds of systems each. A promoted entity's
history starts the day it is promoted: it does not remember what was done to
it while it was scenery.

The rename touches `Corp`, `System`, `SystemNamer`, `GridSeeder`, the system
factory, two tests, `config/cyberspace.php` and `strings`. Old migrations are
history and are not edited. `TODO.md`'s own heat entry says *faction standing*
and should say entity.

### Standing: two tables, one writer, one invariant

- **`entity_books`** — one row per (character, entity): `standing` and
  `loyalty`. A missing row is neutral and broke, the way a missing
  `character_drug_use` row is clean. Two running totals about one
  relationship, so one row rather than two tables.
- **`entity_ledger`** — one immutable row per change: character, entity,
  `kind` (standing or loyalty), `delta`, `reason`, `happened_at`. No
  `timestamps()`, the call `market_purchases` makes. One table for both kinds,
  because an entity screen wants one history of what passed between you.
- **`App\Standing\StandingLedger`** is the only writer of both. A mission
  completed writes a standing entry AND a loyalty entry in one transaction.

**THE INVARIANT: books = SUM(ledger), per kind, always.** It survives clamping
and decay because every delta records WHAT LANDED — a raid against an entity
already at −100 writes a delta of 0, which honestly says the raid changed
nothing. `standing:verify` is then three lines, and a disagreement is a bug
rather than a mystery.

- **Standing is `DECIMAL(6,3)`, not a float**, and so is every delta. The same
  call `characters.heat` makes. Binary floats accumulate error and would drift
  the invariant for no reason; three decimals holds increments like 0.003 and
  the range −100.000 to +100.000.
- **Clamped to [−100, 100]**, by trimming the delta rather than the total.
- **Standing drifts toward zero nightly, PROPORTIONALLY: 0.5% of what is
  carried, from both directions.** Not a flat rate, which at the size of a
  mission's reward would be backwards — half a point a night is twenty-five
  grade 1 jobs and nothing at all to somebody sitting at 60. A percentage costs
  0.03 at standing 5 and 0.3 at standing 60, so low standing is stable and high
  standing is a commitment, and it can never overshoot zero. **It also gives
  every rung a soft ceiling for free**: standing settles where daily earnings
  equal 0.5% of it, which is about 200× the daily rate — grade 1 work alone
  tops out around 30, below the grade 4 desk, so the bands enforce themselves
  without another rule. **The pass writes a `Decay` entry** for every standing
  it moves, or the invariant stops being checkable. `ponytail:` one row per
  character per entity per night is the ceiling; collapsing entries older than
  N days is the fix when it bites.
- **Loyalty never decays and floors at 0.** A grudge fades because people
  forget; a favour bank is a debt somebody owes you. Decaying it would also
  make it a currency that rots in the pocket.

**What moves them**, every value in `config/standing.php`:

| Reason | Standing | Loyalty |
| --- | --- | --- |
| Noticed on their system | small − | — |
| Raided their system | − | — |
| Burned their system | large − | — |
| A backdoor of yours found there | small − | — |
| Mission completed | + | + |
| Spent at their store | small + | − |

The callers already hold the system, so they hold the owner: `Simulator` as a
run ends, `RaidLedger`, `Burner`. **`Burner` writes before it deletes** — the
entry stores the entity id, so a system that no longer exists still cost you.

**Spending is proportional to what was spent**, not flat per purchase, or ten
cheap buys would beat one expensive one; when it rounds to nothing no standing
entry is written, because the loyalty entry already records the purchase.
**It cannot be farmed, structurally**: loyalty is consumed by spending and only
missions mint it, so the standing somebody can buy is capped by the missions
they actually did. Do not "fix" this later with a cooldown.

### The Exchange is a system that is not one

A new `SystemType` case. **It cannot be run against, burned, relocated, locked
down, scanned or raided, and it has no defences, no contents and no operating
system.** It offers two things instead: missions and the loyalty store.

**`SystemType::isInteractable()` splits here, exactly as its own docblock
predicted** — *"when something answers no to only some, this becomes four."*
An Exchange answers no to scanned, intruded and burned, and YES to owned. The
five callers today are the map's *can run*, `RunController`, `Probe`,
`RunStarter` and `Defences`, and each wants a differently named question.

- **`exchanges`** — one row per exchange system: `grade` 1–5, agent name,
  `min_standing`. The shape `programs` and `deck_modules` already have against
  `items`: nothing on the `systems` row says which, and the row is what says
  it. **The agent's grade IS the exchange's grade** — one number, so "which
  agent" and "which shelf" can never disagree.
- **`systems.grade` is NOT the exchange's grade.** That column is 1–9 and
  means difficulty; it is what scans read, what ICE and the loot tables key
  off and what the OS ladder follows. An Exchange leaves it at its default the
  way the portal does, or every existing reader of grade starts silently
  answering questions about a shopfront.
- **An entity has up to five, one per grade, most have grades 1–3.** So six to
  ten interactable entities is roughly twenty-five exchange systems.
- **Higher grades sit deeper**, the way system grade already tracks distance
  from the portal. Getting to a grade 5 desk is a journey.
- **Placed, never rolled**, like the portal — and excluded from the seeder's
  top-up, from `cyberspace:restock`, from lockdown and from relocation. An
  exchange is permanent: that is what lets it be listed.
- **Its glyph on the map is U+2696, the scales** — the one glyph in the set
  already proved to render that means a DEAL rather than a system. It is the
  market's rail icon today and the echo is right: both are places you trade,
  and the two never share a context. Runner-up if that echo is unwanted:
  U+25C9, in the gallery's own cell demo and unused on the map, but a
  near-twin of the portal's U+25CD. **Colour says type and nothing else** on
  this map — steel silo, cyan compute, jade relay — so an Exchange is BRASS,
  which no type uses. **It does not pulse.** The portal breathes because there
  is one of it; twenty-five breathing desks is the disco the map rules already
  refuse. A hairline and a still glyph: the portal's quieter sibling. The key
  beside the map gains a row.
- **Found by exploring, then remembered and listed.** Walking onto one records
  it as every sighting is recorded, and from then on it is on a directory
  screen with its address and grade, so nobody has to find it twice. A
  shopfront announces itself, so a contact carries the entity and the grade
  without a scan — which is what makes "cannot be scanned" livable.

### The screen: Missions & Loyalty

Reached by standing on the cell, the way *Plan a run* is reached for an
ordinary system. It carries: the entity's name, category and description; the
character's standing as a number and a band; the agent by name and grade; the
missions on offer; and the store.

- **`entity_offers`** — entity, **grade**, item, `loyalty_price`,
  `min_standing`. A shelf belongs to an (entity, grade) pair, so a grade 1 desk
  cannot sell what a grade 4 desk keeps. No stock column: an entity's private
  shelf is not restocked by anything, and that is a column to add the day
  somebody wants a limited run.
- **The items are ordinary catalogue rows**, typically `on_market = false` —
  which is already how content is withheld — so this is a second DOOR onto the
  catalogue rather than a second catalogue.
- **`App\Standing\LoyaltyExchange`** is the only writer: a transaction, the
  gate and the balance checked inside it, `ItemGranter` to hand the item over,
  and the two ledger entries. Refusals are an enum, as purchases already are.
- **ONE GATE, NOT TWO.** "Some agents want standing above a rating, most do
  not" and "nobody sells at negative standing" are the same rule:
  `min_standing`, default 0, with a CHECK that it cannot be negative. A gruff
  agent is a row with 25 in that column.

### Phasing, and the ordering problem

1. Entities: rename, category, `interactable`, promote six to ten, docs.
2. Standing: both tables, `StandingLedger`, reasons, config, the hooks in the
   endings that already know the owner, the nightly drift, `standing:verify`.
3. **A reader, in the same release**: the band on the system card and the run
   briefing. `characters.heat` was a number nothing read for four releases and
   this is the same trap.
4. Exchanges: the type split, the table, placement, the directory.
5. Missions — see the Missions entry below.
6. Loyalty and the store, which are small once missions land.

**Steps 1–3 stand on their own and are shippable now.** Everything positive
waits on missions: until they exist, standing can only go down, and the store
is a shop whose currency nobody can earn.

### What is NOT decided

- **What a mission IS** — designed now, in the entry below.
- **Rivalries.** Hurting one entity pleasing another is a graph nobody has
  asked for yet; the ledger makes it addable without a migration.
- **Whether flavour entities can ever be promoted**, and what that does to the
  players who have been raiding them. Decided for now: history starts at
  promotion.

---

## Missions

**Status: designed 2026-09-23, none of it built.** Reads on from the entities
entry above, which holds standing, loyalty and the Exchange. A mission is work
done for an entity: a designated set of systems, an action required of each,
and a clock.

### What one is

**Generated per character, never authored and never shared.** An agent's board
is rolled for whoever is standing at the desk and **refreshed hourly** —
lazily, when the screen is opened and the board is older than the hour, the way
`Probe::settle()` catches up rather than being swept. Nobody can take your job
and two players at one desk see different work.

**Declining clears the job until the next refresh.** So the hour is a real
decision: turning down a burn you do not fancy costs you that desk's offer of
it until the clock comes round, and a board somebody has picked over is a board
with less on it. The floor holds even then — the board is never empty, so a
player who declines everything still finds one job waiting, and it is not the
one they threw back.

- **`missions`** — the instance: character, entity, the exchange it came from,
  `action`, `state`, the clock (`offered_at`, `accepted_at`, `expires_at`,
  `completed_at`) and the rewards SNAPSHOTTED at the offer, so retuning
  `config/missions.php` never changes a job somebody already took. The same
  call `market_purchases.unit_price` makes.
- **`mission_targets`** — one row per designated system: `mission_id`,
  `system_id`, the ADDRESS as it stood when the job was taken, and
  `satisfied_at`.

**THE BOARD IS NEVER EMPTY.** Whatever the roll says, at least one job is on
it — an agent with nothing to offer is a screen that wasted the walk, and a
player who finds an empty desk twice stops visiting desks.

**One active mission per entity.** Three entities can have you working at once;
one agent cannot hand you two jobs. A cap in config, refusals as an enum.

**The window is 4–24 hours**, rolled per mission and started on ACCEPT.
**It must stay under `cyberspace.scan.lifetime_hours`, which is 48** — a scan
file made in the first minute of a 24-hour job is still good at hand-in, and
that is the rule rather than a happy accident. Raising the window past the
file's life would make the file's expiry a second, invisible deadline.

### Evidence differs by action, and that is what `satisfied_at` is for

A target is satisfied either by an act RECORDED WHEN IT HAPPENS or by evidence
CARRIED IN. One column, filled two ways, and a new action picks whichever
suits.

- **Scan** — 3 to 10 DESIGNATED targets. The evidence is the scan file, and it
  is checked at hand-in and then SPENT. `system_scans.used_at` has read *"Nothing spends
  one yet"* since the table was created; this is what spends one, which also
  means one file cannot be sold to two agents. Perishable and losable: that is
  the tension the action is for.
- **Burn** — one or two designated targets, and the evidence cannot be carried
  because the system is gone. See below.
- **Plant** — one to three designated targets, and the evidence is the act:
  the install path writes `satisfied_at` as `Burner` does. See below.
- **Supply** — no designated targets at all; see below.

### Burn: the job that takes a system out of the world

**One or two designated targets in the agent's band, and the system is gone
when it lands.** `App\Cyberspace\Burner` is the only thing in the game that
deletes a system, and it writes `satisfied_at` through the mission writer in
the same transaction that writes the standing hit to the owner — because the
evidence cannot be carried: there is nothing left to bring back.

- **It is a core touch**, so the operating system has to be spoken and the IDS
  gets its second look. A landed burn is the last turn of the run: nothing is
  left to strike back, advance a trace or be shut, and the run ends Completed.
- **The politics are the sharpest of the four.** Burning is the largest
  standing hit a target's owner can take from you, so a burn job against
  another INTERACTABLE entity is the clearest case of being paid by one
  organisation to make an enemy of another. That is the type to watch when the
  grind-down worry above becomes real.
- **It permanently shrinks the grid**, which nothing else a player does can:
  the row goes, the address goes out of existence, and the position is empty
  until a seeding pass puts something new there. `cyberspace:seed` tops the
  world up to a target, so this is self-correcting — but a burn job is the one
  mission type that leaves the world different afterwards.
- **A system cannot be burned twice**, so a target that somebody else burned
  first voids the job without penalty, exactly as relocation does.
- **It pays the most creds** and heavy standing, against the heaviest failure
  cost at that grade: the type is the sharp end of the board.

### Plant: a siphon that is not yours

**The job is to put the entity's siphon on somebody else's system.** One to
three designated targets in the agent's grade band, and it is the most
dangerous of the four: an install is a core touch, so the IDS gets its second
look, and a failed install cleans up nothing.

**NO `backdoors` ROW IS WRITTEN FOR THE PLAYER.** That is the whole point of
the type. The door belongs to the entity, so it pays nobody nightly, it is not
a routing hop, it never appears in `ChainBuilder`'s chain, and none of the four
things that take a backdoor away has anything to take. **The flag IS the
record**: `satisfied_at` on the target row, and nothing else in the world
changed for the runner.

- **It is still an install**, so the one consequence that is not about
  ownership still holds: a landed install means no lockdown even if the run
  was noticed, exactly as `runs.backdoored` already says. The system forgives
  the visit; it just does not owe the visitor anything afterwards.
- **A run installs once.** `runs.backdoor_kind` is chosen at the door, so a
  run spent planting for an entity is a run not spent planting for yourself.
  That is the cost that makes the type a choice rather than free standing.
- **Holding your own door on the target is allowed** and changes nothing about
  it: your backdoor is untouched, and its approach discount makes the job
  easier — the same licence the *backdoored systems may be targeted* rule
  above already grants.
- **It pays the most standing of the four** and moderate creds. A player is
  handing an organisation a permanent foothold on somebody else's system,
  which is worth more to them than intel and more dangerous to get caught at.
- **What the entity DOES with it is not modelled**, and should not be invented
  here. No income accrues to anybody, no NPC uses the foothold. The day
  entities have an economy of their own, this is the hook it arrives on.

### Supply: scan files as a trade, and scanning as an occupation

**THE AGENT NAMES NO SYSTEMS. They are buying intel by the file.** A supply
job says *bring me N complete scan files of grade 5–7 systems* and the player
brings whatever they have or go and get. It is the low-risk half of the
mission board and the reason somebody might run a deck built entirely around
analysers.

- **`missions.quantity_required`** instead of assigned targets, and the grade
  band is the agent's own band — the same table the designated jobs use.
- **THE TARGET ROWS ARE THE RECEIPT RATHER THAN THE ASSIGNMENT.** They are
  written AT HAND-IN, one per file accepted, carrying the system and the
  address the file was made against. Same table, same `satisfied_at`, opposite
  direction — and the mission still records exactly which systems were sold.
- **Only COMPLETE files count** — `SystemScan::isComplete()`, every readable
  fact known. A one-pass file that learned the grade and nothing else is not
  intel, and requiring the whole picture is what makes the instrument and the
  skills behind it matter: a better analyser and System Profiling are the
  difference between a file that sells and one that does not.
- **They are spent, like every other hand-in.** A file sold to one agent is
  gone, so the same scan cannot be traded twice.
- **Files made BEFORE the job was taken count.** That is the whole point: a
  scanner works the grid, builds a stock, and sells it to whoever is buying —
  which is the first sink scan files have ever had, and the shape NOTICE.md's
  roadmap means by *scanfiles get collected and sold*. The 48-hour life of a
  file is what stops a stockpile being a warehouse.
- **Quantity falls as the grade rises**, because a grade 8 file costs a great
  deal more to make than a grade 2 one: roughly 6–10 files at grade 1 down to
  2–4 at grade 5.
- **It pays in CREDS first.** Standing and loyalty are lower than a designated
  job of the same grade — this is trade rather than service, and the income is
  the reward. That also keeps it from being the quiet fastest route up the
  standing ladder.
- **What stops it being farmed is the clock on the instrument.** A pass takes
  minutes, a read runs per analyser and per thread, and files die at 48 hours.
  Throughput is bounded by hardware and by the grid, not by a rule anybody
  writes here.

### Handing in, failing, and being let off

- **Completion happens at the agent**, on the Missions & Loyalty screen. Every
  target satisfied, then the rewards: creds, standing and loyalty, scaled by
  the exchange's grade, the number of targets and the action — a burn is worth
  more than a scan. All in `config/missions.php`.
- **Failure is the clock.** `missions:expire` runs on the five-minute tick the
  lockdown sweep already uses, because the penalty has to land whether or not
  the player ever comes back. A small standing hit, and the mission is
  cancelled.
- **ABANDONING COSTS WHAT EXPIRING COSTS.** If it were free nobody would ever
  abandon: they would sit on the job and let it run out, and the game would
  have charged them four hours for the same outcome.
- **A target that stops existing VOIDS the mission, with no penalty.** Burned
  by somebody else, relocated out from under you, or shut for longer than the
  window — none of it is the runner's fault. Detected by the snapshot: the
  system row is gone, or its address no longer matches the one the job was
  written against. A locked-down target is the same case, because a burn needs
  a run and a lockdown's close is a relocation.
- **The player is TOLD.** A mission expiring or being voided while somebody is
  asleep is exactly what the inbox is for. That wants a `Category` case of its
  own, and its `route()` is the Exchange directory — which is also the answer
  to the null route `Street` carries today.

### Targets, and the politics that fall out of them

**THE AGENT'S GRADE DECIDES WHICH SYSTEMS IT MAY NAME**, and the bands
overlap so each rung shares ground with the one above:

| Agent grade | Targets |
| --- | --- |
| 1 | grades 1–3 |
| 2 | grades 3–5 |
| 3 | grades 5–7 |
| 4 | grades 6–8 |
| 5 | grades 7–9 |

So the bottom desk is walkable for a bare character and the top one is not
survivable without the training and the hardware — the grade band IS the
difficulty gate, which is why the standing gate below is about something else.

**STANDING IS THE LADDER INTO THE ROOM.** `exchanges.min_standing` is set by
grade and not left at its default, so the better desks have to be earned as
well as survived:

| Agent grade | Standing required |
| --- | --- |
| 1 | 0 |
| 2 | 5 |
| 3 | 20 |
| 4 | 40 |
| 5 | 60 |

The column and its CHECK already exist for the store, so this is a number in
the seeder rather than a mechanism — and it is the same gate the shelf reads,
so a desk that will not give you work will not sell to you either. **It is
also what stops standing being only a store gate and a number that drifts**:
the way up is small jobs first, which is the shape the grade bands above
already imply.

Note what it costs a player who burns bridges: falling under a threshold shuts
the desk they had earned, and the only ways back are time (the nightly drift)
and jobs for the desks that will still see them.

**BOTH SIDES SCALE WITH THE AGENT'S GRADE.** Creds, standing and loyalty all
climb; so does what failing costs. One table in `config/missions.php` per
grade, so the whole curve is one thing to retune. **A void still costs nothing
at any grade**, because a system that somebody else burned was never the
runner's fault.

The standing half of it, which is the one the gates above are measured in:

| Agent grade | Standing per mission |
| --- | --- |
| 1 | +0.01 – 0.03 |
| 2 | +0.03 – 0.07 |
| 3 | +0.07 – 0.15 |
| 4 | +0.15 – 0.30 |
| 5 | +0.30 – 0.50 |

**These are small on purpose and the climb is long.** At six to eight jobs a
day against one entity it is roughly a month to the grade 2 desk and three to
four months to the grade 5 desk — a browser game's arc, not a weekend's. The
0.5% nightly drift is what keeps that from being a straight line: grade 1 work
alone settles around 30 and no amount of it reaches 60.

**The reward is a RESOLVED STAT, not a number the writer reads off config.**
`standing_gain` based in `stats.bases`, the shape `heat_decay` already has, so
the first skill, implant or perk that makes somebody better at being liked is
a modifier and nothing in the mission writer learns it exists. The config
table is the BASE per grade; the sheet does the rest.

**A system you already hold a backdoor on MAY be a target.** It makes the job
easier — a scan is a walk, and a burn skips the approach roll — and that is
the door paying for itself, which is what a backdoor is for. What it also
means is that the run which loses the backdoor loses the advantage with it.

**Drawn from anywhere but the giver**, within the band above. Some belong to flavour corps and cost nothing; some belong to
interactable entities and cost standing through the hooks that already exist.
**So a job is a choice about who to annoy, and nothing had to be written to
make that true.**

Watch for the obvious abuse the day both halves exist: burn missions aimed at
one interactable entity are a way for a player to grind that entity's opinion
of them into the floor on purpose. The fix, if it needs one, is which entities
are drawn as targets — not a rule in the ledger.

### Retrieval: the fourth action, and the one the old entry was about

**Not built, and deliberately noted rather than designed.** What exists is the
half it hangs off: `systems.stock_rule` says what a system must be holding,
and `system_contents` can hold a file that nothing generated.

**The shape, as described:** a quest file is not on a system until the player
takes the mission. Starting one INJECTS the file into a target system, and the
file is then the check — the mission is complete when that specific file is in
the player's possession rather than when a counter says so. That is the right
way round: the object is the evidence, so there is nothing to keep in step with
it and nothing that can disagree. It is `satisfied_at` filled a third way.

**What it still needs:**

- **A way to mark a file as THAT file.** A `system_contents` row is generated
  rather than authored and carries no identity beyond its id; a mission file
  has to be recognisable after it has been extracted, which means surviving
  becoming something the player carries —
  `inventory_items.extracted_file` is where that identity would live.
- **Protection from housekeeping.** The nightly restock only fills EMPTY
  nodes, so an injected file is safe from it. A relocation or a burn is not
  something it would survive — which the void rule above now answers.

**What is already true and should stay true:** a node's items are drawn at
random unless it was told, and a system that was told holds exactly that. The
two shapes are `fixed` and `any_of`, and `plus_random` keeps the ordinary draw
for a node that should not look special.

### What is NOT decided

Nothing structural. What is left is the numbers — the reward and penalty curve
per grade, how many targets a scan job names within its 3–10, and how the
window is rolled inside 4–24 hours — and every one of those is a line in
`config/missions.php` to be tuned once somebody can play it.

---

## Drugs, addiction and tolerance

**Status: built 2026-09-21, and the resolver never learned anything.**

The loop is whole: a drug is an item with a `drugs` row, taking one writes a
`character_doses` row read by `App\Stats\Sources\ActiveDoses` (the sixth
modifier source), the books move per (character, family), tolerance cuts what
the next dose is worth, addiction past a threshold is a withdrawal you carry,
overdosing deepens `neural_damage`, and `drugs:metabolise` takes a day off both
figures nightly.

**The question this entry used to be about is answered.** Tolerance is applied
AT THE DOSE and frozen onto the row as `potency` — the first of the three
shapes. `App\Stats\Resolver` still has no idea that one modifier can scale
another, and did not have to learn. The cost of that choice is the one the
entry predicted: a dose taken while clean keeps its strength for its whole
duration even as tolerance climbs under it. That is the intended reading — what
somebody swallowed is what they swallowed.

**What is left, and none of it is blocking:**

- **No drug is authored.** The editor can make one and nothing sells one, so
  the catalogue is empty. Every number in `config/drugs.php` was chosen to make
  the loop legible rather than because anything measured it.
- **Nothing costs money.** A drug is an item, so this is the market's problem
  rather than this mechanic's.
- **A clinic, and it is two injuries overdue.** Neural damage accumulates from
  overdoses as well as from an ICE flatline, and nothing heals any of it. That
  is one screen and one writer, and it is worth doing once rather than per
  cause.
- **Withdrawal is one modifier list per family and does not deepen.** Being
  forty points over the line reads the same as being one. A depth term would
  mean an `:amount` on the definition and `InjuryLedger`'s shape, which is a
  bigger change than it looks and nobody has asked for it yet.
- **Tolerance has no ceiling.** Nothing stops the figure climbing past the last
  band, where it buys headroom for ever and costs nothing more. Capping it, or
  making the top band hurt, is a design decision rather than a bug.
- **Nothing lists "what is in you right now".** An overdose is said in the
  flash message and lands in the injury, the body and sometimes the waiting
  screen — but a player cannot see what they are carrying or how close they are
  to the line. The character sheet grew a Vitals section in 0.15.0 and a dose
  panel is the obvious thing beside it, and the only place `Overdose` would
  want a preview method.

**The design notes that used to follow are gone, swept 2026-09-23.** They
described the shape of a mechanic that is now built, and two of them had already
been answered by the thing that shipped: tolerance is applied at the dose and
frozen onto the row as `potency`, doses sum because two rows sum, and the
overdose writes into the same *neural damage* injury a freeze does. What has not
happened is the third: crossing a withdrawal threshold still sends no inbox
message, and `App\Notifications\Notifier` is what it would use.

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
- ~~**Heat decays on its own, slowly.**~~ Done 2026-09-21: half a point a night
  at 05:10, after the bounty roll so a character answers for the heat the night
  ended with. The rate is the `heat_decay` stat, based at 0.5 — nothing raises
  it yet, and the first skill that does needs no code in `characters:cool`.
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
  than missing — see CYBERSPACE.md, *The routing chain*.
- ~~**What a siphon pays is undecided.**~~ Decided and paying: 30 a night per
  grade of machine — `cyberspace.backdoors.siphon.per_grade` — run by
  `cyberspace:siphons` before the ageing roll. The base is the machine's and the
  modifiers are the person's — `siphon_yield` is a resolved stat, and no skill
  moves it yet.
- **Nothing else is outstanding.** Both kinds do their job; what is left is
  balance, and nothing is balanced against anything yet.

---

## Author skills until every value has one behind it

**Status: counted 2026-09-23. 104 stats in the registry, 16 moved by a skill.**
This is authoring work, not engineering — a grant is a row, the editor writes
one, and the three re-tunes so far arrived by migration because the skills did.
What follows is the list of what nothing reaches, so the authoring has
somewhere to start.

**Attributes — nine of ten.** Only Memory is moved, by Eidetic Implants.
`intellect`, `perception`, `reflex`, `focus`, `social`, `meat`, `motor`,
`senses`, `muscle` have nothing.

**Vitals.**

- `hitpoints_max` — Neural Sheathing raises max SI and nothing raises max HP.
- `recovery_minutes` — how long somebody lies on the floor.

**Deck measures — five of ten.**

- `deck_quantabits` and `deck_power_supply`, the two totals no skill helps with
  while compute, storage, bandwidth and draw all have something.
- `deck_cooling` — it had Thermal Management's level-5 grant until 0.15.0 made
  that skill −2% thermal load at every level instead. Emptied by a re-tune
  rather than never filled, and worth refilling.
- `deck_module_thermal_warning` and `deck_module_thermal_limit` — a skill that
  makes hardware tolerate more heat, now that the deck screen draws both
  thresholds as ticks on a bar.

**One each, and each one load-bearing.**

- `icebreaker_damage` — nothing makes anybody better at the fight the game is
  built around. It is a program's number today; a skill behind it is the
  obvious first thing here.
- `siphon_yield` — `config/cyberspace.php` says in its own comment that no
  skill moves it.
- `heat_decay` — half a point a night, and the first skill to speed it up needs
  no code in `characters:cool`.

**The checks: thirty-two of thirty-four.** Every skill has a `check.<slug>`
stat. Two are raised — Advanced System Profiling raises `check.systemprofiling`
and Advanced Routing raises `check.routing` — and nothing raises a combat
check, an operating-system check, or the rest. That is the same hole from the
other side: **sixteen skills grant nothing at all** (the eight OS skills, the
six combat skills, Leadership and Network Topology).

**What NOT to invent.** The OS skills gate what may be attempted and gating is
not a modifier — see the migration that granted the first effects. The combat
skills have nothing true to grant until combat exists, and Leadership the same
until factions or crews do. So this entry is about the ~15 values above, and
the sixteen empty skills stay empty until the mechanics under them arrive.

**Deliberately not in the count:** the 34 `skill.*` level stats, which are read
rather than modified, and the 10 damage stats, where a resistance is written
against a tag set and no skill grants one yet.

**To count it again:** walk `StatRegistry::ids()`, build every skill's
modifiers through `Modifier::fromArray()`, and ask `appliesTo()` per stat —
targets can be tag sets, so comparing ids alone would undercount.

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

## The Street category is a placeholder, and it is meant to be the City

**Status: named wrong on purpose, because the thing it names does not exist.**
`App\Notifications\Category::Street` was added for heat's first consequence —
word going out that somebody is worth money — and it is the only category
whose `route()` is null, because there is nowhere to send a reader.

**It should be called City**, and the City is the screen the physical side of
the game hangs off (see *The physical side of the game* below): districts,
locations found by walking, clinics, markets, fixers, the people who put a
price on your head in the first place. A category named for a street is a
category named after one sentence in one message.

**What has to happen, and none of it is hard:**

- **Rename the case to `City`.** The enum's backed value is written into
  `notifications.category` on every row, so renaming the case means either a
  data migration over that column or a deliberate decision to leave old rows
  reading `street`. Do the migration — the whole point of that column is that a
  reader can group by it.
- **Give it a route** once there is a City screen, and the inbox stops
  rendering those rows as dead ends. `route()` returning null is deliberate and
  should go back to being impossible.
- **Then reconsider what belongs in it.** A bounty, a fixer's offer, an
  invitation, a debt coming due are all the same category today because none of
  them exists. When two of them do, this may want splitting.

**Do not flesh the City out from this entry.** It is a note about a name and a
null route; the design lives below, and that is a bigger conversation than a
category label.

## The physical side of the game
Some ideas to develop later: A city map showing locations. Most locations are hidden from the player and must be discovered by exploring. This map shouldn't be a grid like the cyberspace map, but rather just districts that you can travel to and "walk the streets" (an action) to discover new stuff (can also be helped by skills later). Locations can hold certain specific markets (selling certain items), places where you can get treatment (heal HP/max HP, heal neural damage/SI etc), get cyber implants or augmentations etc. There can also be "random events" and quest chains in the city. And, of course, fighting NPCs (and even PVP later once we figure that out). Maybe heists/robberies/missions (similar to runs on a node but more demanding).

Maybe players can also buy "houses" and "apartments" - figure out what this would be more than "cosmetics". Also possibly buying into existing businesses to generate passive income (investments). Maybe crafting of items?

## Player Owned Systems ("Data Fortresses")
Systems in cyberspace that a player can "build". Should take quite a lot of skill, and time and resources to build. Works more or less like a normal system, but players can stash cash here, run "programs" that do things like generate passive income etc, use it as a very good route in the chain etc. Prime target for other players to attack. Players should here have to "construct" their own IDS and ICE, which should take skills and the higher the skill and the longer they work on it the stronger their systems become. A data fortress built by a skilled runner should more or less require a team to take down.
