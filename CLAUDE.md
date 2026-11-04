# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

A cyberpunk browser game. PHP 8.4 + MySQL, Laravel 13, developed entirely in Docker.
Two systems are already binding: the Laravel app and the **Ash & Ember** UI kit. Read
both before writing code.

Subsystem rules (items, decks, City, combat, inbox, admin, deploying…) live in `docs/` — each
section below points at its file; read it before editing that subsystem.

Companion docs: `docs/MECHANICS.md` (anything producing a number), `docs/CYBERSPACE.md` (the grid,
systems, runs), `docs/CYBERSPACE_RULES.md` (the binding rules for all of it), `docs/CITY.md` (districts,
travel, explore, shops, clinics, encounters), `docs/COMBAT.md` (physical combat — items built, engine designed),
`docs/FATIGUE.md`, `docs/CRAFTING.md`, `docs/ENCOUNTERS_GRID.md`, `docs/WORLDBUILDING.md`, `docs/TODO.md`.

## Everything runs in Docker

No local PHP, Composer or MySQL. Never run `composer install`/`update` on the host —
`vendor/` must come from inside the container. The repo root is bind-mounted to
`/var/www/cyberpunk` in `web`, so host edits are live; only dependency/image changes need
a rebuild.

**DEVELOPMENT is Docker; PRODUCTION is not.** The live site is Apache + PHP-FPM on bare
metal at `/var/www/dedlok`, so several settings in `docker/` mean something different
there — the path, the user, where errors go. See **Deploying** (`docs/deploying.md`).

```bash
docker compose up -d --build   # first run / after Dockerfile or composer.json changes
docker compose up -d           # normal start
docker compose down            # stop (keeps db-data)
docker compose down -v         # stop and DESTROY the database
docker compose logs -f web     # PHP + Apache errors
```

| Service | Container | URL / port |
| --- | --- | --- |
| web (PHP 8.4 + Apache) | `cyberpunk` | http://localhost:8088 |
| MySQL 8.0 | `cyberpunk-db` | `localhost:3308` host, `db:3306` containers |
| Composer sidecar | `cyberpunk-composer` | runs `composer install`, then exits |
| scheduler | `cyberpunk-scheduler` | `cron -f`: the jobs in `routes/console.php` |
| reverb | `cyberpunk-reverb` | chat websockets, `localhost:8085` |
| cap, cap-valkey | | captcha server `localhost:3010`, and its store |

Ports are offset from baseplate's so both stacks can run side by side. No phpMyAdmin.
An exited `cyberpunk-composer` is normal, not a failure.

### Commands inside the container

**Always `-u www-data`.** `exec` runs as root by default and the bind mount means
anything Artisan or Composer creates lands on the host owned by root. The image aligns
`www-data` to uid/gid 1000; if your host uid differs, rebuild with
`--build-arg HOST_UID=$(id -u) --build-arg HOST_GID=$(id -g) web`.

```bash
docker compose exec -u www-data web php artisan <command>
docker compose exec -u www-data web composer require vendor/package
docker compose exec -u www-data web composer fix      # pint, applies
docker compose exec -u www-data web composer lint     # pint --test
docker compose exec -u www-data web composer phpstan
docker compose exec db mysql -ucyberpunk_user -pcyberpunk_password cyberpunk_db
```

### Tests

```bash
docker compose exec -u www-data web php artisan test
docker compose exec -u www-data web php artisan test --filter TestName
```

`phpunit.xml.dist` is committed (a local `phpunit.xml` beside it wins and stays
untracked). Tests autoload from `tests/` under `Tests\`.

**Tests are isolated from every real database.** `phpunit.xml.dist` forces
`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and pins `CAP_ENABLED=false`.
`tests/Feature/DatabaseSanityTest.php` asserts this — leave it in place.

## Layout and conventions

- `public/` is the DocumentRoot and the only web-reachable directory.
- `app/` is PSR-4 under `App\`. Standard Laravel layout. No `lang/`.
- `public/assets/` is the frontend: `css/`, `js/`, `fonts/`. No build step, no bundler.
- `docker/` holds `php/php.ini` and `apache/vhost.conf`, bind-mounted; editing needs a
  restart, not a rebuild.
- Style is **Laravel Pint**. Run `composer fix` before committing — it reformats
  `config/` array alignment.

## Frontend: the Ash & Ember UI kit → `docs/frontend.md`

**Read that file before writing any HTML or CSS.** The kit (`public/assets/css/ui-kit.css`, `public/assets/js/ui-kit.js`,
gallery `public/ui-kit.html`) is finished: compose what exists, never invent components, colours, spacing or type. In short:
hairlines carry structure; bone is the ink and colour is rationed; geometry is machined (radius 0-3px, notched `.cut`);
dark mode only; style through tokens, never literals; audit at 320px and 360px; player text goes through `UI.esc()`; public
assets load through `Asset::url()`, never bare `asset()`.

**Read that file before touching:** the three rules; Non-negotiables; Responsive rules; Security; the page frame (rail and
top bar); Images; Cache busting; Link unfurls; Fonts.

## Game mechanics → `docs/mechanics-rules.md`

**`docs/MECHANICS.md` is the reference for anything producing or altering a number.** Read it, and `docs/mechanics-rules.md`,
before adding an item, skill or effect. In short: every modifier is data, never PHP; one pure resolver does the arithmetic
in a fixed order; `App\Checks\CheckRoller` is the only die; `Character::modifiers()` is the whole list of sources; hit
points, SI and ICE are carried to two decimals through `Points::of()`, so never `(int)` one.

**Read that file before touching:** Rolling a check; Where a modifier comes from.

## The Laravel application → `docs/laravel-app.md`

Laravel 13, Fortify auth, **no Vite and no npm** (do not add a bundler). The game speaks English only: no `lang/`, no
`__()`; a player-visible string lives on its row or in `config/strings.php`, read through `App\Support\Text`. One character
per user. Captcha is `<x-captcha />`.

**Read that file before touching:** Laravel 13 specifics; Player-visible strings; Auth; Captcha.

## Feature flags → `docs/features.md`

**Read that file before touching:** Feature flags.

## Crafting, inventory, skills, market → `docs/items-and-economy.md`

**Read that file before touching:** Code Crafting; Inventory and imprints; Skills, and the training queue; The market's supply side; Buying.

## Cyberdecks, programs, locators → `docs/decks-and-programs.md`

**Read that file before touching:** Cyberdecks and modules; Programs; Locators.

## Character sheet, equipment, wallet, City, chat, tasks, supporters, fortresses → `docs/character-and-city.md`

**Read that file before touching:** The character sheet; Equipment; The wallet; The City; Chat and websockets; Standing, as a tab; Player counts on the front page; Tasks & Missions; Supporters; The Discord invitation; Data Fortresses.

## Encounters, drugs, consumables, implants, cyberspace rules → `docs/encounters-drugs-cyberspace.md`

**Read that file before touching:** Narrative encounters; Drugs; Consumables; Implants; Cyberspace, runs, hunters, missions.

## Fatigue, safehouses, physical combat → `docs/fatigue-and-combat.md`

**Read that file before touching:** Fatigue and Safehouses (phase 1 built — `docs/FATIGUE.md`); Physical combat (in progress — `docs/COMBAT.md`).

## Archetypes, inbox → `docs/archetypes-and-inbox.md`

**Read that file before touching:** Archetypes and what they hand out; The inbox.

## Admin tools → `docs/admin.md`

**Read that file before touching:** Admin tools.

## Events

Four events (`ChatLine`, `SkillInjected`, `SkillLevelReached`, `TrainingEntryCompleted`) and **one listener**: `App\Listeners\NotifyOfSkillLevel` turns
`SkillLevelReached` into an inbox message. Wiring is standard Laravel
(`Event::dispatch()`, auto-discovered from `app/Listeners`). `QUEUE_CONNECTION=sync` runs
listeners in-process, which is right while the only one does a single insert — switch to
`database` or `redis` before any listener does slow work. See docs/TODO.md.

## Templates

Blade. `layouts/app.blade.php` is the signed-in chrome, `layouts/guest.blade.php` the centred
panel used by auth. Views compose UI kit classes; they do not define their own CSS.

## Versioning and releases → `docs/releasing.md`

Git tags are the source of truth, `CHANGELOG.md` the technical record, `PATCHNOTES.md` the players' (written by the user).
Pre-1.0: a minor bump means a migration, everything else is a patch. `bin/release` does the branching, tag and merge and
never pushes.

**Read that file before touching:** Versioning; Patch notes; Cutting a release.

## Deploying → `docs/deploying.md`

**Read that file before touching:** Deploying; The FPM user is `dedlok`, and that is the whole of it; What differs from the container, setting by setting; The deploy itself: a daemon that pulls.

## Constraints imposed by php.ini

`docker/php/php.ini` is hardened and several settings break otherwise normal code:

- `open_basedir` is **not in php.ini**: in Docker it is `php_admin_value` in
  `docker/apache/vhost.conf` (web SAPI only; on the CLI it broke Composer and Artisan). On
  production it lives in the FPM pool — see *Deploying* (`docs/deploying.md`).
- `allow_url_fopen = Off` — use cURL, not `file_get_contents()`, for URLs.
- `disable_functions` blocks `exec`, `shell_exec`, `system`, `passthru`, `popen`, `symlink`,
  `parse_ini_file`, `show_source`, and binds the CLI too, so `storage:link` fails (see *Deploying* (`docs/deploying.md`)).
- `display_errors = Off`; errors go to `/dev/stderr` (`docker compose logs web`).
- Sessions: strict mode, cookie-only IDs, HttpOnly, SameSite=Lax. `cookie_secure` is off
  locally and must be on in production.

## Environment and the database → `docs/database.md`

**Read that file before writing a migration or touching `.env`.** In short: every new table and column gets a comment, in
its migration (`db:comments` checks); development uses the local `db` container (`DB_*`), a copy of production that
`bin/pull-db` replaces; `REMOTE_DB_*` is production and only that script reads it; **never run `migrate:fresh`,
`migrate:reset` or `db:wipe` against the remote**; quote any `.env` value containing `#` in single quotes.

**Read that file before touching:** the comment rule; Two databases; The database account.

## Dependencies

Laravel 13 with **Eloquent**. Use `Log::` and `config()`, not Monolog or phpdotenv directly.

`roave/security-advisories` is installed, so a `composer require` pulling a package with a
known CVE fails. Resolve by upgrading, never by removing the constraint.
