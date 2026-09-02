# TODO

Deferred work, with enough context to pick up cold. Each item says what to change and
what will break if it is left alone.

---

## Switch `QUEUE_CONNECTION` to `database`

**Status:** `sync` — listeners run in-process, on the request thread.

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

**Status:** both features commented out in `config/fortify.php`.

The columns (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`)
and the `passkeys` table are already migrated. What is missing is the Blade — challenge,
QR/recovery-code display, confirm-password, and passkey registration — built from the
Ash & Ember kit like the rest of `resources/views/auth/`.

Enabling the features before the views exist gives 500s on those routes rather than a
clear error, so keep them commented until the screens are written.

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
- **Add `i18n:check` to CI.** A missing translation key renders as the raw key on screen
  rather than throwing, so drift is invisible until a player sees `items.foo.name`.
