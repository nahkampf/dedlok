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
   A screen with three ember elements on it has two too many. Two filled signal buttons
   are the exceptions, and both are rationed the same way — one per screen, never beside
   another filled button competing for the same click: `.btn--go` (green, `--ok`) means
   *begin*, not *succeeded*; `.btn--warn` (brass, `--warn`) means *this undoes real work
   and destroys nothing* — stripping a deck is the case it was drawn for. Both are FILLED
   because they are things you choose. Ember stays an outline that only fills on hover,
   because filling it would put the accent on screen at rest. **Every button carries a sheen on
   hover** — an 8px soft band at 45°, crossing the face over 520ms, tinted per variant
   through `--sheen` so the reflection off a green button is green. It is a BACKGROUND
   LAYER and never a pseudo-element, because a notched button's face already *is*
   `::after` and its hairline *is* `::before`. **A state that recolours a button must
   set `background-color`, not `background`** — the shorthand resets `background-image`
   and takes the sheen with it, at exactly the moment it is meant to run. And **all
   three filled variants invert on
   hover** — fill and ink trade places over `--t-invert` (400ms), the one deliberately
   unhurried timing in the kit, with the border and `--cut-line` holding the colour so
   only the inside turns over. Declared once for the three of them, so a new filled
   variant only has to say what its colour is.
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
- **A new `.btn--*` variant needs a `.btn--*.cut` companion, always.** `.cut` paints the
  face with `::after` and `--cut-fill`, and that pseudo-element sits *above* the
  element's own background — so a variant that sets `background` and stops gets painted
  over in `--bg-2` and renders as an ordinary button with tinted text. It fails silently,
  and only where the notch is used, which is nearly everywhere. Set `--cut-line` and
  `--cut-fill`; every existing variant already does.

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

### The page frame: rail left, numbers on top

`layouts/app.blade.php` is a `.shell`, and the split between its two halves is the
point.

- **The rail (`.dock`) holds everywhere you can go**, plus the one thing you can do to
  your session. **A rail item never points at a row id.** *Deck* links to
  `deck.index`, which resolves which deck you mean and redirects — or sends you
  to the stash saying you have equipped none. A nav item that appears and
  disappears with your loadout is one nobody trusts, and the nav is the wrong
  place to encode "you do not have one of these". Icons at rest; it opens to icon + label on hover where there is a
  pointer (`@media (hover: hover)`, so a touch device cannot leave `:hover` stuck on)
  and on the `data-dock-toggle` button everywhere else — which is the only way in on a
  phone. It opens on `:focus-within` too, so closing it has to **take the focus out with
  it** or the press that shut it holds it open.
- **It overlays, never reflows.** `.shell--docked` is what pins it to the viewport;
  a bare `.dock` is an ordinary column, which is what makes it demonstrable in the
  gallery. Widening a rail that is in flow would reflow the whole page on hover.
- **The icon column stays `--dock-w` wide in both states**, and the active marker is a
  pseudo-element rather than a border, so nothing moves sideways when the rail opens or
  the page changes.
- **The bar is where status lives, and width is the whole constraint.** The mark, the
  two resources, the money, and a count of what is running. Three `.gauge` blocks at
  104px each did not leave room for the fourth, so the resources became `.dial`s — the
  same meter bent into a ring, 40px, with the current value inside it and the exact
  figures in `title` and `aria-label`. Money is a glyph and a number (`.chip-stat`),
  because `¤` is in Latin-1 and the word is not worth 40px. Anything ADDED here has to
  pay for itself in the same currency: audit at 320px or take it to the rail, which is
  why *Sign out* sits there. The wordmark is `.brand__name`, its own element rather than
  a bare text node so CSS can drop it below 560px while the mark stays. It is
  `.topbar--stuck`: **sticky, not fixed**, so it stays in flow and nothing below has to
  reserve a matching strip of padding — the number that is still 52px on the day the bar
  wraps to two rows.
- **A dial is a meter, not a second component.** `--v` is the ratio, `--fill` the
  colour, `data-hot-low` the threshold — all read by the same `ui-kit.js` that drives
  `.meter`, so "low" is one number in one place across both shapes. Use a bar wherever
  there is room for one; the dial exists because the topbar has none.
- **`creds` has no bar because it has no maximum.** The two resources come from
  `Character::RESOURCES`, whose maxima are derived and resolved per request;
  `Character::resourceRatio()` is the meter's `--v`. Money is in neither, for the reason
  it is not in `RESOURCES`.
- **What counts as "low" lives in `ui-kit.js`, not in a template.** A server-rendered bar
  carries `data-hot-low` and its `--v`; the kit runs the same test over it on load, so
  the threshold is one number in one place. `.meter--hot` paints the fill element and not
  only `--fill`, because a colour set inline out-specifies a custom property and a
  critical bar would otherwise sit there green.
- **Every glyph in the rail is from the set proved to render** in the kit's font stack,
  the same discipline as `Item::glyph()`. Measure a candidate against the stack before
  using it; the ones that come back at the notdef width are missing.

### Images

**Pictures are found by convention, never stored on a row.** `public/images/<kind>/`,
one subdirectory per kind of thing, and inside it:

| File | Is |
| --- | --- |
| `<id>.jpg` | what a card shows. **16:9**, and the source wants to be about **640×360** — twice the widest card, so it is sharp on a dense screen and never scaled UP. |
| `<id>_full.jpg` | what the modal opens. **Any dimensions**: it is the picture itself rather than a slot to fill. |
| `_default.jpg`, `_default_full.jpg` | the fallback, per kind. Ships with the folder. |

Nothing is required: a thing with no picture, no type default and no default above it
renders no image at all rather than a broken one.

`App\Support\Image` resolves them and `<x-entity-image>` renders one. Dropping a file
in makes the picture appear and deleting it brings the default back — no column, no
upload, no seeder, and nothing that can disagree with the filesystem. **A missing full
version means no modal at all**, which is why `card()` and `full()` are separate
questions rather than one.

- **Stamped through `Asset::url()`** like every other public asset, for the reason the
  stylesheet is: nothing renames a file when its contents change.
- **The card image is a fixed 16:9 and crops.** A grid of cards whose pictures are each
  a different height is a grid that never lines up; the card is a slot the picture fills,
  never the other way round.
- **The id is validated as one path segment**, and so is every segment of the kind. They
  come from the database rather than a request, so that is a belt rather than braces —
  but a filename built by concatenation is exactly the shape that ends up reading
  `../../.env`. One bad step refuses the whole path rather than the part before it.
- **`data-open` calls `preventDefault()`**, because an opener can sit inside a `<label>`
  — as it does on the archetype cards — and looking at a picture must not also pick the
  thing it belongs to.
- **A kind may be a path, and items are one subdirectory per TYPE** —
  `public/images/items/cpu/`, `items/icebreaker/`, `items/skillbook/`, `items/physical/`.
  One folder holding every item in the game is a folder nobody can find a file in.
  `Item::imageType()` names it and `Item::imageTypes()` is the whole list, composed from
  `Hardware`, `ProgramKind`, the book and the two inventories — so a new kind of hardware
  or program brings its folder with it rather than needing one remembered.
- **The type vocabulary is the card's, with one difference.** A skillbook CALLS itself
  data and FILES itself as a book: there is one book per skill and they all look alike,
  so one picture covers every book in the game.
- **Only the default walks back up the path.** `items/_default.jpg` stands in for every
  type nobody has drawn yet, and a type that draws its own overrides it by existing. An
  item's own picture is looked for in its type's folder and nowhere else — a file one
  level up is filed in the wrong place, not filed loosely.
- **Where a card gets CLONED, the enlarge dialog is rendered beside the listing** —
  `<x-item-image>`, the same shape as `<x-item-destroy>` and for the same reason: the
  item card is cloned into the hover inspector and into a modal, and a `<dialog>` cloned
  with it is a second copy of itself in the top layer. `<x-entity-image>` renders that
  dialog inline (the archetype cards) unless a `dialog` id says it lives elsewhere; the
  markup itself is written once, in `<x-entity-image-dialog>`. The id is keyed by the
  inventory ROW, so two of the same thing are two dialogs rather than one duplicated id.

### Cache busting

**Both layouts load the kit through `App\Support\Asset::url()`, never bare `asset()`.**
It appends the file's own mtime as `?v=`. There is no bundler, so nothing renames a file
when its contents change, and Apache sends no `Cache-Control` for `public/assets` — which
leaves a browser free to hold `ui-kit.js` for as long as it likes without asking whether
it is still current. That is invisible while a file is stable and then costs an afternoon:
the markup arrives new, the behaviour driving it is a week old, and the screen does
nothing. The stamp is the mtime rather than a hand-kept number, because a version someone
has to remember to bump is wrong exactly when it matters. `tests/Feature/AssetVersionTest.php`
holds the rule.

`public/ui-kit.html` is static and still links the assets bare. It is a developer's page
and a reload fixes it; a query string written into the file by hand would go stale.

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

**One character per user, and two middlewares say so.** Registration creates only the
user, so the pair is what makes the rule true in practice rather than by convention:

- **`RequireCharacter`** runs on every web request. A signed-in player without a
  character goes to the creation screen whatever they asked for — except the routes it
  names (the creation form, logout, the handle pool) and anything under `admin.`, which
  edits the game rather than a character.
- **`ForbidSecondCharacter`** is the mirror, and applies to those two creation routes
  only: a player who already has a character is sent back to the run screen. **It guards
  the POST as well as the form.** `characters.user_id` is unique, so an unguarded second
  submission is a 500 with an integrity error rather than a refusal — and a stale tab or
  a back button reaches `character.store` without ever loading the form again. A safe
  request is redirected silently; a write says why, because silence after a submitted
  form reads as the form having done nothing.
- **An admin may still *look* at the creation screen**, with a character or without one.
  It is a screen they maintain — the archetype cards and their loadouts, the size of the
  point pool — and opening it is the only way to see that work. The exception is a read
  and nothing more: the POST is refused for everyone who already has a character. The
  middleware marks the request (`ForbidSecondCharacter::PREVIEW`) rather than making the
  controller ask the same question again, and the view then renders a notice and **no
  submit button at all** — a disabled one would be re-enabled by the pool updater the
  moment the form validated.

Both answer a JSON caller with **409** and the URL to go to, rather than a redirect a
fetch() would follow into HTML.

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
- **`ItemSeeder` seeds no items.** It generates one skillbook per skill and nothing
  else. A hardcoded catalogue in PHP would be a second source for content the items
  table already owns — and the one that wins on a re-seed, so an item deleted in the
  admin editor would come back. Author items in the editor; the only rows a seeder
  may write are ones *derived* from another table, like the books.
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
- **The stash is a slot grid, and the detail card is the only place words are.**
  One `.slot` per *loose* row — the grid is deliberately unpadded, because
  neither inventory has a capacity and a run of empty slots would draw a limit
  that does not exist. Everything a slot cannot show (flavour, numbers, a
  container's contents, and every action) lives in one `<x-item-detail>`,
  rendered once into a `<template>` beside the slot. The kit's `data-inspect`
  behaviour clones it into the floating `.inspector` on hover and into a modal
  on click, so hover and tap show the same card and neither can drift.
  `[data-act]` marks what appears only in the modal: a hover layer is not
  somewhere a click can land, and a phone has no hover at all. **A row inside a
  container gets no slot**, so its inject and eject buttons live on the
  container's card — the only place it is reachable.
- **An item's actions live on its card**, in `<x-item-detail>`, which is the
  thing you already have open. **Do not put them behind a right-click.** They
  were there once: a right-click has no mobile story, nothing on screen says it
  exists, and a browser extension can eat `contextmenu` before the page ever
  sees it — three separate ways for a feature to be invisible, and all three
  happened.
- **An action that does nothing yet says so with a `.tag`** rather than being
  absent. A card that grows buttons as features land is a card nobody learns.
  *Show on market* and *Disassemble* are those; *Fit to deck*, *Take out of
  deck*, *Board*, *Inject*, *Store* and *Destroy* are real.
- **Every surface that lists an item needs a route to its card**, since the
  card is where the actions are: the stash grid has the slot itself, the
  equipped deck's "in use" row has a Details button, and a filled socket on the
  board has one in its dialog. All three go through `data-inspect-open`.
- **The destroy confirmation is rendered beside the listing, never inside the
  card** — `<x-item-destroy>`. The card is cloned into a shared modal, and a
  `<dialog>` cloned with it would be a second copy of itself in the top layer.
- **Destroying is real, irreversible, and the confirmation is the only brake** —
  the same decision the admin item editor took. The dialog spends what the
  delete reaches before offering the button: contents cascade with a container,
  a deck's modules do NOT and come back loose, and destroying the equipped deck
  leaves you with none. It has no writer class of its own, unlike injecting or
  placing: there is exactly one rule and the controller already checks it.
- **`ModuleFitter::fitToDeck()` picks the socket**, since the menu's *Fit to
  deck* is the player choosing the module and not the place. It tells "this
  deck has no socket that takes it" apart from "every socket that takes it is
  full", because one will never work and the other will. **There is no skill
  check** — no module carries a requirement in any column or table. When one
  does, that method is where it goes: it is the only place that knows the
  character, the module and the deck at once.
- `size` is enforced, but only as what a container's contents cost against its capacity.
  `weight` is still stored and unenforced, and neither inventory has an overall capacity.

### The market's supply side

**The market restocks itself; nothing sells anything yet.** There is no price paid and
no transaction — what exists is the half a storefront will be built on top of. Money
exists now (`characters.creds`, see *Archetypes and what they hand out*) and nothing
spends it.

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

### Cyberdecks and modules

**A cyberdeck is an item, and so is every module that goes in one.** One column
says which, and two tables carry what a bare `items` row cannot.

- **`items.hardware` is the only place the kind is written down** — `deck`,
  `cpu`, `npu`, `ram`, `nic`, `expansion`, `psu`, or NULL for the rest of the
  catalogue. `deck_modules` carries the numbers and *not* the word, so a row
  can never disagree with its item about being a CPU.
- **`deck_slots` is one row per socket, not a count per kind.** A socket is a
  place: a module fitted into a deck will point at the one it went into, and a
  count cannot be pointed at. `App\Decks\SlotLayout` is the same set seen as
  counts, which is what the editor and every spec line actually want — and it
  is pure, because the editor needs a layout for a deck that has not been saved
  yet exactly as much as for one that has.
- **`App\Decks\SlotType` is the whole vocabulary**: fourteen (kind, variant)
  pairs. The editor renders one counter per type, the request validates against
  the same list, and the layout counts by it, so a fifteenth socket is an enum
  case and a label and nothing else.
- **A cap belongs to the kind, not to each variant.** Five expansion slots
  means five altogether. That is why it is not a CHECK constraint — a deck's
  sockets are separate rows, and no constraint on one can count the others.
  `ItemRequest::withValidator()` holds it, along with "a deck needs a CPU
  socket" and "a deck needs a power socket".
- **`universal` is a socket and never a module.** A RAM slot may take either
  kind of stick; no stick is both. The two sides are validated against separate
  lists for that reason, and fit is `Variant::accepts()` rather than `===`.
- **An IO port seats nothing.** The electrode and neuralink ports are rows like
  any other socket rather than two booleans, so "what does this deck present"
  stays one question with one shape of answer.
- **At most one measure per module**, enforced by `chk_deck_modules_one_measure`:
  qFLOPS, quantabits, QB/s and watts do not convert into one another. An
  expansion card fills none of them — what one *does* is undecided, and a
  made-up number now is a number to migrate away from later.
- **A supply gives power and draws none** (`chk_deck_modules_supply`). The
  budget that would spend it does not exist yet, because nothing is fitted into
  anything yet.
- **`App\Decks\HardwareEditor` is the only writer**, and it always clears the
  side an item stopped being: a deck re-saved as a CPU loses its sockets, and a
  CPU re-saved as a NIC loses its qFLOPS. It replaces a deck's sockets
  wholesale, like skill prerequisites — **when a socket can be occupied, that
  has to become a diff.**
- **A module's spec line never says what it is.** `hardwareSummary()` gives the
  numbers — `Opto-electrical · 16 QB · 34 W draw` — and the word RAM appears
  nowhere in it. `Item::kindName()` is what answers that, and it is what the
  detail card puts beside the tier: deck hardware says which piece it is, and
  everything else falls back to the inventory it lives in (`items.kinds.*`).
- **A deck's board is a picture, and it lives on the item.** `items.board_layout`
  is a JSON map from `SlotType::key()` to `[x, y]` percentages, and
  `App\Decks\BoardLayout` turns it into nodes and wires. **A node is a type, not
  a socket** — four QDRAM sockets are one box with one wire, so the coordinate
  belongs to the pair and not to the rows; four rows carrying it are four
  chances to disagree. **NULL means unarranged, not missing**: the automatic
  ring draws it, so every deck renders without an author. Stored coordinates
  are an **override on top of that ring**, never the whole answer, which is why
  a partial arrangement is normal and a new socket lands somewhere sensible.
  `<x-deck-board>` is the one renderer — the player's screen and the admin's
  drag editor are the same component with `editable` flipped, because an editor
  that draws the board differently from the screen is an editor that lies.
- **A deck has no thermal rating and heat does not happen to it.** Power is
  measured against the supplies fitted in the deck; heat is measured against
  nothing at the deck level. A chassis cap would be a second verdict about one
  thing, free to disagree with the per-module one — do not add one back. On the
  screen that asymmetry is why power has a meter and heat is a bare figure.
- **Every module makes heat** (`deck_modules.heat`, a default like
  `power_draw`) and a **radiator takes it away** (`Hardware::Thermal` in a
  `thermal` socket, measure `cooling`). A radiator cools and heats none — the
  mirror of a supply, and a CHECK for the same reason — but it still draws,
  because the fan runs on something.
- **A module's heat tolerance is a pair of thresholds on the DECK'S heat**, not
  on its own output: `heat_level_warning` and `heat_level_limit`, nullable
  together (null = does not care), warning at or below limit. The same part is
  safe in a cold deck and dying in a hot one — **a deck's tolerance is its
  weakest part, not the sum of its parts**, which is why
  `App\Decks\ThermalExposure` is separate from `DeckTotals`. Both thresholds
  are resolved stats.
- **The two warnings are deliberately different volumes.** Over the warning
  level: an amber callout naming the parts. Past the limit: the kit's
  `.hud-alert`, the only place the deck screen spends the accent, because it is
  a certainty rather than a risk. `.slot--hot` pulses slowly on the board —
  slow because it is a condition, not an event — and `.slot--doomed` adds the
  crit fill.
- **Destroying modules in an overheating deck is NOT built**, and neither is
  the accumulation that would cause it. `DeckTotals::netThermalLoad()` is a
  RATE, in thermal units per turn, and nothing runs a deck over time — so no
  total accumulates and there is nothing for a threshold to trip. What exists
  is `ThermalExposure`'s static verdict on a loadout
  (`ThermalVerdict::OverLimit`), which the deck screen shows as a warning about
  what WOULD happen. The algorithm that destroys a module is undecided.
  CYBERSPACE.md puts accumulation in two places and they are not the same
  mechanic: inside a run, where it resets when the run ends, and under a
  decryption, where it cooks the weakest module overnight and unattended.
- **A module goes into a socket, and `deck_fittings` is that row.** Three
  references, because three things have to be named: `deck_id` (an inventory
  row — WHICH deck), `deck_slot_id` (a definition row — WHICH socket), and
  `module_id` (an inventory row — WHAT went in). **Only the pair is a place**:
  a slot id alone is "the CPU socket of a Kurogane Explorer", shared by every
  Explorer in the game. `App\Decks\ModuleFitter` is the only writer.
- **Fitting is not containment and shares nothing with it.** A container asks
  whether the bulk fits in what is left; a socket asks whether this is the
  particular thing this particular place takes. `parent_id` stays NULL for a
  fitted module — what takes it out of the player's hands is
  `InventoryItem::scopeLoose()`, which now means *neither inside anything nor
  fitted into anything*. One place decides, so no listing had to learn about
  fittings.
- **Destroying a deck frees its modules**, deliberately unlike a memory shard,
  which destroys what is written on it. Stripping a deck for parts is what
  anyone would do before letting go of it.
- **`HardwareEditor` diffs sockets now, it does not replace them.** A wholesale
  replace would empty every player's deck whenever an admin touched an
  unrelated field. The diff is by type — one Socket K is indistinguishable from
  another until something is in it — and when there are too many it drops the
  EMPTY ones first. Removing an occupied socket is still allowed and returns
  the module to its owner.
- **Modules do not stack, for the reason decks do not.** A fitting points at an
  inventory row, and a row holding two sticks cannot be pointed at.
- **Equipping is not jacking in.** Jacking in and out is entering and leaving
  cyberspace, a session an equipped deck sits right through; equipping is which
  deck you are carrying. Never use one word for the other in a player-visible
  string. (`auth.login`/`auth.logout` still say "Jack in"/"Jack out" for signing
  in and out — pre-existing, and worth revisiting when a cyberspace session
  exists.)
- **An equipped deck IS in the stash.** It is on you, not elsewhere, so it lists
  with everything else and its card wears the tag. It used to be excluded, and
  the exclusion forced the stash to grow a panel whose only job was to be the
  route back to it — a box built to solve a problem the exclusion created.
  `scopeLoose()` now leaves out three things that really are elsewhere: rows
  inside a container, modules fitted into a socket, and programs that are
  running. All three decided in one place.
- **`characters.equipped_deck_id` is which deck is carried**, and NULL is the
  ordinary state. A column rather than a flag on the inventory
  row, so "only one at a time" is not a rule anybody enforces: one column holds
  one value. `App\Decks\DeckEquipper` is the only writer, since the two rules
  the database cannot reach across a table to check (it is yours, it is a deck)
  have to live somewhere. Equipping a second deck swaps rather than refusing.
- **Stripping destroys nothing**, so nothing confirms it — `ModuleFitter::strip()`
  empties every socket and the modules go back to the stash. Unequipping DOES
  ask, not because it is dangerous but because there are two right answers:
  leave the deck built, or strip it on the way out. Stripping and equipping are
  independent in both directions.
- **`App\Decks\DeckTotals` is the ONE place a deck is totalled**, which
  MECHANICS asks for by name — the stash renders it and the equipped deck will
  render the same number once anything resolves, so no total is ever computed
  twice. It sums by MEASURE, not by socket: qFLOPS comes from a CPU or an NPU,
  so a deck with one of each has one compute figure.
- **`App\Decks\DeckRequirement` says whether a deck will run** — compute (CPU
  or NPU), memory, link, power, and draw within supply. One enum case plus one
  `match` arm each, so a sixth requirement is two lines and a string. **Nothing
  refuses a fitting because of it**, including one that pushes the deck over
  budget: the screen names what is missing instead.
- **A deck's measures are RESOLVED, not read off the row.** Five stat ids —
  `deck_qflops`, `deck_quantabits`, `deck_bandwidth`, `deck_power_draw`,
  `deck_power_supply`, all tagged `deck` — and `DeckTotals::resolvedWith()`
  hands the summed base to `App\Stats\Resolver`. **The equipped deck resolves;
  every other deck shows bases**, because modifiers belong to a character and
  only one deck is theirs at a time. `DeckTotals::forCharacter()` is the one
  place that rule lives — it moved off `DeckController::totals()` (which now
  delegates to it) the moment the jack-in gate became a second caller, because
  a readiness check reading bases while the screen showed resolved numbers
  would refuse a deck the player is being told is fine. Skills move deck measures
  now — Overclocking and Cyberdecks raise `deck_qflops`, Advanced Routing
  cuts `deck_power_draw`, Thermal Management cuts `deck_thermal_load` — so
  the equipped deck genuinely reads differently from the same deck in the
  stash, and every screen picked that up at once because there is one place
  the rule lives.
- **Readiness gates jacking in, and nothing else.** `DeckTotals::isReady()` is
  the whole check, and `App\Cyberspace\Navigator::jackIn()` is what calls it.
  Fitting and equipping are never refused for it — you build a deck while
  carrying it. CYBERSPACE.md names one more caller, the start of every
  intrusion run, which does not exist yet.
- **The deck is locked while you are jacked in.** Fitting, taking out,
  stripping, unequipping, and destroying the deck or anything fitted in it are
  all refused while a character is in cyberspace — three writers
  (`ModuleFitter`, `DeckEquipper`, the destroy path) asking ONE question,
  `Character::isLockedInCyberspace()`, so the rule cannot drift into three.
  Only the EQUIPPED deck and its modules: a spare deck in the stash is not in
  play, other items are still destroyable, and training carries on.
  **The lock guards what the PLAYER does and must never be asked by the game's
  own consequences** — a Surge or a thermal burnout destroys fitted hardware
  precisely while somebody is jacked in, and neither goes near these writers.
  The other half is still missing: losing readiness while inside is meant to
  dump the player out for 1 point of Synaptic Integrity, and nothing watches
  for it. See TODO.md.
- **Nothing a deck stores makes its owner better at anything yet.** No stat,
  check or modifier reads a deck's totals in the other direction; the
  `ModifierSource` over fitted hardware that MECHANICS names is still missing.
- **Every stored measure is a BASE, not a value.** `qflops`, `quantabits`,
  `bandwidth`, `watts`, `power_draw` — implants and skills are all meant to
  move them, so when something finally reads one it resolves it through
  `App\Stats\Resolver` rather than reading the column. A deck's totals are
  therefore per character, and may never be cached on a row. See MECHANICS.md,
  *Deck numbers are bases, not values*.
- **A deck that cannot power every socket it presents is intentional.** Do not
  "fix" one, and do not add a bigger supply to make one fit — over-commitment
  is the player's decision to balance, and skills are meant to cut draw.

### Archetypes and what they hand out

**An archetype is a role, a set of perks, and a loadout.** The perks say what it
*changes*; the loadout says what it *gives* — cash, skills and items, granted
once, at character creation.

- **`archetypes.playable` is what a new character may be, and only the Deck Jockey is.**
  The other six are written but not built, so they are **withdrawn, never deleted** —
  `characters.archetype` is a foreign key, and the roster still has to resolve a name
  and a perk set for anyone who already chose one. Same shape and same reasoning as
  `items.on_market`, and the column defaults to **false** for the reason
  `seed_quantity` defaults to 0: nothing reaches a player by being forgotten.
- **`Archetype::playable()` is the menu; `Archetype::roster()` is the truth.** Anything
  asking "what can be chosen" wants the first; anything resolving a character that
  already exists wants the second. The creation validator uses `playable()`, so a
  hand-rolled POST cannot mint a role with nothing behind it — the disabled cards are
  the courtesy, not the guard.
- **A withdrawn archetype still renders**, greyed out by the kit's own device (a
  `.card--select` holding a disabled radio half-lights itself) with a *Not yet* tag.
  Hiding them would make the roster look shorter than it is.

- **`archetypes.starting_creds` is a column; the other two are tables.** Cash is
  one number. Skills and items are sets, so `archetype_skills` and
  `archetype_items` are rows pointing at the definition — a foreign key the
  database enforces, rather than a slug nothing checks until a character is made.
- **`characters.creds` is the first money in the game**, and nothing spends it.
  Stored and mutable like the two resources beside it, but with no derived
  maximum, which is why it is not in `Character::RESOURCES` and is not a stat:
  nothing targets it, so nothing resolves it.
- **A starting skill is granted, not sold.** No book is spent, because none is
  owned. `App\Inventory\SkillInjector::grant()` is the second door into
  `character_skills` and deliberately sits on the same class as `inject()`, so
  the training gate stays one place to read.
- **A granted level seeds the points that level costs.** `attained_level` and
  the level `skill_points` implies must agree, or `skills:validate` reports
  drift on a character that has done nothing yet.
- **Level 0 is the ordinary grant** and means what injecting a book means: on
  the sheet, untrained. It is also why level 0 is exempt from the prerequisite
  rule below — injection has never checked prerequisites, only training does.
- **A grant above level 0 must be reachable.** Every prerequisite of a skill
  granted at level 1 or more has to be granted too, at least as far.
  `ArchetypeRequest` refuses the rest: the archetype would otherwise mint a
  character in a state their own training queue could never have produced.
- **Items land loose, never inside a container.** Putting a grant in a container
  would mean granting the container as well and ordering the two, and a loadout
  is a set. An unstackable item becomes one row per copy — two decks are two
  objects.
- **`App\Archetypes\LoadoutGranter` runs at creation and nowhere else**, inside
  the transaction that writes the character. Editing a loadout changes what the
  NEXT character gets; it never reaches back. What was granted is the player's
  from then on — theirs to spend, sell or lose.
- **`App\Archetypes\LoadoutEditor` is the only writer of the two tables**, and
  it replaces both wholesale, like skill prerequisites and deck sockets. Same
  caveat as those: **when a grant row acquires anything worth preserving, that
  has to become a diff.**
- **A granted level is drawn, not spelled out.** The archetype card shows the kit's
  segmented meter — `.meter--seg` with `--segs` from `SkillProgression::maxLevel()` —
  the same device the skill tree uses for a level, so the two screens read alike. An
  empty meter is a skill granted and untrained, which is not the same as no skill; the
  number lives in the meter's `title` and `aria-label`.
- **The roster cache stays lean.** `Archetype::roster()` is read on nearly every
  request by stat resolution and holds perks only; `Archetype::withLoadout()` is
  the uncached read for the two screens that need the grants.

### Admin tools

**Three content editors — items, skills and archetypes — behind one gate.** Content
lives in the database, which is the documented exception to the config rule; these
screens are what replaces the protection config content gets from tests and code
review.

- **`users.is_admin` is the whole authorisation model.** One `Gate::define('admin', …)`
  in `AppServiceProvider`, read as `can:admin` on the route group and `@can('admin')` in
  Blade. A role table for a set of size two would be a framework standing in for an `if`.
- **The flag is outside `User`'s `#[Fillable]`, deliberately.** There is no HTTP path to
  it at all. `php artisan admin:grant <email>` (`--revoke` to reverse) is the only writer,
  and it uses `forceFill` — which is also the only way the *first* admin could be made.
- **`RequireCharacter` lets `admin.*` through by name prefix.** The tools edit the game,
  not a character, so demanding one would be a rule about the wrong subject — and it would
  trap an admin account that has never played behind the creation form. The gate, not the
  middleware, is what guards them.
- **No `show` route on any of the three.** The edit form *is* the detail view; a
  read-only copy would be a second place to add every new column.
- **Both loadout dropdowns are grouped**, because a flat list of every skillbook in
  the game is a scroll rather than a choice. Skills group by their `category`, shown
  raw the way the skill tree shows it; items group by their own vocabulary — deck
  hardware by `items.hardware` (labelled by `App\Decks\Hardware::label()`), then the
  two inventories, then skillbooks last. The grouping is decided in the controller and
  `<x-grouped-options>` renders it, so the four selects that need the identical list
  (a row and a template each, for skills and items) share one copy of it.
- **The archetype editor has no `create` and no `destroy` either.** An archetype id is
  written into every character row that chose it, so the roster arrives by migration and
  leaves the same way; the editor tunes what it hands out. Perks are displayed there and
  edited nowhere — a live perk is a list of modifiers with a target shape of its own,
  which is an editor in its own right.
- **A slug is permanent and the request says so.** `slug` is `prohibited` on update rather
  than merely absent, so a hand-crafted POST is refused instead of silently ignored.

**The deck board is arranged by dragging, and by the arrow keys.** The editor
renders `<x-deck-board editable>` and the nodes write every coordinate back into
one hidden JSON field; `data-board-min`/`max` carry the clamp bounds from
`BoardLayout` so the JS and PHP cannot disagree about where the edge is. It is
arranged for the sockets **as saved**, not as the counters currently read — the
ring that places a new socket lives in PHP, and a second copy of it in
`ui-kit.js` would be a second answer to one question. Change the counts, save,
then arrange.

**The item editor writes three tables.** An item is a row, plus a socket each
in `deck_slots` if it is a cyberdeck, plus a row in `deck_modules` if it is a
module — see *Cyberdecks and modules*. `ItemController` wraps both writes in a
transaction and delegates the decision of which tables those are to
`App\Decks\HardwareEditor`.

**The form requests are the substantive part.** `App\Http\Requests\Admin\ItemRequest`,
`SkillRequest` and `ArchetypeRequest` mirror every CHECK constraint on the tables — the
constraints are the guarantee, these are the explanation, because what the database
returns is a 500 and a constraint name. Five rules have **no** counterpart in the
schema, because SQL cannot express them against a single row:

- **One book per skill.** `items.use_skill_id` is unique, or `Skillbook::forSkill()` is
  ambiguous and a skill has two names.
- **A container cannot stack**, and neither does a cyberdeck. `InventoryItem` derives
  `stack_key` from (character, item, parent), so two stackable containers merge into one
  row and the second one's contents are gone. A deck is the same case one step ahead of
  itself: its sockets are what will be filled per object.
- **No prerequisite cycles.** Nothing about "A needs B" and "B needs A" is wrong on
  either row; together they are two skills nobody can ever learn.
- **A deck's socket counts.** Five expansion slots and a CPU socket to fill are
  facts about a set of `deck_slots` rows, and a CHECK sees one row at a time.
- **A granted skill level must be reachable.** An archetype starting a character at
  level 2 in a skill whose prerequisite it does not grant describes progress the
  training queue would have refused to make. That is a walk of the prerequisite graph,
  not a fact about one row.

**`App\Skills\PrerequisiteGraph` is the one graph walk.** Pure — it holds ids, never
models — so the editor can ask what *would* happen if a set of edges were saved without
saving them first (`->with($id, $requires)->cycleThrough($id)`). `skills:validate` uses
the same class, so the check that runs in CI and the check that runs in the form cannot
disagree.

**`App\Inventory\Skillbook` is the one derivation.** Creating a skill creates its book,
because "exactly one book per skill" is what makes a book nameless and what makes the
injection gate meaningful — a skill made in the UI with no book is a skill nobody can
learn. `ItemSeeder` calls the same class, so the two cannot drift. What follows the skill
afterwards is deliberately narrow: **`base_cost` and `on_market` are kept in step on every
save; `tier`, `rarity` and `seed_quantity` are derived from rank once, at creation, and
belong to the item editor from then on** — the seeder's rule that a tuned value is not a
value to overwrite holds here too.

**Deletes are real and unguarded, and the confirmation is the only brake.** There is no
soft delete and no undo. `App\Admin\DeletionImpact` counts what a delete would reach —
inventory rows, copies players carry, shelf stock, purchase history, container contents,
character progress, queued training, requirements naming it — and the dialog spends that
count before the button is offered. It is a description, never a guard. To retire content
instead, unlist it: `on_market = false` is what that boolean is for.

**A skill's book must be deleted first.** `items.use_skill_id` is `restrictOnDelete`, so
the database refuses a skill something still points at rather than cascading. The
controller drops the book inside the same transaction; everything else
(`character_skills`, `training_queue`, both directions of `skill_prerequisites`) cascades
on its own.

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

### Every table and every column carries a comment

**This is a standing rule, not a one-off tidy-up: EVERY NEW TABLE AND EVERY NEW
COLUMN GETS A COMMENT, in the migration that creates it.** No exceptions, and
it is not something to come back to later.

**The schema explains itself in MySQL, not only in the migrations.** All 32
tables and all 257 columns carry a `COMMENT` saying what the thing MEANS rather
than restating its type — which inventory `items.container` names, why
`characters.grid_x` being NULL is ordinary, which numbers are bases rather than
values, which ones nothing reads yet. A GUI, a `SHOW CREATE TABLE`, or somebody
with a mysql prompt at three in the morning gets the same explanation the code
has.

How to write them:

```php
Schema::create('widgets', function (Blueprint $table): void {
    $table->comment('What this table is for, in a sentence.');
    $table->string('slug', 100)->comment('Stable machine name. Permanent: rows reference it.');
});

// Adding to an existing table works the same way.
$table->unsignedInteger('charge')->comment('Uses left before it is spent.');
```

Both are **no-ops on SQLite**, so the test suite is unaffected — exactly like
the CHECK constraints.

**Two ways this fails silently, and `bookmarks` hit both on its first
outing:**

- **`->comment()` must come BEFORE `->constrained()`.** `constrained()` returns
  a `ForeignKeyDefinition`, not the column, so a comment chained after it is
  set on the key and never reaches the column:
  `$table->foreignId('x')->comment('…')->constrained()->cascadeOnDelete();`
- **`$table->timestamps()` returns void**, so `created_at` and `updated_at`
  cannot be commented inline at all. Add them with a separate statement, or
  accept that they will need a follow-up migration. `$table->id()` does accept
  `->comment()`, but it is easy to forget in passing.

Neither failure says anything: the migration runs, the table is right, and the
comment is simply absent — and both of them have already happened, on the two
tables added since the rule was written. **So check, do not remember:**

```bash
docker compose exec -u www-data web php artisan db:comments          # report
docker compose exec -u www-data web php artisan db:comments --fill   # and fix the easy ones
```

`--fill` writes only the three bookkeeping comments — `id`, `created_at`,
`updated_at` — because those mean the same thing on every table there has ever
been. It deliberately refuses to invent anything else: a generated line saying
"The owner id." would be worse than the silence, because it would look done.
The command exits non-zero while anything is still missing, so it can sit in CI
the day there is one.

- **Say what it means, not what it is.** `varchar(20)` is already in the type.
  Whether NULL is an ordinary state, whether a number is a base the resolver
  reads, and whether anything reads it yet are the things worth a sentence.
- **Do not edit `2026_09_04_190000_comment_every_column`.** It is history and
  covered what existed when it ran. That migration also documents why column
  comments have to restate a whole definition, and how it avoids the risk.
- **Nothing enforces this.** A missing comment fails no test and no build, so
  it is a habit rather than a guarantee — which is exactly why it is written
  down here.

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
