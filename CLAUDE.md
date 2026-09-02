# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A cyberpunk browser-based game, PHP + MySQL, developed entirely inside Docker. The
project was scaffolded from [nahkampf/baseplate](https://github.com/nahkampf/baseplate)
(a LAMP-in-Docker starter), then rebuilt on **Laravel 13** (`laravel/framework` ^13.17,
PHP ^8.3). Two systems are already established and binding: the Laravel application
below, and the Ash & Ember UI kit — read both sections before writing code.

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

Always `-u www-data`. See *Running Artisan and Composer* for why.

```bash
docker compose exec -u www-data web composer require vendor/package
docker compose exec -u www-data web composer install
docker compose exec -u www-data web php artisan <command>
docker compose exec -u www-data web composer fix        # pint, applies fixes
docker compose exec -u www-data web composer lint       # pint --test
docker compose exec -u www-data web composer phpstan    # phpstan analyse app
docker compose exec db mysql -ucyberpunk_user -pcyberpunk_password cyberpunk_db
```

The `cyberpunk-composer` service exists to run `composer install` once after a build and
then exit — a stopped/exited `cyberpunk-composer` is the normal state, not a failure. It
runs as root, so prefer the `exec -u www-data` form above for day-to-day work.

### Tests

Laravel's PHPUnit setup. Only the two stock example tests exist so far.

```bash
docker compose exec -u www-data web php artisan test                    # whole suite
docker compose exec -u www-data web php artisan test --filter TestName  # one test
docker compose exec -u www-data web php artisan test tests/Feature/FooTest.php
```

Config lives in `phpunit.xml.dist` (committed; drop a `phpunit.xml` beside it for local
overrides — it wins and should stay untracked). Tests autoload from `tests/` under
`Tests\` via `autoload-dev`, so `tests/Feature/FooTest.php` declares
`namespace Tests\Feature;`.

**Tests are isolated from the remote database.** `phpunit.xml.dist` overrides
`DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, so `RefreshDatabase` builds a throwaway
in-memory schema and never touches the live host. `tests/Feature/DatabaseSanityTest.php`
asserts this and will fail if anyone repoints the test connection — leave it in place.

## Layout and conventions

- `public/` is the Apache DocumentRoot and the **only** web-reachable directory. Nothing
  outside it can be requested directly. `AllowOverride All` is on, so `.htaccess`
  rewrites work if you move to a front controller.
- `app/` is the PHP codebase, PSR-4 autoloaded under `App\` (the old `src/` +
  `Nahkampf\Cyberpunk\` namespace is gone). Standard Laravel layout: `app/`,
  `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `storage/`. There is no
  `lang/` — see *Player-visible strings*.
- `public/assets/` holds the frontend: `css/`, `js/`, `fonts/`. Served directly, no build
  step and no bundler — see *Frontend: the Ash & Ember UI kit*.
- `docker/` holds `php/php.ini` and `apache/vhost.conf`, both bind-mounted over the
  container's config. Editing them needs only a container restart, not a rebuild.
- Code style is **Laravel Pint** (`composer fix` to apply, `composer lint` to check).
  PHPStan and PHPCS are still installed and now point at `app/`. Run Pint before
  committing — it reformats `config/` array alignment, which is easy to get wrong by hand.

## Frontend: the Ash & Ember UI kit

**Read this before writing any HTML or CSS.** The frontend has a finished design system.
Do not invent components, colours, spacing or type — compose what is already there.

| File | Role |
| --- | --- |
| `public/assets/css/ui-kit.css` | The whole system: tokens, then components. No build step. |
| `public/assets/js/ui-kit.js` | Behaviours, data-attribute driven. Exposes `window.UI`. |
| `public/assets/fonts/` | Overpass and IBM Plex, self-hosted (both SIL OFL). |
| `public/ui-kit.html` | Live gallery of every component with copyable markup. |

The gallery at http://localhost:8088/ui-kit.html is the reference. **Open it (or read its
markup) before building a screen** — if a component already exists, use it. When you add a
component to the kit, add a demo to the gallery in the same `<template class="demo-src">`
pattern, so the docs cannot drift from the code.

### The three rules the system rests on

1. **Hairlines carry structure.** Borders separate things — not shadows, not fills.
   Elevation is a step in `--bg-0`→`--bg-4`, never a glow.
2. **Bone is the ink; colour is rationed.** Primary buttons are `--ink-0` (white). The
   ember accent means *live, critical or destructive* only — aim for ~2% of lit pixels.
   A screen with three ember elements on it has two too many. `.btn--go` (green, `--ok`)
   is the single exception: it means *begin*, not *succeeded*, and is likewise limited to
   one per screen — never beside another filled button competing for the same click.
3. **Geometry is machined.** Radius stays 0–3px. Character comes from the notched corner
   (`.cut`), never from rounding. The corner *tick* (`.ticked`, and the same device inside
   `.card--select` and `.slot--filled`) is the other half of that vocabulary: it marks a
   box as one you have, and marks nothing else — no border colour, no fill.

Dark mode only. There is no light theme and none is planned — do not add one, and do not
add `prefers-color-scheme` blocks.

### Non-negotiables when extending it

- **Style through tokens, never literals.** No hex codes, no `px` font sizes, no ad-hoc
  spacing in component CSS or in `style=""`. Use `--bg-*`, `--ink-*`, `--s-1`…`--s-8`,
  `--fs-*`.
- **Use the semantic aliases, not the palette.** `--ok --warn --crit --info` (and their
  `-a` tinted companions) in anything that carries meaning. The raw names (`--jade`,
  `--brass`, `--ember`, `--steel`) are only for decoration: rarity tiers, brand mark,
  focus rings. Re-skinning depends on this holding.
- **Drive state with custom properties, not classes.** PHP renders values straight into
  the style attribute: `<div class="meter" style="--v:.72">`. `--v` is a 0–1 ratio applied
  as a transform, so it animates without layout.
- **`.cut` is two clipped pseudo-elements**, because `clip-path` eats a real border. A
  child with its own background squares the corners off — that needs `.cut--clip`, which
  is opt-in because it also clips focus outlines.

### Responsive rules — these were all shipped broken once

The user tests on an Android phone. Audit every layout at **320px and 360px** before
calling it done. There is no browser in this environment, so this is done by reading.

- **Never a bare `1fr` grid track.** `1fr` is `minmax(auto, 1fr)`; one over-wide child
  stretches the track past the viewport and shoves the whole page sideways. Always
  `minmax(0, 1fr)`.
- **Cap every `minmax()` floor**: `minmax(min(260px, 100%), 1fr)`. Usable width inside a
  panel on a 320px phone is about 238px.
- **Fixed widths get `min(Npx, 100%)`.** Same for `min-width`.
- **Any flex row of chips, buttons or metadata needs `flex-wrap: wrap`.**
- **Inline `style=""` grid tracks beat media queries.** Add a modifier class to the kit
  instead (see `.vital--tight`).
- **Watch paint order.** Non-positioned siblings paint backgrounds first, then all inline
  text — so a sibling's text crosses over a chip's background regardless of DOM order.
  Anything meant to sit *above* an overflowing sibling needs its own stacking context
  (see `.ticker__tag`).

### Security

`UI.log()` inserts its text as **HTML**, so log lines can carry `<em>`. Anything
player-supplied must go through `UI.esc()` first. The same applies to any markup you build
by string concatenation.

### Fonts

**Every face is self-hosted and declared in `ui-kit.css`.** No font request leaves the
origin, so the layouts carry no `<link>` and nothing to preconnect to — do not add one
back. Overpass is one variable file per subset; IBM Plex is static instances, because
that is how Google Fonts serves it and only three weights per family are in use. Each
face is split into `latin` and `latin-ext` by `unicode-range`, so a page with no accented
characters never fetches the second file.

Both families are SIL OFL and the licences sit beside the files. **Adding a weight means
adding a file and an `@font-face` block** — there is no build step to generate either, so
keep the set as small as the design actually needs. Nothing is required: every stack falls
back to system faces.

## Game mechanics

**`MECHANICS.md` is the reference for anything that produces or alters a number** —
attributes, hit points, damage, skill checks, and everything that modifies them
(skills, implants, drugs, injuries, archetype perks, gear).

Read it before adding an item, a skill or an effect. The short version:

- Every modifier is data, never PHP — a `config/` entry, or a row where the thing
  already has one. If a new piece of content needs new PHP, the design has leaked; fix
  the op set instead.
- One pure resolver does all the arithmetic, with a fixed layer order:
  `add` → `percentage_of_base` → `multiplier` → `override` → clamp → round.
- `percentage_of_base` applies to **base + flat**, and all percentages are
  **summed then applied once**, never compounded.
- **Clamp and round once, at the end.** Clamping mid-pipeline breaks the
  commutativity the whole design depends on.
- Derived stats are never stored; resources are stored and clamp down (never
  scale) when their maximum drops.

Most of that system is **specified but not built** — MECHANICS.md has a status
table saying exactly which parts exist. Do not describe it as working.

## The Laravel application

Laravel 13. `laravel/fortify` for auth, `laravel/tinker`, **no Vite and no npm** — the
UI kit is deliberately build-free, so `package.json` and `vite.config.js` were not
installed. Do not add a bundler without a reason; `asset()` serves `public/assets/`
directly.

### Running Artisan and Composer

```bash
docker compose exec -u www-data web php artisan <command>
docker compose exec -u www-data web composer <command>
docker compose exec -u www-data web ./vendor/bin/pint
```

**Always pass `-u www-data`.** `docker compose exec` runs as root by default, and because
the repo root is bind-mounted, anything Artisan or Composer creates then lands on the host
owned by root and can neither be edited nor deleted without `sudo`. The image aligns
`www-data` to uid/gid 1000 to match the host user; if your host uid differs, rebuild with
`docker compose build --build-arg HOST_UID=$(id -u) --build-arg HOST_GID=$(id -g) web`.

### Laravel 13 specifics worth knowing

- Models declare `#[Fillable([...])]` and `#[Hidden([...])]` as **PHP attributes**, not
  `protected $fillable` properties. Editing the old property does nothing.
- There is no `app/Http/Kernel.php`. Middleware is registered in `bootstrap/app.php`
  via `->withMiddleware()`, and providers in `bootstrap/providers.php`.
- `laravel/agent-detector` (a framework dependency) probes paths like `/opt/.devin` at
  autoload-dump time. This is why `open_basedir` had to move off the CLI — see
  *Constraints imposed by php.ini*.

### Player-visible strings

**The game speaks English and only English.** Translation was removed: there is no
`lang/` directory, no locale to resolve, negotiate or switch, and `__()` and
`trans_choice()` are not used anywhere in this codebase. Do not reintroduce them.

Every string a player reads lives in exactly one of two places, and which one is not a
matter of taste:

- **On the row, if the thing has a row.** An item's `name` and `flavor` are columns; a
  skill's `title` always was. A definition that is half in the database and half in a
  file is two things to keep in step, and the file half is the one that gets forgotten.
- **In `config/strings.php` otherwise.** UI chrome, auth screens, refusal messages,
  captcha labels, the tier vocabulary shared by every item. Read it through
  `App\Support\Text`, which is a config lookup plus `:placeholder` substitution and
  nothing else.

Details worth knowing:

- **Keys still name the role, not the text** — `ui.action.jack_in`, not
  `ui.action.jack_in_now`. Rewording copy stays a one-line change.
- **Counted strings** are `{zero, one, many}` groups read by `Text::plural()`. English
  has two forms; that is now the only case there is.
- **`Text::get()` returns the key** when it is missing, the way the translator did: a
  wrong label on screen beats a 500, and is just as visible.
- **A skillbook has no name of its own.** There is one book per skill, so its name
  composes from the skill it teaches through `items.skillbook.name`.
- **Archetypes carry their own strings on their rows**, in the `archetypes` table, beside
  their ids and their perks. Perk labels live on `archetype_perks`, except where a perk
  borrows an attribute's own name — those have a null label and read
  `character.names.<key>`, so renaming "Reflex" is still one edit.
- **Framework strings that reach a player** are still swapped in by the exception
  renderers in `bootstrap/app.php`, now from `strings.errors.*`. Laravel throws a raw
  English literal for rate limiting (`'Too Many Attempts.'` in
  `Illuminate\Routing\Middleware\ThrottleRequests`) and vendor code cannot be edited —
  `composer install` reverts it. Re-render the exception instead, and **carry the original
  headers through**: `Retry-After` and `X-RateLimit-*` are the machine-readable half of a
  429.
- **`config/app.php` still sets `locale` and `fallback_locale`, and they stay.** The
  framework's own validation and auth messages come from vendor lang files keyed by
  them — that is the one translator that is still in play, and it is not ours.

### Auth

Fortify, with **all views written by us** in `resources/views/auth/` using the UI kit —
that is why no starter kit was installed. Two-factor and passkeys are **disabled** in
`config/fortify.php` because their screens do not exist yet; the columns and tables are
already migrated, so enabling them is a matter of writing the Blade and uncommenting.

### Captcha

**Cap** ([trycap.dev](https://trycap.dev)), self-hosted in the `cap` service. No Google,
no telemetry, no third-party script in the render path. It guards login, registration and
the password-reset request.

- **Adding it to a form is one tag: `<x-captcha />`.** `ui-kit.js` upgrades every
  `.captcha` element into Cap's `<cap-widget>`, reading the endpoint and the labels from
  the two globals `<x-captcha-runtime />` emits, so no form repeats them and no page needs
  a solve handler — inside a `<form>` the widget injects its own hidden `cap-token` input.
- **Protecting a route is one line in `config/cap.php`.** The `protect` list holds route
  *names*, and `App\Http\Middleware\EnsureCaptchaIsSolved` reads it. It is middleware
  rather than a validation rule because two of the three routes are Fortify's, in
  `vendor/`, with no action of ours to hook.
- **The widget is a challenge, not a gate.** Nothing is protected until `App\Captcha\Cap`
  has POSTed the token to `/siteverify`. A client that skips the widget simply posts
  without a token.
- **Every failure mode fails closed** — unreachable server, error status, forged token,
  and *enabled but missing credentials*. Quietly waving everyone through while the form
  still shows a "verified" checkbox is the one outcome not allowed.
- **Off by default** (`CAP_ENABLED=false`), so a fresh clone boots with no Cap server and
  the auth screens still work. The component renders nothing while it is off.
- The widget lives in an **open shadow root**, so kit classes cannot reach inside it. The
  `--cap-*` custom properties are the whole styling surface; they are mapped onto tokens
  in `ui-kit.css`. Cap's default 14px radius is overridden — the kit's radius is 0–3px.
- **The widget and its WASM are vendored**, in `public/assets/js/cap-widget.js` and
  `public/assets/wasm/`. Cap Standalone does not serve the widget itself (its `/assets`
  is the dashboard's), and the widget fetches its WASM from jsDelivr unless
  `window.CAP_CUSTOM_WASM_URL` says otherwise — so without both, self-hosting Cap would
  still have a CDN seeing every solve. The file header says how to update it.
  One third-party call remains: a `pako` fallback the widget loads only on browsers
  without `DecompressionStream`. Cap exposes no override for that URL.
- **Two endpoints, not one.** `CAP_ENDPOINT` is what the browser uses; `CAP_VERIFY_ENDPOINT`
  is what the `web` container uses, and defaults to the first. With the bundled service
  they differ — the browser has `localhost:3010`, the container has `cap:3000`, and
  neither host resolves for the other. `CAP_SECRET` is the site key's secret, never
  `CAP_ADMIN_KEY`.
- **`phpunit.xml.dist` pins `CAP_ENABLED=false`** and blanks the endpoints, so a working
  Cap in a developer's `.env` cannot leak into the suite. Tests that want it on set the
  config themselves.

### Inventory and skillbooks

**Two inventories, and one gate in front of every skill in the game.**

- `items` holds definitions, `inventory_items` holds what a character carries — one row
  per (character, item) with a `quantity`, not one row per object. Item definitions are in
  the **database, not config**: the second documented exception to the config-plus-lang
  rule, taken for the same reasons skills were. `config/items.php` does not exist.
- **`container` is a property of the item, never a player choice.** `physical` and `data`
  are the two inventories; a row cannot disagree with its definition because nothing
  stores the container on the row. `Character::inventoryIn()` filters through the item.
- **A `character_skills` row means the skill has been injected**, and
  `App\Inventory\SkillInjector` is the only thing that creates one. Injecting consumes one
  copy of the skillbook and writes the row at 0 points, level 0, in one transaction.
- **`TrainingDeposit` does not create that row** — it refuses and logs. Owning and
  spending the book is therefore a hard gate: nothing can be trained without it, including
  through a queue row written directly. `skills:validate` and the queue validator both
  report an uninjected skill.
- **Level 0 is a real state** (injected, untrained), so the gate is a row-exists check —
  `Character::hasInjected()` — and never a level check. `hasSkillAtLevel()` answers a
  different question.
- Injecting a skill already known is **refused**, and the player keeps the book.
- **Skillbooks need no lang entry.** There is one book per skill, so `ItemSeeder` generates
  them from the skills table and the name composes from the skill's own title via
  `items.skillbook.name`. Adding a skill adds its book, in every locale, for free.
- **Items can hold items.** An item with `capacity > 0` and an `accepts` kind is a
  container — the memory shard is a `physical` object that `accepts` `data`, which is the
  case `container` alone could not express. `container` still says what *kind* of thing an
  item is; `inventory_items.parent_id` says where a row actually sits.
- **`parent_id` null means loose.** Every listing that means "the player's inventory"
  needs `InventoryItem::scopeLoose()`, or a book on a card shows up twice — once on the
  card and once on a deck it was never on. `Character::inventoryIn()` already does.
- **Depth is capped at one.** A container cannot go inside a container, so the structure
  cannot contain a cycle and nothing has to walk a tree defensively. `App\Inventory\ItemPlacer`
  is the only thing that moves rows between containers, and it enforces every rule:
  ownership, kind, capacity, and the depth cap.
- **Destroying a container destroys its contents** — `parent_id` cascades on delete. That
  is the fiction (crush the shard, lose the data) and it is also what stops orphan rows.
- **Two copies of an item are no longer interchangeable.** The old unique key on
  (`character_id`, `item_id`) said they were, which a card with a book on it disproves.
  It is gone, replaced by a nullable unique `stack_key`: null for unstackable items — both
  MySQL and SQLite treat nulls in a unique index as distinct, so each object gets its own
  row — and `character:item:parent` for stackable ones, so two piles of the same thing in
  the same place still collide. **It is derived on every save by the model, never set by
  hand.** A generated column would be better and is not available: `stackable` lives on
  `items`, and a generated column can only read its own row.
- `size` is enforced, but only as what a container's contents cost against its capacity.
  `weight` is still stored and unenforced, and neither inventory has an overall capacity.

### The market's supply side

**The market restocks itself; nothing sells anything yet.** There is no price paid, no
transaction and no money — what exists is the half a storefront will be built on top of.

- **`rarity` is not `tier`.** Tier is what an item IS — Tier One or Tier Two, narrowed
  from five named steps that nothing mechanical read — it colours the card through the
  kit's `.t1`/`.t2`, and it carries no mechanics. Rarity — `common`, `uncommon`, `rare`,
  `ultra_rare` — is how often the market has one, and it is the only thing that decides
  whether a restock pass delivers. They are free to disagree.
- **An item is stocked only with `on_market` AND `seed_quantity > 0`.** The default shelf
  size is 0, so nothing reaches the market by being forgotten. `Item::restockable()` is
  the scope that means both.
- **`market_stock` is one row per item, and a missing row is a shelf of zero**, not an
  error: rows are created by the first delivery that lands. Anything reading stock has to
  treat absent and empty alike — `Item::stockOnHand()` does.
- **`market_purchases` is demand, not just a receipt.** Every purchase, one immutable row,
  no `timestamps()` — `purchased_at` is the moment. `App\Market\MarketSeeder` sums a
  seven-day window of it per item and stocks more of what is selling.
- **The pass tops up TO a target, it does not add.** Leftover stock counts, so an item
  nobody buys never piles up. Demand above half a shelf scales the target with the ratio,
  capped at 4× so a run on an item cannot feed itself. `config/market.php` has the formula
  and the reasoning; MECHANICS.md has it in context.
- **The rarity roll is all or nothing** — one roll per item per pass. A failed roll adds
  nothing rather than adding less, because scarcity a player can feel is a shelf that is
  usually empty.
- **`market:seed` runs every eight hours** (`0 */8`, in `routes/console.php`) and is
  **not** the training heartbeat: there is no stored clock, a missed pass is never made up,
  and a pass run twice is two deliveries. That is what `withoutOverlapping()` is for.
  `--dry-run` prints the whole decision table and writes nothing.
- **The roll is injected** (`MarketSeeder::fromConfig(fn () => …)`), so tests pin the dice.
  A probabilistic pass tested against real randomness is a test that passes 95% of the time.

### Events

Nothing custom yet, but the wiring is standard Laravel: `Event::dispatch()`, listeners
auto-discovered from `app/Listeners`. `QUEUE_CONNECTION=sync` runs listeners in-process —
switch to `database` or `redis` before any listener does slow work, or it lands on the
request path. See TODO.md, which covers the switch and the worker it requires.

### Templates

Blade. `layouts/app.blade.php` is the signed-in chrome, `layouts/guest.blade.php` the
centred panel used by auth. Both set `<html lang>` and `dir` from the active locale.
Views compose UI kit classes; they do not define their own CSS.

## Versioning and releases

**Git tags are the source of truth; `CHANGELOG.md` is the human record.** There is no
release tooling and none is wanted — `standard-version`, `changesets` and
`semantic-release` are all npm, and this project has no `package.json` on purpose.
Composer's `version` field is deliberately absent too: for an application it is a second
number to forget.

SemVer, and **pre-1.0 is meant literally**. While the major is 0, the line is the schema:

- **Minor** — the release changes the database. Anyone deploying it has to run a migration
  before serving, and pre-1.0 that is the only kind of breakage worth a version number of
  its own. A "breaking change" here means *needs a migration you cannot casually undo*,
  not an API signature — remember `migrate:fresh` is forbidden against the remote.
- **Patch** — everything else, however large. A new page, a new screen, a font moved
  in-house: if a deploy is `git pull` and nothing more, it is a patch.

That is why 0.1.1 (self-hosting Plex) and 0.2.1 (the skill tree) are patches despite
adding things, while 0.2.0 was a minor for two migrations.

Three places carry the number, and they are bumped together:

| Where | What it is for |
| --- | --- |
| `git tag -a vX.Y.Z` | the source of truth |
| `CHANGELOG.md` heading | what changed and why |
| `config/version.php` | what a *running* container can report, via `config('version.app')` |

The last one exists because a deployed instance has no tags. It is the only copy that can
drift, so bump it in the same commit as the changelog heading.

### Cutting a release

**Work on `main`. Branching is the release script's job.** Commit to `main` as you go;
`bin/release` is what puts the work on a feature branch, and it does so after the fact —
so nobody has to decide what a change was going to be called before writing it.

**`bin/release X.Y.Z` does all of it.** It moves the commits since the last tag onto a
feature branch, drafts the changelog entry from them, opens `$EDITOR` (nano by default)
so you can rewrite that draft into something a person would read, and only then commits,
merges and tags.

```bash
bin/release                  # asks which version, showing the latest tag
bin/release 0.2.3            # or say so outright
git push origin main --follow-tags
```

Run with no version it reports the branch, the latest tag, how many commits are in the
release and whether any of them add a migration — then proposes the version that follows
from the schema rule and lets you press Enter to accept it or type your own. Next it asks
what to call the branch. `--branch NAME` answers that up front, `--no-branch` releases
where you stand, `--no-tests` skips the suite, and `--no-merge` commits and tags where you
are (which implies `--no-branch`).

What it does for you, and why:

- **Branches the work for you, backwards.** The commits since the tag become a feature
  branch, `main` rewinds to the tag, and the release comes back as a `--no-ff` merge. The
  history reads as though the work had been branched from the start; the workflow does not
  ask anyone to have known that in advance. Run it from a branch that is not `main` and
  nothing moves — it cuts the release it always did.
- **Suggests the branch name from the commits.** The commonest conventional-commit scope
  wins (three `feat(market)` commits suggest `feature/market`), falling back to the words
  of the earliest `feat` when nothing carries a scope. It is a suggestion; Enter accepts it.
- **Refuses to rewind a `main` that has been pushed.** Moving commits that are already on
  `origin/main` would need a force push, so it stops and names `--no-branch` instead. The
  check reads the last fetch, so `git fetch` first if that could be stale.
- **Deletes the branch it made**, after merging it — the merge commit is the record. A
  branch you made yourself still gets the old "delete it?" prompt.
- **Drafts, never writes.** Commit subjects say what changed to someone reading the
  history; a changelog says what changed to someone deciding whether to pull. The draft
  is grouped `feat`→Added, `fix`→Fixed, everything else→Changed, and carries a DRAFT
  marker the script refuses to proceed past — so the entry cannot ship un-rewritten.
- **Enforces the schema rule.** New migrations since the last tag are listed under
  **Migrations** automatically, and a release that adds one while bumping only the patch
  has to be confirmed.
- **Tags on `main`**, after the merge, never on a feature branch — a tag on a branch that
  later gets squashed points at a commit that no longer exists.
- **Restores the tree *and the branches* on any exit that is not a successful commit**,
  including an editor that quits non-zero or a failing suite. An abort puts the work back
  on `main` and deletes the branch it made. Nothing half-released is left behind.
- **Never pushes.** It prints the command; pushing stays a decision.

Afterwards, run the migrations the changelog named and any seeder they need.

## Constraints imposed by php.ini

`docker/php/php.ini` is hardened, and several settings will actively break otherwise
normal code. Read it before debugging anything that fails mysteriously:

- `open_basedir` is **no longer set in php.ini**. It lives in `docker/apache/vhost.conf`
  as `php_admin_value open_basedir "/var/www/cyberpunk:/tmp"`, so it applies to the web
  SAPI only. Applying it to the CLI broke Composer and Artisan on dependencies that probe
  paths outside the tree, and bought nothing — anyone who can run the CLI can already read
  the filesystem. `php_admin_value` is also stricter than php.ini was: `.htaccess` and
  `ini_set()` cannot override it.
- `allow_url_fopen = Off` — `file_get_contents()` cannot fetch URLs. Use cURL for HTTP.
- `disable_functions` blocks `exec`, `shell_exec`, `system`, `passthru`, `popen`,
  `symlink`, `parse_ini_file`, `show_source`. This is `PHP_INI_SYSTEM` and cannot be
  scoped per-SAPI, so it binds the CLI too: **`php artisan storage:link` will fail**.
  Create `public/storage` from the host with `ln -s` if you need public file storage.
- `register_argc_argv = Off` in php.ini, but **the CLI SAPI hard-overrides it to On**, so
  `$argv` is populated and Artisan works. The setting only affects web requests.
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
reaches that local one, *not* production data. To reach the real database, go through
Artisan or Eloquent in the `web` container. Keep this service — it is the safe target for
migrations and tests while the remote account stays over-privileged.

### The database account is dangerously over-privileged

Verified 2026-09-01 with `SHOW GRANTS`. This is the single largest risk in the project.

- The account holds **`ALL PRIVILEGES ON *.*`** — not scoped to the app's schema. That
  includes `SUPER`, `FILE`, `SHUTDOWN`, `CREATE USER`, `DROP` and `PROCESS`. `FILE` alone
  lets any SQL injection read and write files on the database server.
- It is **`lod@%`**, connectable from any host on the internet, over an **unencrypted**
  connection. Credentials and every query travel in clear text.
- The same account can see and write **two unrelated production schemas**: `gryningsland`
  (21 tables) and `lod` (14 tables). A bad migration or a stray `migrate:fresh` run
  against the wrong connection reaches real data belonging to other projects.

Before this goes anywhere near production: create a dedicated account granted only
`SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES` on the
`cyberpunk` schema alone, restrict its host mask, and enable TLS
(`DB_SSLMODE` / `MYSQL_ATTR_SSL_CA` in `config/database.php`).

**Until that is done:** never run `migrate:fresh`, `migrate:reset` or `db:wipe` against
the remote connection. Point tests and destructive commands at the local `db` container
with explicit env overrides:

```bash
docker compose exec -u www-data \
  -e DB_HOST=db -e DB_DATABASE=cyberpunk_db \
  -e DB_USERNAME=cyberpunk_user -e DB_PASSWORD=cyberpunk_password \
  web php artisan migrate:fresh
```

## Dependencies

Laravel 13 with **Eloquent** for database access. Doctrine DBAL is gone; Monolog and
`vlucas/phpdotenv` are still present but as Laravel's own transitive dependencies — use
`Log::` and `config()`, never those packages directly.

`roave/security-advisories` is installed, so a `composer require` that pulls in a package
version with a known CVE will fail — that is intended, resolve it by upgrading rather than
by removing the constraint.
