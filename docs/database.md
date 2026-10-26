# Environment and the database

_Split out of CLAUDE.md. Heading levels are as they were there._

## Environment and the database

### Every table and every column carries a comment

**A standing rule: EVERY NEW TABLE AND EVERY NEW COLUMN GETS A COMMENT, in the migration
that creates it.** The schema must explain itself in MySQL, not only in the migrations.

```php
Schema::create('widgets', function (Blueprint $table): void {
    $table->comment('What this table is for, in a sentence.');
    $table->string('slug', 100)->comment('Stable machine name. Permanent: rows reference it.');
});
```

Both are **no-ops on SQLite**, so the suite is unaffected.

**Two ways this fails silently:**

- **`->comment()` must come BEFORE `->constrained()`** — `constrained()` returns a
  `ForeignKeyDefinition`, so a comment chained after it never reaches the column.
- **`$table->timestamps()` returns void**, so those two cannot be commented inline.

Neither failure says anything, and both have already happened. **So check, do not remember:**

```bash
docker compose exec -u www-data web php artisan db:comments          # report
docker compose exec -u www-data web php artisan db:comments --fill   # fix the easy ones
```

`--fill` writes only `id`, `created_at` and `updated_at` — it refuses to invent anything
else, because a generated "The owner id." would look done. It exits non-zero while anything
is missing, so it can sit in CI.

- **Say what it means, not what it is.** Whether NULL is ordinary, whether a number is a base
  the resolver reads, and whether anything reads it yet are what is worth a sentence.
- **Do not edit `2026_09_04_190000_comment_every_column`** — it is history.
- **Nothing enforces this.** A missing comment fails no test, which is why it is written down.

### Two databases: a local copy, and production

**Development runs against the local `db` container, which holds a copy of production.**
Until 2026-09-28 the laptop and the live site shared one remote MySQL, so every migration
reached the live game the moment it ran here, and the dev scheduler ran every job a second
time against production, beside the server's own crontab. Bounties rolled twice, siphons
paid twice and the market restocked twice. A dev `training:deposit` holding a transaction
open across the internet also stalled a migration's `ALTER TABLE characters`, and that
stalled every production query queued behind it.

- **`DB_*` in `.env` is the container.** `REMOTE_DB_*` is production, and **only
  `bin/pull-db` reads it** — the app never connects there from a laptop.
- **`bin/pull-db` replaces the local database with a fresh dump of production.** Content
  is authored in the live admin, so this is how the laptop learns what items, skills and
  entities exist. It REPLACES: anything made locally since the last pull is gone. The dump
  is `--single-transaction` and takes no locks production would feel. It includes player
  accounts and password hashes, which stay on this machine.
- **When a question is about what content EXISTS, pull first** — a stale copy is exactly
  how a seeder gets written for an item that is not there.
- **The container is MySQL 8.0, pinned to match production (8.0.46).** 8.4 accepts syntax
  8.0 refuses, and a migration that passes here must pass there.
- **A migration now reaches production by DEPLOY**, through the worker's
  `migrate --force` after the pull — not from a laptop. Test it here first; that is the
  whole reason for having two databases.

`.env` (gitignored) holds the credentials; `.env.sample` is the committed template and must
never contain real values.

**Quote any value in `.env` that contains a `#`, and use SINGLE quotes.** phpdotenv reads
an unquoted `#` as the start of an inline comment and truncates the value there, so a
password carrying one arrives at MySQL as its first few characters and the server answers
`1045 Access denied` for credentials that are, on the face of the file, correct. Double
quotes are not the fix — a double-quoted value is interpolated, so a `$` in it expands.
`DB_PASSWORD='...'`. This cost an afternoon on 2026-09-21, with the resolved password three
characters of sixteen. **Nothing between the file and the error message says so**, so when
a connection is refused, compare what the file holds against what the framework loaded
before suspecting the server:

```bash
docker compose exec -u www-data web php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo strlen((string) config("database.connections.mysql.password")), "\n";'
```

### The database account

`dedlok@%` has `USAGE` globally and `ALL PRIVILEGES` on `cyberpunk`.\* only. Open items
(host mask still `%`, connection unencrypted) are tracked in `TODO.md`.

**Never run `migrate:fresh`, `migrate:reset` or `db:wipe` against the remote.** Locally they
are harmless now — the app's own connection IS the container, and `bin/pull-db` puts the
data back.
