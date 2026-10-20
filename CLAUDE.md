# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

A cyberpunk browser game. PHP 8.4 + MySQL, Laravel 13, developed entirely in Docker.
Two systems are already binding: the Laravel app and the **Ash & Ember** UI kit. Read
both before writing code.

Subsystem rules (items, decks, City, combat, inbox, admin, deploying…) live in `docs/` — each
section below points at its file; read it before editing that subsystem.

Companion docs: `MECHANICS.md` (anything producing a number), `CYBERSPACE.md` (the grid,
systems, runs), `CYBERSPACE_RULES.md` (the binding rules for all of it), `CITY.md` (districts,
travel, explore, shops, clinics, encounters), `COMBAT.md` (physical combat — items built, engine designed),
`WORLDBUILDING.md`, `TODO.md`.

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

## Frontend: the Ash & Ember UI kit

**Read this before writing any HTML or CSS.** The design system is finished. Do not
invent components, colours, spacing or type — compose what exists.

| File | Role |
| --- | --- |
| `public/assets/css/ui-kit.css` | Tokens, then components. No build step. |
| `public/assets/js/ui-kit.js` | Behaviours, data-attribute driven. Exposes `window.UI`. |
| `public/assets/fonts/` | Overpass and IBM Plex, self-hosted (SIL OFL); IBM VGA 9x16 (CC BY-SA 4.0). |
| `public/ui-kit.html` | Live gallery with copyable markup. |

The gallery is the reference — read its markup before building a screen. Adding a
component means adding a demo in the same `<template class="demo-src">` pattern.

### The three rules

1. **Hairlines carry structure.** Borders separate things — not shadows, not fills.
   Elevation is a step in `--bg-0`→`--bg-4`, never a glow.
2. **Bone is the ink; colour is rationed.** Primary buttons are `--ink-0`. Ember means
   *live, critical or destructive* only — ~2% of lit pixels. Two filled signal buttons
   are the exceptions, one per screen, never beside another filled button:
   `.btn--go` (green, `--ok`) means *begin*, `.btn--warn` (brass) means *this undoes real
   work and destroys nothing*. Ember stays an outline that fills only on hover.
   Every button carries a hover **sheen** — an 8px band at 45° over 520ms, tinted through
   `--sheen`. It is a BACKGROUND LAYER, never a pseudo-element (a notched button's face
   is already `::after` and its hairline `::before`). **A state that recolours a button
   sets `background-color`, not `background`** — the shorthand drops `background-image`
   and kills the sheen. All three filled variants **invert on hover** over `--t-invert`
   (400ms), border and `--cut-line` holding colour; declared once for all three.
3. **Geometry is machined.** Radius 0–3px. Character comes from the notched corner
   (`.cut`), never rounding. The corner tick (`.ticked`, `.card--select`, `.slot--filled`)
   marks a box as one you have, and marks nothing else.

**Dark mode only.** No light theme, no `prefers-color-scheme` blocks.

### Non-negotiables

- **Style through tokens, never literals.** No hex, no px font sizes, no ad-hoc spacing
  in component CSS or `style=""`. Use `--bg-*`, `--ink-*`, `--s-1`…`--s-8`, `--fs-*`.
- **Semantic aliases, not the palette.** `--ok --warn --crit --info` (and `-a` tints) for
  anything meaningful. Raw names (`--jade`, `--brass`, `--ember`, `--steel`) are
  decoration only: rarity tiers, brand mark, focus rings.
- **Drive state with custom properties, not classes.** `<div class="meter" style="--v:.72">`
  — a 0–1 ratio applied as a transform, so it animates without layout.
- **`.cut` is two clipped pseudo-elements** (clip-path eats a real border). A child with
  its own background squares the corners — that needs `.cut--clip`, opt-in because it
  also clips focus outlines.
- **A new `.btn--*` variant needs a `.btn--*.cut` companion, always.** `.cut` paints the
  face with `::after` and `--cut-fill` above the element's own background, so a variant
  that only sets `background` renders as an ordinary button with tinted text. Set
  `--cut-line` and `--cut-fill`.

### Responsive rules — all of these shipped broken once

The user tests on an Android phone. Audit every layout at **320px and 360px**. There is
no browser here, so this is done by reading.

- **Never a bare `1fr` grid track** — always `minmax(0, 1fr)`.
- **Cap every `minmax()` floor**: `minmax(min(260px, 100%), 1fr)`. Usable width inside a
  panel at 320px is ~238px.
- **Fixed widths get `min(Npx, 100%)`.** Same for `min-width`.
- **Any flex row of chips, buttons or metadata needs `flex-wrap: wrap`.**
- **Inline `style=""` grid tracks beat media queries** — add a modifier class to the kit
  instead (see `.vital--tight`).
- **Watch paint order.** Non-positioned siblings paint all backgrounds, then all text, so
  a sibling's text crosses a chip's background regardless of DOM order. Anything meant to
  sit above an overflowing sibling needs its own stacking context (see `.ticker__tag`).

### Security

`UI.log()` inserts text as **HTML**. Anything player-supplied goes through `UI.esc()`
first, as does any markup built by concatenation.

### The page frame: rail left, numbers on top

`layouts/app.blade.php` is a `.shell`; the split between its halves is the point.

- **The rail (`.dock`) holds everywhere you can go**, plus sign-out. **One item may stand
  for several screens** — Character is current for the sheet, skills, implants and
  achievements alike, because to a player they are one place. **A rail item never
  points at a row id** — *Deck* links to `deck.index`, which resolves which deck you mean
  and redirects, or sends you to the stash. Icons at rest; opens to icon + label on hover
  where there is a pointer (`@media (hover: hover)`), on `data-dock-toggle` everywhere
  else, and on `:focus-within` — so closing it must take focus out with it.
- **It overlays, never reflows.** `.shell--docked` pins it to the viewport; a bare
  `.dock` is an ordinary column (which is what makes it demonstrable in the gallery).
- **The icon column stays `--dock-w` wide in both states**, and the active marker is a
  pseudo-element, so nothing moves sideways.
- **The bar is where status lives, and width is the whole constraint.** Mark, two
  resources, money, count of what is running. Resources are `.dial`s (40px rings) because
  three 104px gauges left no room for the fourth. Money is `.chip-stat` (`¤` + number).
  **Beside the running count sits *Current processes* (U+25CD, no number): a modal whose body is the fragment `processes.current`, fetched when it opens (`[data-fragment]` in `ui-kit.js`) and built by `App\Characters\CurrentProcesses` from the thing that owns each clock (scans, a city activity, decryption, training, drugs in effect, withdrawals, accepted jobs' deadlines) plus a *Clocks* group (the market's restock, the next point of SI mend, a trained regeneration; the next-run moments come from `config/cadence.php`, which `routes/console.php` reads too), each a `.meter` whose time left is its `data-tip`; read only, writing nothing but the decryption settle.** Anything added has to pay for itself at 320px or go to the rail. `.brand__name` is its
  own element so CSS can drop it below 560px. The bar is `.topbar--stuck`: **sticky, not
  fixed**, so nothing below reserves a matching strip.
- **A dial is a meter, not a second component** — same `--v`, `--fill`, `data-hot-low`,
  same `ui-kit.js`. Use a bar wherever there is room.
- **`creds` has no bar because it has no maximum.** The two resources come from
  `Character::RESOURCES`; `Character::resourceRatio()` is their `--v`.
- **What counts as "low" lives in `ui-kit.js`.** A server-rendered bar carries
  `data-hot-low` and `--v`; the kit runs the same test on load. `.meter--hot` paints the
  fill element, not only `--fill`, because an inline colour out-specifies a custom
  property.
- **Every glyph in the rail is from the set proved to render** in the kit's font stack —
  same discipline as `Item::glyph()`. Measure candidates; the missing ones come back at
  notdef width.

### Images

**Pictures are found by convention, never stored on a row.** `public/images/<kind>/`:

| File | Is |
| --- | --- |
| `<id>.jpg` | what a card shows. **16:9**, source ~**640×360**. |
| `<id>_full.jpg` | what the modal opens. Any dimensions. |
| `_default.jpg`, `_default_full.jpg` | the per-kind fallback. |

Nothing is required: no picture, no type default and no default above it renders no image
rather than a broken one. `App\Support\Image` resolves, `<x-entity-image>` renders.

- **Stamped through `Asset::url()`** like every other public asset.
- **The card image is a fixed 16:9 and crops** — the card is a slot the picture fills.
- **The id is validated as one path segment**, as is every segment of the kind. One bad
  step refuses the whole path.
- **`data-open` calls `preventDefault()`** — an opener can sit inside a `<label>` (the
  archetype cards), and looking at a picture must not also pick the thing.
- **A kind may be a path; items are one subdirectory per TYPE** — `items/cpu/`,
  `items/icebreaker/`, `items/imprint/`, `items/physical/`. `Item::imageType()` names
  it, `Item::imageTypes()` is the list composed from `Hardware`, `ProgramKind`, the imprint
  and the two inventories, so a new kind brings its folder with it.
- **An imprint calls itself data and files itself under `imprint/`** — one imprint per
  skill, one picture for all of them.
- **Only the default walks back up the path.** An item's own picture is looked for in its
  type's folder and nowhere else.
- **A missing full version means no modal**, which is why `card()` and `full()` are
  separate questions.
- **Where a card gets CLONED, the enlarge dialog is rendered beside the listing** —
  `<x-item-image>`, same shape as `<x-item-destroy>`: a `<dialog>` cloned into the
  inspector would be a second copy of itself in the top layer. `<x-entity-image>` renders
  it inline unless a `dialog` id says otherwise; the markup lives once in
  `<x-entity-image-dialog>`. The id is keyed by the inventory ROW.

### Cache busting

**Both layouts load the kit through `App\Support\Asset::url()`, never bare `asset()`.**
It appends the file's mtime as `?v=`. There is no bundler, nothing renames a file when it
changes, and Apache sends no `Cache-Control` for `public/assets` — so a browser holds
`ui-kit.js` for as long as it likes. mtime rather than a hand-kept number, because a
version someone must remember to bump is wrong exactly when it matters.
`tests/Feature/AssetVersionTest.php` holds the rule. `public/ui-kit.html` is static and
links bare on purpose.

### Link unfurls

**`layouts/social-meta.blade.php`, included in both layouts' `<head>`**, gives a shared link its Open Graph and Twitter tags: the
title is the page's own `<title>` plus ` - A cyberpunk browser game`, the picture is `public/images/square.jpg` (640×640, so
`twitter:card` is `summary`), through `Asset::url()` and absolute because a crawler has nothing to resolve a relative URL against.
`og:description` and the tagline are `strings.unfurl.*`.

### Fonts

**`--font-mono` is IBM VGA 9x16** (the int10h "Plus" woff, with IBM Plex Mono behind it for glyphs it lacks). It is a bitmap
in outlines: crisp only at **16px or a whole multiple, with `line-height` equal to the size**, so `.ascii`
(and `.input--mono`, `.textarea--mono`) set 16px/16px, no tracking, kerning or ligatures, `white-space: pre`. Do not give a mono
element a negative `letter-spacing` or a fractional size; the font has one weight, so `font-weight` changes nothing. Small chrome
(tags, badges) still sets `--fs-xs`/`--fs-sm` and is scaled, softer than at 16px. Attribution: `LICENSE-IBM-VGA-9x16.txt`.

**Every face is self-hosted and declared in `ui-kit.css`.** No font request leaves the
origin — the layouts carry no `<link>` and nothing to preconnect to. Overpass is one
variable file per subset; IBM Plex is static instances. Each face splits `latin` /
`latin-ext` by `unicode-range`. Both SIL OFL, licences beside the files. **Adding a
weight means adding a file and an `@font-face` block** — keep the set small.

## Game mechanics

**`MECHANICS.md` is the reference for anything producing or altering a number.** Read it
before adding an item, skill or effect. Short version:

- Every modifier is data, never PHP — a `config/` entry or a row. If new content needs
  new PHP, the design has leaked; fix the op set.
- One pure resolver does the arithmetic, fixed order: `add` → `percentage_of_base` →
  `multiplier` → `override` → clamp → round.
- `percentage_of_base` applies to **base + flat**; percentages are **summed then applied
  once**, never compounded.
- **Clamp and round once, at the end.**
- Derived stats are never stored; resources are stored and clamp down (never scale) when
  their maximum drops.

MECHANICS.md's status table says which parts exist — check it before calling anything
working.

### Rolling a check

**`App\Checks\CheckRoller` is the only die in the game**, separate from
`App\Stats\Resolver` so a screen can ask what a character's chances ARE without spending
them.

- **A logistic on the MARGIN** — `1 / (1 + exp(-(value - difficulty) / spread))` — so
  only the gap counts and equal value and difficulty is a coin flip.
- **`checks.spread` is how decisive the whole game is, in one number.** Unmeasured.
- **No check is ever certain.** Clamped into `[floor, 1 - floor]`. A kind may name its own
  floor in `checks.floors` — **config, never an argument**.
- **`chance()` is public and separate from `roll()`**, so a warning and the roll cannot
  disagree.
- **The die is injected.** Pin it in tests.

### Where a modifier comes from

**`Character::modifiers()` is the whole list; each entry is a `ModifierSource`.** Seven
today (`WornArmour` is the seventh, under *Physical combat* in `docs/fatigue-and-combat.md`), and the resolver learns nothing new when an eighth arrives.

| Source | Is |
| --- | --- |
| `ArchetypePerks` | the role's live perks |
| `RunningPrograms` | software running on the equipped deck |
| `SkillEffects` | `skills.modifiers` |
| `InstalledImplants` | `items.modifiers` |
| `CharacterModifiers` | injuries and statuses, `character_modifiers` table |
| `ActiveDoses` | drugs in effect, `character_doses` table |
| `WornArmour` | worn armour's damage modifiers, wrapped in `hit_location_in` |

- **A thing with a row carries its modifiers ON that row** — hence no
  `config/implants.php` and no `skill_effects` table. **`config/effects.php` is only for
  causes with no row of their own**: injuries, statuses, lingering effects.
- **Definition keys in `config/effects.php` are permanent.** Retire with `retired => true`.
- **A per-instance amount is the string `':amount'`**, filled from the row's `data`.
- **Stacking belongs to the DEFINITION and collapses at READ time.** `App\Stats\Stacking`
  has four answers. Never at write time — "the strongest wins" stops being true the moment
  the strongest expires.
- **`App\Stats\InjuryLedger` is the only writer of an accumulating injury**, and it
  DEEPENS one row rather than adding a second (-5 plus -3 is one row at -8). A row that
  reaches zero is deleted.
- **Resolution can be asked AS OF a moment.** `TrainingDeposit` cuts the segment at every
  modifier change; `CharacterModifiers` filters rows to that instant. Sources that cannot
  honestly look back say so — nothing records when a program STOPPED.
- **Damage is a stat too, and its base arrives with the event.** One stat per resource and
  origin (`damage.synaptic_integrity.ice`), generated from two enums by
  `App\Stats\DamageStats`, tagged with both axes plus `damage` — so a resistance is
  `percentage_of_base -15` against `all_of: ['damage', 'ice']`, immunity is
  `multiplier 0`. `DamageResolver` is the only door; `taken` is for saying out loud, never
  for deciding whether anything happened.
- **Build a `Sheet` and read it; do not call `modifiers()` per stat.** Most of the
  sources query. A sheet is bound to its context and cheap to rebuild rather than hold.
- **Pools are not formulas.** A formula divides each attribute, rounds each up and adds; a
  pool adds first and divides once. Pools are kept out of `stats.formulas` because that
  array is shipped to character creation and recomputed in JS (one shape, asserted by
  `DerivedStatTest`). They are in the stat graph all the same.
- **`stats.bases` is the third map `Sheet::baseFor()` walks**, for stats that are neither
  attribute-derived nor stored (`routing_hops`). Older stats of that family
  (`icebreaker_damage`, `scan_duration`) still hand a base in at their call sites.
- **"Per level of something else" is FIVE MODIFIERS**, each conditional on one more level
  — a modifier's amount is a number, never an expression. `skill_at_least` is the
  predicate; `Sheet::contextFor()` puts levels into the context as `skill.<slug>`, for the
  skills a character actually has (absent and zero are the same answer).
- **Hit points and Synaptic Integrity are carried to TWO DECIMALS** (`current_hitpoints`, `current_synaptic_integrity`
  are `DECIMAL(8,2)`; `hitpoints_max`, `synaptic_integrity_max`, both regens and every `damage.*` stat round to a
  `hundredth`). **`App\Support\Points::of()` is the one rounding, and a whole figure is an `int`** (a fraction the only
  thing that is a float), so every `=== 0` and `assertSame(30, …)` still holds. `Character` casts them through
  `PointsCast`; `takeDamage()`/`recover()` take and return `int|float`. **Never `(int)` a resource, a maximum or a
  taken amount** — it silently drops the fraction and puts somebody on 0.4 HP on the floor. Creds stay whole: a clinic
  bills `ceil(points × price)`. The regen command works in hundredths so an hourly rate is exact.
- **ICE and icebreakers are carried to two decimals too** (`ice.integrity/attack`, `runs.ice_integrity/ice_attack/ice_damage`, `hunts.ice_integrity` are `DECIMAL(8,2)` through `PointsCast`; `icebreaker_damage` rounds to a `hundredth`; `CriticalHit::scale()` and the fight code use `Points::of()`), so a skill's few percent is not rounded away. A fortress's kernel (`kernel_integrity`, burned by `Simulator`) is still whole.
- **Any pool stat asked for outside a Sheet reads as zero.** `Resolver::resolve('stealth',
  0.0, …)` returns modifiers and nothing else. `Character::stat()` is the way in.

## The Laravel application

Laravel 13, `laravel/fortify` for auth, `laravel/tinker`, **no Vite and no npm** — the kit
is build-free. Do not add a bundler.

### Laravel 13 specifics

- Models declare `#[Fillable([...])]` and `#[Hidden([...])]` as **PHP attributes**. The
  old properties do nothing.
- No `app/Http/Kernel.php`. Middleware goes in `bootstrap/app.php` via
  `->withMiddleware()`, providers in `bootstrap/providers.php`.
- `laravel/agent-detector` probes paths like `/opt/.devin` at autoload-dump time — which
  is why `open_basedir` had to move off the CLI.

### Player-visible strings

**The game speaks English only.** No `lang/`, no locale to resolve, and `__()` /
`trans_choice()` are not used. Do not reintroduce them.

Every player-visible string lives in exactly one of two places:

- **On the row, if the thing has a row** (an item's `name` and `flavor`, a skill's
  `title`). A definition half in the database and half in a file is two things to keep in
  step.
- **In `config/strings.php` otherwise** — UI chrome, auth screens, refusals, captcha
  labels, the tier vocabulary. Read through `App\Support\Text` (config lookup plus
  `:placeholder` substitution).

- **Keys name the role, not the text** — `ui.action.jack_in`.
- **Counted strings** are `{zero, one, many}` read by `Text::plural()`.
- **`Text::get()` returns the key** when missing.
- **An imprint has no name of its own** — it composes via `items.imprint.name`.
- **Archetypes carry their strings on their rows.** Perk labels live on `archetype_perks`,
  except where a perk borrows an attribute's name — those are null and read
  `character.names.<key>`.
- **Framework strings that reach a player** are swapped by the exception renderers in
  `bootstrap/app.php` from `strings.errors.*` (Laravel throws a raw `'Too Many Attempts.'`
  and vendor code cannot be edited). **Carry the original headers through** — `Retry-After`
  and `X-RateLimit-*` are the machine-readable half of a 429.
- **`config/app.php` still sets `locale` and `fallback_locale`, and they stay** — vendor
  validation and auth messages are keyed by them.

### Auth

Fortify, with **all views written by us** in `resources/views/auth/`. Two-factor and
passkeys are **disabled** in `config/fortify.php` because their screens do not exist; the
tables are already migrated.

**One character per user, enforced by two middlewares:**

- **`RequireCharacter`** on every web request: a signed-in player without a character goes
  to the creation screen, except the routes it names (creation form, logout, handle pool)
  and anything under `admin.`.
- **`ForbidSecondCharacter`** on those two creation routes: a player who has a character
  is sent to the run screen. **It guards the POST as well as the form** —
  `characters.user_id` is unique, so an unguarded second submission is a 500. A safe
  request is redirected silently; a write says why.
- **An admin may still *look* at the creation screen**, with or without a character — it
  is a screen they maintain. The POST is refused for everyone who already has one. The
  middleware marks the request (`ForbidSecondCharacter::PREVIEW`) and the view renders a
  notice and **no submit button** (a disabled one would be re-enabled by the pool updater).
- Both answer a JSON caller with **409** and a URL, not a redirect a fetch would follow
  into HTML.

### Captcha

**Cap** ([trycap.dev](https://trycap.dev)), self-hosted in the `cap` service. Guards login,
registration and password-reset request.

- **Adding it to a form is one tag: `<x-captcha />`.** `ui-kit.js` upgrades every
  `.captcha` into `<cap-widget>`, reading endpoint and labels from the two globals
  `<x-captcha-runtime />` emits. Inside a `<form>` the widget injects its own hidden
  `cap-token`.
- **Protecting a route is one line in `config/cap.php`** — the `protect` list holds route
  names, read by `App\Http\Middleware\EnsureCaptchaIsSolved`. Middleware rather than a
  validation rule because two of the three routes are Fortify's.
- **The widget is a challenge, not a gate.** Nothing is protected until `App\Captcha\Cap`
  has POSTed the token to `/siteverify`.
- **Every failure mode fails closed** — unreachable, error status, forged token, and
  *enabled but missing credentials*.
- **Off by default** (`CAP_ENABLED=false`); the component renders nothing while off.
- The widget lives in an **open shadow root** — the `--cap-*` custom properties are the
  whole styling surface, mapped onto tokens in `ui-kit.css`. Cap's 14px radius is
  overridden.
- **The widget and its WASM are vendored** in `public/assets/js/cap-widget.js` and
  `public/assets/wasm/`, because Cap Standalone serves neither and the widget would fetch
  WASM from jsDelivr. The file header says how to update. One third-party call remains: a
  `pako` fallback on browsers without `DecompressionStream`.
- **Two endpoints.** `CAP_ENDPOINT` is the browser's (`localhost:3010`),
  `CAP_VERIFY_ENDPOINT` the container's (`cap:3000`), defaulting to the first.
  `CAP_SECRET` is the site key's secret, never `CAP_ADMIN_KEY`.

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

**Read that file before touching:** Fatigue and Safehouses (phase 1 built — `FATIGUE.md`); Physical combat (in progress — `COMBAT.md`).

## Archetypes, inbox → `docs/archetypes-and-inbox.md`

**Read that file before touching:** Archetypes and what they hand out; The inbox.

## Admin tools → `docs/admin.md`

**Read that file before touching:** Admin tools.

## Events

Three events, and **one listener**: `App\Listeners\NotifyOfSkillLevel` turns
`SkillLevelReached` into an inbox message. Wiring is standard Laravel
(`Event::dispatch()`, auto-discovered from `app/Listeners`). `QUEUE_CONNECTION=sync` runs
listeners in-process, which is right while the only one does a single insert — switch to
`database` or `redis` before any listener does slow work. See TODO.md.

## Templates

Blade. `layouts/app.blade.php` is the signed-in chrome, `layouts/guest.blade.php` the centred
panel used by auth. Views compose UI kit classes; they do not define their own CSS.

## Versioning and releases

**Git tags are the source of truth; `CHANGELOG.md` is the human record.** No release tooling
and none wanted — `standard-version`, `changesets` and `semantic-release` are all npm.
Composer's `version` field is deliberately absent.

SemVer, and **pre-1.0 is meant literally**. While the major is 0, the line is the schema:

- **Minor** — the release changes the database and a deploy needs a migration.
- **Patch** — everything else, however large. If a deploy is `git pull` and nothing more, it
  is a patch.

Three places carry the number and are bumped together:

| Where | For |
| --- | --- |
| `git tag -a vX.Y.Z` | the source of truth |
| `CHANGELOG.md` heading | what changed and why |
| `config/version.php` | what a *running* container can report |

The last is the only copy that can drift — bump it in the changelog commit.

### Patch notes

**`PATCHNOTES.md` is the players' changelog**, written by hand in plain words;
`CHANGELOG.md` stays the technical record. One `## X.Y.Z (YYYY-MM-DD)` heading per
release (a trailing colon is allowed), read by `App\Support\PatchNotes`, rendered with
raw HTML stripped.

- **What is new is decided by the newest NOTE, never the app version** — a release
  nobody wrote a note for opens nothing.
- **Signed in only.** A signed-in player sees everything newer than
  `users.last_seen_changelog` in a modal that opens by itself; NULL sees the latest
  release alone. The version on the front page reopens every note.
- **Seen is written on CLOSE**, by a POST the dialog sends however it is closed
  (`data-show-on-load` / `data-post-on-close` in `ui-kit.js`), and it never moves
  backwards.

### Cutting a release

**Work on `main`; `bin/release [X.Y.Z]` does the branching, changelog, tag and merge, and
never pushes** (`--help` has the flags). Push with `git push origin main --follow-tags`.

- **When Claude is asked to cut one**: Claude writes both entries to files, and the user
  runs `bin/release X.Y.Z --changelog FILE --patchnotes FILE` in their own terminal —
  nano (patch notes) cannot open inside Claude's shell.
  The changelog file must start with its own `## [X.Y.Z] — YYYY-MM-DD` heading (the script dies without it); the patch notes file is the body only, no heading.
- **Patch notes are for players**: short and plain, no class names, migrations or reasoning.
- It refuses to rewind a `main` that has been pushed (`git fetch` first, or `--no-branch`),
  and a patch release that adds a migration must be confirmed.

Afterwards, run the migrations the changelog named and any seeder they need.

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

## Dependencies

Laravel 13 with **Eloquent**. Use `Log::` and `config()`, not Monolog or phpdotenv directly.

`roave/security-advisories` is installed, so a `composer require` pulling a package with a
known CVE fails. Resolve by upgrading, never by removing the constraint.
