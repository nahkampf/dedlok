# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A cyberpunk browser-based game, PHP + MySQL, developed entirely inside Docker. The
project was scaffolded from [nahkampf/baseplate](https://github.com/nahkampf/baseplate)
(a LAMP-in-Docker starter). As of now it is **infrastructure only** — `src/` is empty
and the application architecture has not been designed yet. Expect to be the one
establishing conventions rather than following existing ones.

## Everything runs in Docker

There is no local PHP, Composer, or MySQL in play. Never run `composer install`/`update`
on the host — `vendor/` must be populated from inside the container so it lines up with
the mounted volumes and the container's PHP version. The repo root is bind-mounted to
`/var/www/cyberpunk` in the `web` container, so host edits are live immediately; only
dependency and image changes need a rebuild.

```bash
docker compose up -d --build     # first run / after Dockerfile or composer.json changes
docker compose up -d             # normal start
docker compose down              # stop (keeps the db-data volume)
docker compose down -v           # stop and DESTROY the database
docker compose logs -f web       # PHP errors and Apache logs land here
```

| Service | Container | URL / port |
| --- | --- | --- |
| web (PHP 8.4 + Apache) | `cyberpunk` | http://localhost:8088 |
| MySQL 8.4 | `cyberpunk-db` | `localhost:3308` from host, `db:3306` from containers |
| Composer sidecar | `cyberpunk-composer` | runs `composer install`, then exits |

Ports are deliberately offset from baseplate's (8086/3307) so both stacks can run side by
side. Unlike baseplate there is no phpMyAdmin container — use the `mysql` client in the
`db` container (below) or a host-side GUI pointed at `localhost:3308`.

### Commands inside the container

```bash
docker compose exec web composer require vendor/package
docker compose exec web composer install
docker compose exec web composer phpstan     # phpstan analyse src
docker compose exec web composer phpcs       # phpcs --standard=PSR12 src
docker compose exec web php <script>
docker compose exec db mysql -ucyberpunk_user -pcyberpunk_password cyberpunk_db
```

The `cyberpunk-composer` service exists to run `composer install` once after a build and
then exit — a stopped/exited `cyberpunk-composer` is the normal state, not a failure.

While `src/` holds no `.php` files, `composer phpstan` exits 1 with "No files found to
analyse". That is the empty codebase, not a misconfiguration; it resolves itself as soon
as the first class lands. `composer phpcs` is quiet in the same situation.

### Tests

PHPUnit 13 is installed and wired up, but **no test cases have been written yet** —
`tests/` is empty, so a run reports "No tests executed!" and still exits 0.

```bash
docker compose exec web composer test                      # whole suite
docker compose exec web ./vendor/bin/phpunit --filter TestName          # one test method
docker compose exec web ./vendor/bin/phpunit tests/Path/To/FooTest.php  # one file
```

Config lives in `phpunit.xml.dist` (committed; drop a `phpunit.xml` beside it for local
overrides — it wins and should stay untracked). Tests autoload from `tests/` under
`Nahkampf\Cyberpunk\Tests\` via `autoload-dev`, so `tests/Foo/BarTest.php` declares
`namespace Nahkampf\Cyberpunk\Tests\Foo;`. `failOnRisky` and `failOnWarning` are on, so
a test that asserts nothing is a failure rather than a quiet pass. `.phpunit.cache/` is
gitignored.

## Layout and conventions

- `public/` is the Apache DocumentRoot and the **only** web-reachable directory. Nothing
  outside it can be requested directly. `AllowOverride All` is on, so `.htaccess`
  rewrites work if you move to a front controller.
- `src/` is the PHP codebase, PSR-4 autoloaded under `Nahkampf\Cyberpunk\`. A new file
  at `src/Foo/Bar.php` must declare `namespace Nahkampf\Cyberpunk\Foo;`.
- `docker/` holds `php/php.ini` and `apache/vhost.conf`, both bind-mounted over the
  container's config. Editing them needs only a container restart, not a rebuild.
- Code style is PSR-12, enforced by PHPCS. PHPStan runs against `src` with no configured
  level, i.e. level 0 — raising it via `phpstan.neon` is a reasonable early move.

## Constraints imposed by php.ini

`docker/php/php.ini` is hardened, and several settings will actively break otherwise
normal code. Read it before debugging anything that fails mysteriously:

- `open_basedir` restricts filesystem access to `/var/www/cyberpunk`, `/tmp`, `/usr/bin`,
  `/root`. Paths outside those are unreachable.
- `allow_url_fopen = Off` — `file_get_contents()` cannot fetch URLs. Use cURL for HTTP.
- `disable_functions` blocks `exec`, `shell_exec`, `system`, `passthru`, `popen`,
  `symlink`, `parse_ini_file`, `show_source`.
- `register_argc_argv = Off` — CLI scripts get no `$argv`. Flip this if you add real
  console tooling.
- `display_errors = Off`; errors go to `/dev/stderr`, visible via `docker compose logs web`.
- Sessions are already configured for strict mode, cookie-only IDs, HttpOnly and
  SameSite=Lax. `session.cookie_secure` is commented out for local HTTP — it must be
  enabled before any HTTPS deployment.

## Environment and the database

**The application uses a remote MySQL server, not the local `db` container.** `.env`
(gitignored) holds the real credentials and points `DB_HOST` at an external IP on port
`3306`; `.env.sample` is the committed template and must never contain real values. Copy
it to `.env` before first run.

The remote server is MySQL 8.0.46 — older than the 8.4 image in `docker-compose.yml`, so
target 8.0 syntax and features when writing schema, not 8.4.

The `db` service in `docker-compose.yml` is therefore **not what the app connects to**. It
is left running as a scratch/offline instance, and `docker compose exec db mysql ...`
reaches that local one, *not* production data. To reach the real database, go through PHP
in the `web` container. Because this local instance is unused by the app, deleting the
service is a reasonable cleanup if it starts causing confusion.

The connection is currently **unencrypted over the public internet**, and the account is
`lod@%` (connectable from any host). Before this goes anywhere real, add TLS to the DBAL
connection and restrict the account's host mask.

## Dependencies

Doctrine **DBAL** (not the ORM) for database access, Monolog for logging,
`vlucas/phpdotenv` for env loading. `roave/security-advisories` is installed, so a
`composer require` that pulls in a package version with a known CVE will fail — that is
intended, resolve it by upgrading rather than by removing the constraint.
