# Deploying

_Split out of CLAUDE.md. Heading levels are as they were there._

## Deploying

**The live site is not Docker.** `dedlok.online` is Apache + **PHP-FPM 8.4** on bare
metal, with the checkout at **`/var/www/dedlok`** and the DocumentRoot at
`/var/www/dedlok/public`. Development is entirely in containers and production is not, so
every container-ism in `docker/` is a thing that means something different there — which
has cost one outage already.

### The FPM user is `dedlok`, and that is the whole of it

**PHP runs as `dedlok`. Apache serves static files as `www-data`.** They are different
users and only the first one matters for anything the application writes.

```bash
sudo -u dedlok php artisan migrate --force      # every artisan command
sudo -u dedlok composer install --no-dev        # every composer command
```

**Anything run as root or as `www-data` leaves files the application cannot write**, which
is the same rule `-u www-data` enforces in the container, with a different name. After a
deploy that got it wrong:

```bash
sudo chown -R dedlok:dedlok /var/www/dedlok
sudo find /var/www/dedlok/storage /var/www/dedlok/bootstrap/cache -type d -exec chmod 775 {} +
sudo find /var/www/dedlok/storage /var/www/dedlok/bootstrap/cache -type f -exec chmod 664 {} +
```

**THE SYMPTOM IS AN EMPTY 500 WITH NOTHING IN ANY LOG**, and it is a double fault: Blade
cannot write a compiled view, so a notice becomes an `ErrorException`, and Monolog cannot
write `storage/logs/laravel.log` either, so the exception that says so is itself lost. The
page returns zero bytes.

**The diagnostic that localises it in one minute**: request a static asset, then
`public/index.php`. Static 200 and PHP 500 means PHP, Apache, TLS and `open_basedir` are
all fine and the application's WRITABLE paths are not. A `phpinfo()` in the docroot
answering 200 says the same thing louder. (Delete it afterwards — it publishes the whole
configuration.)

### What differs from the container, setting by setting

- **`open_basedir` lives in the FPM pool, not the vhost.** `php_admin_value` is a *mod_php*
  directive; under FPM, Apache refuses to start on it. The pool spelling is
  `php_admin_value[open_basedir] = /var/www/dedlok:/tmp`. It is set correctly there, and it
  is **not** a suspect when something breaks — `docker/apache/vhost.conf` names
  `/var/www/cyberpunk` and is irrelevant on that host.
- **`error_log = /dev/stderr` is a Docker-ism.** Off Docker it means PHP errors go to the
  worker's stderr, which FPM discards unless told otherwise. Set both, or the box is blind:

  ```ini
  ; /etc/php/8.4/fpm/pool.d/www.conf
  catch_workers_output = yes
  php_admin_flag[log_errors] = on
  php_admin_value[error_log] = /var/log/php/dedlok-error.log
  ```

  Without them, the only trace is Apache's `proxy_fcgi` `AH01071` lines, which are
  **truncated mid-stack-trace** and easy to mistake for nothing.
- **`php artisan storage:link` cannot work** — `symlink` is in `disable_functions` and that
  is `PHP_INI_SYSTEM`, so it binds the CLI too. Make it by hand, once:
  `sudo -u dedlok ln -s /var/www/dedlok/storage/app/public /var/www/dedlok/public/storage`.
- **`session.cookie_secure` must be on.** `docker/php/php.ini` leaves it commented out for
  local HTTP; the live site is HTTPS.
- **`docker/cron/cyberpunk` is a container cron and does nothing there.** The schedule runs
  from `dedlok`'s crontab, and without it there are no training deposits, no lockdown
  expiry, no nightly rolls and no `scans:stale`:

  ```
  * * * * * cd /var/www/dedlok && php artisan schedule:run >> /dev/null 2>&1
  ```

### The deploy itself: a daemon that pulls

**NOBODY DEPLOYS. `git_worker.php` does**, running daemonised on the server as
`dedlok`: every 60 seconds it fetches `origin/main`, compares `HEAD` against it, and
pulls when they differ. It is not web-reachable — `public/` is the DocumentRoot and this
sits above it.

**IT PULLS OVER ITSELF.** The file lives in the repo root, which on that host IS
`/var/www/dedlok`, so a deploy overwrites the script doing the deploying. PHP reads a
script once at startup and this is a `while(true)` loop, so **a change to it lands on
disk on the next push and takes effect on the next RESTART** — two separate events, and
forgetting the second is how a fixed worker goes on behaving like the broken one. The
consolation is the same fact from the other side: a bad edit cannot take deploys down by
itself, because the running process carries on with the code it started with.

**So a deploy is a push to `main`, and it happens within a minute.** Not a tag: the tag
is how a release is RECORDED, and it has nothing to do with what the server picks up.
Pushing a commit to `main` without tagging it deploys it just the same.

**WHAT IT RUNS AFTER A PULL, and only when it has to.** The diff between the old and new
`HEAD` decides: `composer.lock` moving runs `composer install`, anything under
`database/migrations/` runs `migrate --force`, and the caches are cleared on every pull
because compiled Blade from the previous commit is stale on all of them. Dependencies
first (a migration may use a class that arrived with them), then the caches so Artisan
reads the config that was just pulled, then the schema.

**A failed step is logged and the loop continues.** A deploy daemon that dies on a bad
release stops deploying silently, which is worse than a loud failure nobody is watching
either way.

Three things it still cannot do, all of them because `dedlok` has no sudo:

- **Restart itself**, per above.
- **Restart PHP-FPM or Apache.** Nothing here needs it today — no opcache concerns have
  surfaced — but a change that did would need a hand.
- **Recover from wrong ownership.** `git pull` writes files as whoever runs it, which is
  why the daemon runs as `dedlok`: PHP-FPM is that user, and a worker started as root
  would leave root-owned files in the tree on **every** deploy — the outage this
  document's first section is about.

**THIS IS HOW A MIGRATION REACHES PRODUCTION**, since 2026-09-28. Development used to
share the live database, so a migration had already run from a laptop before the code
arrived. Now development has its own copy (see *Two databases* in `docs/database.md`), and the worker's
`migrate --force` after the pull is the only way a schema change reaches the live game.

The one GitHub Actions workflow is `.github/workflows/patchnotes.yml`: a `v*` tag push
extracts that version's section of `PATCHNOTES.md` and posts it to Discord
(`DISCORD_WEBHOOK_URL` repo secret). It deploys nothing. **It must live in
`.github/workflows/`** — GitHub ignores it anywhere else — and the trigger needs the `v`
because the tags carry one. `workflow_dispatch` with a version re-sends a release by hand.
The old `manual.yml` that POSTed to `deploy.php` was deleted 2026-09-29; the polling
worker replaced it.

**`APP_DEBUG` stays `false`.** Turning it on is the fastest way to read a production
fault and it prints the environment to the public internet; turn it back the moment the
trace has been read.

