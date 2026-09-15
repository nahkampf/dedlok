# TODO

Deferred work, with enough context to pick up cold. Each item says what to change and
what will break if it is left alone.

---

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
