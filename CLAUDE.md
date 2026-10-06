# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

A cyberpunk browser game. PHP 8.4 + MySQL, Laravel 13, developed entirely in Docker.
Two systems are already binding: the Laravel app and the **Ash & Ember** UI kit. Read
both before writing code.

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
there — the path, the user, where errors go. See **Deploying**.

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
| `public/assets/fonts/` | Overpass and IBM Plex, self-hosted (SIL OFL). |
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
  Anything added has to pay for itself at 320px or go to the rail. `.brand__name` is its
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

### Fonts

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
today (`WornArmour` is the seventh, under *Physical combat*), and the resolver learns nothing new when an eighth arrives.

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

### Inventory and imprints

**Two inventories, and one gate in front of every skill.**

- `items` holds definitions, `inventory_items` what a character carries — one row per
  (character, item) with a `quantity`. Item definitions are in the **database, not
  config**; `config/items.php` does not exist.
- **`ItemSeeder` seeds no items.** It generates one imprint per skill. A hardcoded
  catalogue would be a second source that wins on re-seed. Author items in the editor; a
  seeder may only write rows *derived* from another table.
- **`container` is a property of the item, never a player choice.** `physical` and `data`
  are the two inventories; `Character::inventoryIn()` filters through the item.
- **A `character_skills` row means the skill has been injected**, and
  `App\Inventory\SkillInjector` is the only thing that creates one — consuming one copy of
  the imprint and writing the row at 0 points, level 0, in one transaction.
- **`TrainingDeposit` does not create that row** — it refuses and logs. Owning and
  spending the imprint is a hard gate.
- **Level 0 is a real state** (injected, untrained), so the gate is
  `Character::hasInjected()`, never a level check.
- **Injecting a skill already known is refused**, and the player keeps the imprint.
- **Items can hold items.** `capacity > 0` plus an `accepts` kind is a container — the
  memory shard is a `physical` object that `accepts` `data`. `container` says what kind of
  thing an item is; `inventory_items.parent_id` says where a row sits.
- **`parent_id` null means loose.** Every listing meaning "the player's inventory" needs
  `InventoryItem::scopeLoose()`.
- **Depth is capped at one**, so there are no cycles and nothing walks a tree.
  `App\Inventory\ItemPlacer` is the only mover and enforces ownership, kind, capacity and
  the cap.
- **Destroying a container destroys its contents** — `parent_id` cascades.
- **Two copies of an item are not interchangeable.** The old unique key on (character,
  item) is gone, replaced by a nullable unique `stack_key`: null for unstackable items
  (nulls are distinct in a unique index, so each object gets a row) and
  `character:item:parent` for stackable ones. **Derived on every save by the model, never
  set by hand.** A generated column cannot read `items.stackable`.
- **The stash is a slot grid, and the detail card is the only place words are.** One
  `.slot` per *loose* row, deliberately unpadded (neither inventory has a capacity).
  Everything else lives in one `<x-item-detail>` rendered into a `<template>` beside the
  slot; `data-inspect` clones it into the floating `.inspector` on hover and a modal on
  click. `[data-act]` marks what appears only in the modal — a hover layer is not
  somewhere a click can land. **A row inside a container gets no slot**, so its buttons
  live on the container's card.
- **An item's actions live on its card.** **Do not put them behind a right-click** — no
  mobile story, nothing announces it, and extensions eat `contextmenu`.
- **An action that does nothing yet says so with a `.tag`** rather than being absent.
  *Show on market* and *Disassemble* are those; *Fit to deck*, *Take out of deck*,
  *Board*, *Inject*, *Store* and *Destroy* are real.
- **Every surface that lists an item needs a route to its card** — the stash slot, the
  equipped deck's "in use" row, a filled socket's dialog. All through `data-inspect-open`.
- **The destroy confirmation is rendered beside the listing** — `<x-item-destroy>`, for
  the cloned-`<dialog>` reason above.
- **Destroying is real and irreversible; the confirmation is the only brake.** The dialog
  spends what the delete reaches: contents cascade with a container, a deck's modules do
  NOT and come back loose, destroying the equipped deck leaves you with none. No writer
  class — one rule, already in the controller.
- **`ModuleFitter::fitToDeck()` picks the socket**, since *Fit to deck* is the player
  choosing the module and not the place. It distinguishes "no socket takes it" from "every
  socket that takes it is full". **There is no skill check** — when one exists, that
  method is where it goes.
- `size` is enforced against a container's capacity, and for DATA against the equipped
  deck's storage (see *Programs*). `weight` is stored and unenforced; the physical
  inventory has no overall capacity.

### Skills, and the training queue

**`/skills` is a character's own skills and nothing else.** The public catalogue (and
`SkillTree`, `SkillTreeNode`, `<x-skill-node>`) is gone. The route is behind `auth`, so
`RequireCharacter` guarantees a character.

**HOW A PLAYER FINDS OUT A SKILL EXISTS IS THE MARKET.** One imprint per skill, so the shelf
IS the list — and deliberately not the whole list: **a skill off the market is content
found rather than bought**, and `Imprint` keeps `on_market` in step with the skill on
every save. The skill EDITOR is the only place all of them are visible.

- **`App\Skills\TrainingQueue` is the only writer a PLAYER reaches.** `TrainingDeposit`
  writes these rows too, but that is the clock moving rather than somebody deciding.
- **A player picks a SKILL and never a level.** An entry is a (skill, target) pair, targets
  must be consecutive, so the next level up — counting what is QUEUED, not only reached —
  is the only acceptable entry. Pressing the button five times takes a skill to level 5;
  two presses computing the same level would collide on the unique (character, skill,
  target_level) index. Locked for the duration.
- **The picker offers exactly what the writer would accept**, doing the same sum in
  `SkillsController::queueable()`.
- **A PLAN IS SEVERAL PRESSES OF THAT BUTTON, never a second door.**
  `App\Skills\TrainingPlanner` works backwards from a skill and the level somebody
  WANTS to the entries that reach it — prerequisites first, then one entry per level in
  between — and `TrainingQueueController::plan()` feeds them to `enqueue()` one at a
  time. The planner decides the ORDER and the COUNT and nothing else, so every rule about
  what may go into a queue still lives in one place and `TrainingQueueValidator` judges
  the result exactly as it judges a hand-built one.
- **A prerequisite is per SKILL, not per level**, so everything a skill requires stands in
  front of its first level and nothing interleaves. That is what makes the plan a flat
  list rather than a schedule.
- **Stopping part-way is the ORDINARY outcome, and it is a status rather than an error.**
  Nine entries into a queue that holds five, or a route through an imprint that was never
  spent: the loop stops at the first `QueueRefusal`, keeps what landed, and names both the
  skill it stopped at and why. A plan is never refused as a whole.
- **The plan picker offers only INJECTED skills**, like every other list on this screen —
  one naming every skill in the game would be the catalogue this page deleted.
- **An unmet prerequisite is a WARNING, never a refusal.** A queue may describe training
  that cannot happen yet — that is what lets Codebreaking sit behind Hacking.
  `TrainingQueueValidator` says what will stall; `QueueRefusal` has three cases and none is
  about requirements.
- **Removing one is allowed to break the rest**, deliberately. **The deposit runs first**,
  inside `remove()`, because removing position 1 throws away its clock.
- **Renumbering is ascending** — `(character_id, position)` is unique, so each row moves
  into a slot the one before has vacated.
- **The estimate is the rate it earns THIS INSTANT.** Modelling future modifier changes
  would be a second, worse copy of `TrainingDeposit`'s segment walk.
- **What is training wears `.dot--live`** — ember, one per screen, and at most one entry
  trains at a time by definition.

### The market's supply side

**The market restocks itself, and now it sells.** See *Buying* below for the demand
side; this is where the stock comes from.

- **`rarity` is not `tier`.** Tier is what an item IS (Tier One / Tier Two), colours the
  card through `.t1`/`.t2`, carries no mechanics. Rarity (`common`, `uncommon`, `rare`,
  `ultra_rare`) decides whether a restock pass delivers. They may disagree.
- **An item is stocked only with `on_market` AND `seed_quantity > 0`.** The default shelf
  size is 0, so nothing reaches the market by being forgotten. `Item::restockable()`.
- **`on_market` is the SEEDER's question and will not be the player's.** Player listings
  will be a table of offers, not this flag. **A listable imprint is an UN-INJECTED one, and
  that needs no rule** — `SkillInjector` consumes the copy it spends.
- **`market_stock` is one row per item; a missing row is a shelf of zero**, not an error.
  `Item::stockOnHand()` treats absent and empty alike.
- **`market_purchases` is demand, not a receipt.** Immutable, no `timestamps()` —
  `purchased_at` is the moment. `App\Market\MarketSeeder` sums a seven-day window per item.
- **The pass tops up TO a target, it does not add.** Leftover counts. Demand above half a
  shelf scales the target, capped at 4×. Formula and reasoning in `config/market.php`.
- **The rarity roll is all or nothing** — one roll per item per pass.
- **`market:seed` runs every eight hours** (`routes/console.php`) and is **not** the
  training heartbeat: no stored clock, a missed pass is never made up, a pass run twice is
  two deliveries — hence `withoutOverlapping()`. `--dry-run` prints and writes nothing.
- **The roll is injected** (`MarketSeeder::fromConfig(fn () => …)`).

### Buying

**`App\Market\Purchaser` is the only thing that spends creds AT THE SHELF** (every creds
change goes through `App\Wallet\Wallet` — see *The wallet*), the only
writer of `market_purchases`, and the only thing besides `MarketSeeder` that touches a
shelf. Money existed for four releases with nothing to spend it on.

- **The price is the ITEM's, never the shelf's.** `market_stock` carries no price on
  purpose, so a shelf row cannot drift out of step with the catalogue it sells from.
  `items.base_cost` is what a thing costs; **`market_purchases.unit_price` records what it
  cost that day**, which is the only copy that has to survive a retuning.
- **One transaction, and the shelf is locked inside it.** Two people reaching for the last
  one is the case it exists for: the stock row is locked, the count re-read inside the
  lock, and the money and the goods move together or not at all.
- **Asking for more than is left is REFUSED, not quietly reduced.** Somebody who asked for
  five and could have had two did not decide that.
- **Withdrawn from sale is the same answer as never stocked.** `on_market` is the seeder's
  question, and an item that stops being on the market stops being buyable the moment it
  does — checked before the shelf is even read.
- **A purchase row is demand, not a receipt**, so writing one is half the reason the class
  exists: `MarketSeeder` sums a window of them per item and stocks more of what sells.
- **`App\Inventory\ItemGranter` is how a catalogue item becomes a possession**, shared by
  the archetype loadout, a raid's haul and the market. Stackable merges into one pile,
  unstackable becomes one row per copy, and everything lands LOOSE — which container it
  goes in is `ItemPlacer`'s question and the player's choice. It was written out twice
  before the market would have made it three times.
- **The shelf screen lists `market_stock` rows with something left**, never the catalogue
  with counts laid over it: an item nobody can buy today is not something a shop is
  holding. Buy is an ordinary button and never `btn--go` — the green one is rationed to
  one per screen and a shelf of twenty would say nothing.

### Cyberdecks and modules

**A cyberdeck is an item, and so is every module.** One column says which; two tables carry
what a bare `items` row cannot.

- **`items.hardware` is the only place the kind is written down** — `deck`, `cpu`, `npu`,
  `ram`, `nic`, `expansion`, `psu`, or NULL. `deck_modules` carries numbers and *not* the
  word.
- **`deck_slots` is one row per socket, not a count per kind** — a fitted module points at
  the socket it went into, and a count cannot be pointed at. `App\Decks\SlotLayout` is the
  same set as counts, and pure (the editor needs a layout for an unsaved deck).
- **`App\Decks\SlotType` is the whole vocabulary**: fourteen (kind, variant) pairs. A
  fifteenth socket is an enum case and a label.
- **A cap belongs to the kind, not each variant** — five expansion slots means five
  altogether. Not a CHECK constraint (sockets are separate rows);
  `ItemRequest::withValidator()` holds it, with "a deck needs a CPU socket" and "a deck
  needs a power socket".
- **`universal` is a socket and never a module.** The two sides validate against separate
  lists and fit is `Variant::accepts()`, never `===`.
- **An IO port seats nothing** — electrode and neuralink ports are rows like any socket.
- **At most one measure per module** (`chk_deck_modules_one_measure`): qFLOPS, quantabits,
  QB/s and watts do not convert. An expansion card fills none.
- **A supply gives power and draws none** (`chk_deck_modules_supply`).
- **`App\Decks\HardwareEditor` is the only writer**, and it always clears the side an item
  stopped being. **It diffs sockets, it does not replace them** — a wholesale replace would
  empty every player's deck whenever an admin touched an unrelated field. The diff is by
  type, and when there are too many it drops EMPTY sockets first; removing an occupied one
  returns the module to its owner.
- **A module's spec line never says what it is.** `hardwareSummary()` gives the numbers;
  `Item::kindName()` answers what it is — deck hardware says which piece, everything else
  falls back to its inventory (`items.kinds.*`).
- **A deck's board is a picture, and it lives on the item.** `items.board_layout` is a JSON
  map from `SlotType::key()` to `[x, y]` percentages; `App\Decks\BoardLayout` turns it into
  nodes and wires. **A node is a type, not a socket** — four QDRAM sockets are one box.
  **NULL means unarranged, not missing**: the automatic ring draws it and stored
  coordinates are an **override on top of that ring**, so a partial arrangement is normal.
  `<x-deck-board>` is the one renderer; the admin's drag editor is the same component with
  `editable` flipped.
- **A deck has no thermal rating and heat does not happen to it.** Power is measured
  against fitted supplies; heat is measured against nothing at deck level. Do not add a
  chassis cap. On screen heat's meter ends at the highest resolved LIMIT anything fitted
  carries (`ThermalExposure::scale()`) — a scale, not a rating — with every module's
  warning and limit ticked on it (`.meter__mark`). No module with a limit, no meter.
- **Every module makes heat** (`deck_modules.heat`) and a **radiator takes it away**
  (`Hardware::Thermal` in a `thermal` socket, measure `cooling`). A radiator cools and
  heats none, but still draws.
- **A module's heat tolerance is a pair of thresholds on the DECK'S heat** —
  `heat_level_warning` and `heat_level_limit`, nullable together, warning ≤ limit. **A
  deck's tolerance is its weakest part, not the sum**, which is why
  `App\Decks\ThermalExposure` is separate from `DeckTotals`. Both thresholds are resolved
  stats.
- **The two warnings are deliberately different volumes.** Over warning: an amber callout
  naming the parts. Past limit: `.hud-alert`, the only accent on the deck screen.
  `.slot--hot` pulses slowly on the board (a condition, not an event); `.slot--doomed` adds
  the crit fill.
- **Destroying modules in an overheating deck is NOT built**, nor the accumulation that
  would cause it. `DeckTotals::netThermalLoad()` is a RATE and nothing runs a deck over
  time. What exists is `ThermalExposure`'s static verdict (`ThermalVerdict::OverLimit`).
- **A module goes into a socket, and `deck_fittings` is that row** — `deck_id` (inventory:
  which deck), `deck_slot_id` (definition: which socket), `module_id` (inventory: what went
  in). **Only the pair is a place.** `App\Decks\ModuleFitter` is the only writer.
- **Fitting is not containment.** A container asks whether the bulk fits; a socket asks
  whether this is the thing this place takes. `parent_id` stays NULL for a fitted module —
  `InventoryItem::scopeLoose()` is what takes it out of the player's hands.
- **Destroying a deck frees its modules**, unlike a memory shard.
- **Modules do not stack** — a fitting points at an inventory row.
- **Equipping is not jacking in.** Jacking in is entering cyberspace; equipping is which
  deck you carry. Never one word for the other in a player-visible string.
  (`auth.login`/`auth.logout` still say "Jack in"/"Jack out" — pre-existing.)
- **An equipped deck IS in the stash.** `scopeLoose()` leaves out four things that really
  are elsewhere: inside a container, fitted into a socket, running, installed in a body.
- **`characters.equipped_deck_id` is which deck is carried**, NULL being ordinary. A column
  rather than a flag, so "one at a time" is not a rule anybody enforces.
  `App\Decks\DeckEquipper` is the only writer; equipping a second swaps rather than
  refusing.
- **Stripping destroys nothing**, so nothing confirms it. Unequipping DOES ask, because
  there are two right answers (leave it built, or strip on the way out). The two are
  independent in both directions.
- **`App\Decks\DeckTotals` is the ONE place a deck is totalled.** It sums by MEASURE, not
  socket — qFLOPS comes from a CPU or an NPU.
- **`App\Decks\DeckRequirement` says whether a deck will run** — compute, memory, link,
  power, draw within supply. One enum case plus one `match` arm each. **Nothing refuses a
  fitting because of it**, including one that busts the budget; the screen names what is
  missing.
- **A deck's measures are RESOLVED, not read off the row.** Five stat ids — `deck_qflops`,
  `deck_quantabits`, `deck_bandwidth`, `deck_power_draw`, `deck_power_supply`, all tagged
  `deck`. **The equipped deck resolves; every other deck shows bases**, and
  `DeckTotals::forCharacter()` is the one place that rule lives.
- **Readiness gates jacking in and starting a program, and nothing else.**
  `DeckTotals::isReady()`; callers are `Navigator::jackIn()` and `ProgramRunner::start()`.
  Fitting and equipping are never refused for it.
- **The deck is locked while you are jacked in.** Fitting, taking out, stripping,
  unequipping and destroying the deck or anything in it are refused — three writers
  (`ModuleFitter`, `DeckEquipper`, the destroy path) asking ONE question,
  `Character::isLockedInCyberspace()`. Only the EQUIPPED deck. **The lock guards what the
  PLAYER does and must never be asked by the game's own consequences** — a Surge or a
  burnout destroys fitted hardware precisely while somebody is jacked in.
- **Nothing a deck stores makes its owner better at anything yet** — the `ModifierSource`
  over fitted hardware that MECHANICS names is missing.
- **Every stored measure is a BASE.** Resolve through `App\Stats\Resolver`, never read the
  column; a deck's totals are per character and may never be cached.
- **A deck that cannot power every socket it presents is intentional.** Do not "fix" one.

### Programs

**Software is an item with a `programs` row**, as deck hardware is an item with a
`deck_modules` row. No column on `items` says which.

- **Three costs, paid at different times.** `storage_size` is paid by OWNING;
  `qflops_cost` and `memory_cost` only while it RUNS.
- **There is no "loaded".** A program runs or it does not. `background_process` says which
  it is ALLOWED to be; the refusal is `ProcessRefusal::RunOnly`.
- **Running is two columns on the inventory row** — `running_on_deck_id` and `started_at`,
  null exactly together — never a join table. It has to be on the row because `stack_key`
  is derived from the row and IDLE copies stack while RUNNING ones do not.
- **`App\Programs\ProgramRunner` is the only writer.** Starting takes one copy off the pile
  and gives it a row; stopping puts it back and MERGES (not optional — `stack_key` is
  unique).
- **Always the equipped deck**, which is why `start()` takes no deck argument. So
  unequipping stops everything, and so do fitting, stripping and destroying:
  `stopAll()` is called by `DeckEquipper`, `ModuleFitter` and the destroy path.
- **`running_on_deck_id` is RESTRICT, forced rather than chosen.** MySQL refuses SET NULL
  (error 3823) because the column is in a CHECK — rightly, since nulling the deck id would
  strand `started_at`. So the destroy path stops processes first, in the same transaction.
- **Compute and memory are measured against the deck's RESOLVED totals.**
- **The kind is asked about BY NAME, not derived from modifiers** — icebreaker, decryptor,
  evasion, analyser, daemon. A program is a gate as much as a modifier.
- **`programs.modifiers` is nullable JSON and null is ordinary.**
  `App\Stats\Sources\RunningPrograms` reads them, and **only the equipped deck's
  processes count**.
- **`ProgramEditor` is the only writer of the `programs` table** and clears the side an
  item stopped being. Nothing to diff — a process points at an inventory row.
- **How many copies of one program may run is `program_threads`** — one by default
  (`config/programs.php`), raised by Multithreading, and **a ceiling per program rather
  than a total**. `ProgramRunner` counts running rows of the same item across every deck.
- **Programs of one KIND diminish.** Every running program of one kind (two Surveyors, or a
  Surveyor and any other analyser) is a stack per op and target: the PROGRAMS are ranked
  strongest first (`Modifier::magnitude()` summed) and the Nth gives
  `stats.diminishing[N-1]` of what it does there — 100%, 86%, 57%, 26%, 10%, 3%, then nothing — via
  `Modifier::scaledBy()` (a multiplier scales its distance from one; an override is
  untouched; drawbacks scale too). The unit is the program, never the modifier — a
  program's five per-level grants do not penalise each other. Different kinds
  never discount each other. **This is the game's rule for things of one type stacking
  anywhere**: the next source that can hold two reads the same list.
- **`programs.multithreaded` decides whether more than one is a question at all** — a
  property of the software, false by default, exposed in the item editor.
- **Run-only software is the LOADOUT, chosen on the briefing.** `RunStarter::start()` is
  the only caller passing `$duringRun`, and it starts the loadout BEFORE the arrival roll
  (evasion that came up after the system looked hid nobody). A program that will not start
  refuses the whole run with its own reason and anything already started is put back.
  `RunStarter::end()` stops everything run-only (`ProgramRunner::stopRunOnly()`). **It
  cannot be changed inside a run.** Jacking in is NOT a run and must not pass `true`.
  **The last loadout that went in is remembered** in the `run_loadout` cookie, as ITEM ids
  (a row id does not survive its stack splitting and merging) and ticked on the next
  briefing. Per browser, deliberately — no column, no migration. A refused run is not
  remembered.
- **One icebreaker at a time**, whichever ones — `ProcessRefusal::OneIcebreaker`.
  `icebreaker_damage` sums every running program, so two cheap ones would out-cut a dear
  one and *twenty turns is never enough* would stop being true.
- **Storage IS enforced, on the equipped deck.** `App\Decks\Storage` is the one measure:
  every loose data row counts (programs at `storage_size`, a file at its own QB, anything
  else at `items.size`, idle, running or listed alike), a row inside a shard does not.
  Capacity is the resolved `deck_storage`, which starts from the deck's own
  `items.onboard_storage` (8 QB on an Explorer, 64 on the top three) — NULL falls back to
  `decks.onboard_storage` (16), and only a deck may carry one. No deck holds nothing. **Every door that adds data asks `fits()`** —
  market, player market, loyalty store, scan export, taking out of a shard, extraction —
  and refuses with its own `NoStorage`; the archetype loadout does not. Extraction leaves
  what does not fit ON THE SYSTEM, and files are all-or-nothing because the pull was
  priced on their total. **Nothing is ever deleted for being over**: a smaller deck may
  be equipped, and `DeckRequirement::Storage` then keeps it from jacking in or starting
  programs until the player makes room.
- Screens: topbar count + modal, deck screen's Running and Storage tabs (storage split
  into two grids), `inventory.run` in both directions.

### The character sheet

**One rail item stands where four did.** Skills, Implants and Achievements each had their
own, and are tabs of `/character` now (as is Standing, below) — because to a player they are one screen: who you
are, rather than what you are carrying.

- **THE TABS ARE ROUTES, NOT PANELS** (five now: character, skills, implants, standing,
  achievements). Each kept its URL, its controller and its
  tests; `<x-character-tabs>` is a nav of LINKS and the current one is `aria-current`,
  never `role="tab"` with `aria-selected` — promising a tablist and then reloading the page
  is a lie a screen reader acts on. A client-side window would run four screens' worth of
  queries to draw one panel, on the screen a player opens most.
- **`.tab[aria-current="page"]` is the kit's link form**, sharing the button form's two
  lines of paint.
- **The attributes are RESOLVED**, never the stored column — an implant or a drug moves
  one, and a sheet showing the stored number would disagree with every check made against
  it. Drawn as `.meter`s against `Character::ATTRIBUTE_MAX`.
- **The portrait is 3:4 and 300px** — `.entity-image--portrait`, the one picture in the
  game that is of a PERSON, because a face in the 16:9 card slot is a face with its head
  cropped. Found by convention like every other picture:
  `public/images/characters/<id>.jpg`, `_default.jpg` behind it.
- **`characters.bio` is the only column on the row the game never reads.** Everything else
  is a number something resolves, a pointer something follows or a clock something settles.
  MEDIUMTEXT because the form allows 65,000 CHARACTERS and TEXT holds 65,535 BYTES — a bio
  in Japanese is three bytes a character. Clearing the box writes NULL: empty and absent
  are one state, and a row of spaces is not a bio.

### The wallet

**Every movement of creds is a line in `wallet_entries`, and `App\Wallet\Wallet::move()` is
the only thing that writes `characters.creds`.** Starting funds, shelf and player-market
trades, mission rewards, siphons, extraction, hunter wins and ICE skim all call it. A new
way for money to move is a `Wallet\Reason` case, a label under `wallet.reasons`, and a call —
never a `forceFill(['creds' => …])`.

- **The ledger is immutable and carries the balance after**, so it reads as a statement.
  No `updated_at`. The `note` is composed at write time and never recomposed, the
  notification rule. Rows cascade with the character; unlike a sighting there is nothing
  here that must outlive its owner.
- **`move()` does not check funds.** The caller owns the transaction and the refusal
  (`Purchaser`, `OfferBook` and `Transfers` lock first). It only floors the balance at 0.
- **`App\Wallet\Transfers` sends cash by handle**, locking both rows in id order against
  deadlock, no fee and no cooldown (a known gap for multi-account funnelling). The
  recipient gets a `Category::Wallet` message.
- **Balances before the ledger existed have no history**; the first entry carries the
  balance it left.
- `/wallet` shows every entry, newest first, 50 to a page (`pagination.ui-kit`, like the admin lists).

### The City

**`CITY.md` is the document.** Maps, travel, exploring, shops, clinics and surgery are built.

- **`characters.district_id` is where the body is, and is always set.** The `Character`
  creating hook fills it from `config('city.start')` (a slug), so the factory, creation and
  anything else agree. NOT NULL on MySQL, RESTRICT on delete; on SQLite the migration skips
  the NOT NULL step. Being jacked in is still only the grid position.
- **Visibility is a READ, in one place: `Location::scopeVisibleTo()`.** Open, or a
  `character_locations` row (found), or `required_flag` set in `character_flags`. A flag
  needs no unlock step. Nothing else may decide it, and **no count of what is hidden is ever
  drawn**.
- **`App\City\Discovery::unlock()` is the only writer of `character_locations`** and only
  for `explorable` locations; **`App\Characters\Flags::set()` is the only writer of
  `character_flags`** (permanent, a lower-case slug). `flag:set <handle> <flag>` is the only
  caller until quests and encounters exist.
- **`App\City\Traveller` is the only writer of `characters.district_id` after creation**,
  and of a travel's `city_activities` row; **`Explorer` writes an explore's.** One row per
  character (the primary key is the whole "one thing at a time" rule). `begin()` itself
  refuses a jacked-in or busy character — the controller is not the guard — and **the
  screen's quote and what is charged are the same call** (`Traveller::quote()`).
- **A travel is one pass in every district on the route EXCEPT the destination, origin
  included**; `district_id` is the district whose pass is running. `Router` is pure and
  deterministic (cheapest, then fewest districts, then lowest ids). **Cancelling waits for
  the pass in progress and leaves you in that district.** A pod's fare is charged up front
  and **snapshotted on the row** (`paid`), so a refund returns what was paid.
- **Settling is lazy, catches up (each pass begins when the previous matured) and runs
  under a lock on the activity row**, because the five-minute `city:settle` sweep and a
  player's request can settle the same trip at once and a refund is real money. The sweep
  also writes the inbox message; a request flashes it instead.
- **`exploration` is a POOL: read it with `$character->stat('exploration')`**, never
  `Resolver::resolve` (outside a Sheet a pool is zero). The two durations are seconds with
  their base handed in from `config/city.php`, as `scan_duration`'s is. **Exploring is never
  refused for there being nothing left, and "found nothing" is SILENT either way** (no flash,
  no inbox message) — it must not say how much is hidden. `checks.floors.exploration` is `0.0`, so there is no
  certainty floor *or ceiling*: a strong explorer is certain of a Trivial place.
- **`[data-countdown="SECONDS"]`** in `ui-kit.js` ticks as m:ss and reloads shortly after
  zero. It is seconds REMAINING from the server, never an absolute time.
- **The City editors are `admin/districts` and `admin/locations`** (`DistrictRequest`,
  `LocationRequest` mirror every CHECK). Coordinates are held to the 320px audit — districts
  20–80%, pins 25–75% — a slug is `prohibited` on update, links are stored lower id first
  and replaced wholesale per district, and **a district is refused deletion while anybody
  stands in it or it is `city.start`**. A trip whose route names a deleted district stops
  where the traveller is and refunds the rest (`Traveller::settle()`).
- **A location is a shop by having `shop_items` rows and a clinic by having
  `clinic_services` rows, and buys from players by having `location_buys` rows;
  `FeatureEditor` is the only writer of all three.** Shop listings are edited as a DIFF —
  a listing carries a stock count, so saving the editor must not refill a shelf — and
  the buy list and clinic are replaced wholesale. **Shop stock restocks lazily**
  (`ShopItem::restock()`, settled under a lock by `ShopKeeper`); no `max_stock` is
  unlimited. **What a place buys vanishes** and `ShopKeeper::sell()` only takes what is in
  the character's hands. **`ShopKeeper` is the only door from a City shop to a stash** and writes
  neither of the market's tables nor looks at `on_market`; **`Clinic` prices per point
  restored**, and its `quote()` and `treat()` share one `owed()`. Both ask `Presence` inside
  their own lock — the page hiding a button guards nothing. A hidden place is the same 404
  as a missing one (`PlaceController` resolves through `visibleTo`). Money moves under a
  lock on the character row and recovery happens on that same locked instance.
- **A district has a `color`** (`App\City\DistrictColor`): a decoration token naming WHICH
  district, set as `--hue` on a map node and `--tier` on a list row. Violet, clay and moss
  joined the palette for it. Never ember, never a semantic alias.
- **`locations.discovery` is `open`, `explorable` or `explored_by_flag`**, and a CHECK ties
  `concealment` to the second and `required_flag` to the third. Names and descriptions are
  ON the rows; UI chrome is `strings.city.*`.
- **The maps are the deck board's classes** through `<x-city-map>`: percentage coordinates,
  wires from `district_links`, `.board__node--here` for where you stand. A LOCATION on a
  district map is an icon, not a name box: `button.board__node` + `.board__pin` with the
  kit's `[data-tip]` tooltip (also shown on `:focus`, because a tap is not a hover), and the
  list under the map still names it. Keep pins 25–75% across or the tooltip clips. **Audited at 320px**
  (a 288px board, so 1cqw is 2.9px): a name box is at most 120px wide (the `.board__name`
  floor, so "Knightsbridge" is one line) and 45px tall, so keep one or two nodes to a row of
  the board, rows 20%+ of the height apart, and centres 20–80% across — an edge or a
  neighbour is the failure. City screens redirect a
  jacked-in character to cyberspace with an `error` flash.
- **The starter city's links, weights and every location are placeholders** in
  `2026_10_03_110000_the_starter_city`; only the seven district names are the author's.

### Standing, as a tab

`/standing` is the fifth character tab: entities with a non-zero `entity_books.standing`
(band word and figure), and the `entity_ledger` standing entries newest first. **Loyalty is
not shown** — it is spent at the store and has its own story. Zero-delta entries (an act
against somebody already at −100) are hidden. Read-only: `StandingLedger` stays the only
writer.

### Narrative encounters

**`CITY.md`, *Encounters*, is the document.** Something that happens to a character in the City:
a graph of nodes and choices with outcomes as rows, authored at `admin/encounters`, nothing seeded.

- **Data, never PHP.** A new kind of effect is an `OutcomeKind` case and a branch in
  `EncounterOutcomes`, and **every kind goes through the writer that already owns it**
  (`Wallet`, `ItemGranter`, `StandingLedger`, `Flags`, `InjuryLedger`, `DamageResolver`,
  `DrugTaker`). **A dose is grant-then-take** in the runner's transaction, so tolerance,
  addiction and overdose all apply; the editor refuses a non-drug item. **Injuries are the two
  `OutcomeKind::INJURIES`** — the drug withdrawals are `DrugLedger`'s alone.
- **An outcome belongs to a button OR a screen** (`choice_id` / `node_id`, exactly one, held by the
  request). A screen's apply on arrival, every time, through `EncounterRunner::arrive()`; the floor
  is asked once, after everything.
- **Nodes are reached by `node_key`, never by id**, so the editor replaces them wholesale on
  every save and nobody mid-encounter is stranded. `EncounterEditor` is the only writer of the
  graph; `Encounters::open()` the only door in; `EncounterRunner` the only thing that answers or
  closes one.
- **No CHECK on a cascading FK** (MySQL error 3823; SQLite will not tell you). Rules like "an
  item outcome has an item" live in `EncounterRequest`. **Run a new migration on the MySQL
  container, not only the suite.**
- **Rarity is the pool.** The draw is out of `max(eligible weights, city.encounter.pool)`, so a
  lone low-weight encounter is rare, not certain.
- **The spawn roll is not a check** (a flat percentile, injected and container-bound), its chance
  the resolved `encounter_chance` stat. **You cannot jack in during any City activity**
  (`Navigator::jackIn()`), so an encounter never opens beside a run; `canHappenTo()` still guards
  against jacked-in, run, hunt, encounter and floor.
- **A travel hit stops the trip** (`stop()` with `step + 1`: the character is already in the
  district reached); an explore pass settles its discovery first.
- **`EnsureEncounterIsResolved` owns the screen**, the hunt lock's shape. Encounters wait for the
  player. A choice must belong to the CURRENT node; cost is charged before the roll.
- **A choice may be a FIGHT** (`encounter_choices.fight_npc_id` + `win_key`/`lose_key`/`flee_key`): it opens a
  fight through `Fights::open()` (BEFORE charging the cost) and the encounter row stays. When the fight ends
  `EncounterRunner::afterFight()` moves it to the win, loss or nobody-won screen (`flee_key` covers the player
  getting away AND the opponent doing so; no gains) and applies that screen's arrival outcomes, which is where a
  reward lives. The destinations are keys snapshotted onto the fight, never a choice id. A fight choice has no
  roll, next screen or outcomes of its own, and restricts the NPC's deletion. **`EnsureEncounterIsResolved` stands
  down while a fight is live**, or the two locks redirect to each other for ever.
- **The button shows a band word**, the figure in `title`.

### Drugs

**A drug is an item with a `drugs` row**, the same shape software and deck hardware have.
Nothing on `items` says an item is one. What a dose DOES stays on `items.modifiers` like an
implant's; the row says which family, how long, and what it costs the books.

- **`App\Drugs\Family` is a fixed vocabulary and not rows** — stimulant, opiate, sedative,
  psychedelic, nootropic. **A new family is a case and a withdrawal**: each needs its own
  `config/effects.php` definition, because coming off a stimulant is not coming off an
  opiate and there is nothing generic to say about either.
- **The books are per (character, family) and a missing row means clean.** Somebody can be
  deep into opiates and clean of stimulants. `App\Drugs\DrugLedger` is the only writer of
  `character_drug_use`, and the only thing that writes or clears a withdrawal.
- **A dose in effect is its OWN row**, because `character_modifiers` cannot express it: a
  modifier row names a definition and takes one amount, and a drug's effects are a list on
  the item. So `character_doses` points at the item and
  **`App\Stats\Sources\ActiveDoses` is the sixth modifier source** — the resolver learned
  nothing new for it. **Two doses are two rows and they add up**, which is `Stacking::Sum`
  by construction rather than by a rule anybody wrote.
- **TOLERANCE IS A PROPERTY OF THE DOSE.** It is read when the dose is taken and frozen
  onto the row as `potency`. A band that climbs an hour later does not reach back into
  somebody's blood — and that decision is the whole reason this mechanic needs nothing
  from `Resolver`, which still has no idea that one modifier can scale another.
- **Only the POSITIVE amounts are scaled.** Tolerance means a drug does less *for* you,
  never less *to* you; softening the cost too would make tolerance a pure good.
- **`App\Drugs\DrugTaker` is the only door** between an item and somebody's blood. One
  transaction: the copy is spent, the dose begins, the books move, the injury lands.
- **Withdrawal is written, not derived**, and **taking one clears it without curing it** —
  the row goes for as long as something of that family is in them and the figure underneath
  does not move. Only time brings it down. **`drugs:withdraw` sweeps every five minutes**,
  for `cyberspace:settle`'s reason: taking a dose has somebody pressing a button, a dose
  WEARING OFF has nobody.
- **Overdosing is allowed and it is DETERMINISTIC.** A body holds `drugs.overdose.base`
  doses of one family at once and tolerance widens that, so **tolerance costs potency and
  buys headroom**. Capacity is counted against what is ALREADY working, so the dose being
  taken is the one that breaks it. Nothing rolls: a drug is a known quantity somebody puts
  into their own body on purpose, and the third one hurting *sometimes* is a slot machine.
- **Too much costs three separate things, and they are different KINDS of thing.** The
  ceiling comes down as an **injury** (`neural_damage`, not mitigable — mitigation has
  nothing to say about a ceiling). The body takes **ordinary damage** through
  `DamageResolver` under `DamageOrigin::Overdose`, so a constitution is written against
  `all_of: ['damage', 'overdose']`. And past the family's line the **heart stops**.
- **A flatline is not big damage.** The body is emptied outright and does not come through
  the damage door — a resistance is about how hard something lands, and a stopped heart is
  not a matter of degree. The same call the ICE attack tag of that name makes.
  `WaitingRoom::checkFloor()` is asked either way, which is the rule every bad ending
  follows.
- **Lethality belongs to the FAMILY**, in `drugs.overdose.families`: opiates and sedatives
  stop you at two past the line, a stimulant at three, a psychedelic or a nootropic never
  (`lethal_at` null). **`drugs.lethal_at` is the per-drug override** and is nearly always NULL — null
  asks the family and does not mean never, so a survivable drug in a lethal family sets a
  high number.
- **`drugs:metabolise` takes a day off both figures nightly**, tolerance faster than
  addiction — a break gives the drug its strength back long before it gives the need up.
  Books that reach nothing on both are deleted; absent and zero are the same answer.
- **`App\Drugs\DrugEditor` is the only writer of the `drugs` table** and clears the side an
  item stopped being, exactly as `ProgramEditor` does. The item editor has a Drug panel and
  `ItemRequest` refuses a drug that is also software, deck hardware or an imprint.

### Implants

**An implant is an item with a body to go in.**

- **Five regions and eleven slots, a fixed vocabulary and not rows.**
  `App\Implants\BodyRegion`: head 2, body 4, arms 2, legs 2, spine 1. Every character has
  the same body — the opposite of a cyberdeck, hence no `implant_slots` table.
- **Capacity belongs to the REGION and is counted, not stored.**
- **`items.implant_region` is the only place the region is written down.**
- **`inventory_items.installed_at` decides whether an implant is doing anything**, and
  `App\Implants\ImplantFitter` is its only writer. An implant in the stash or in a shard
  does nothing, and neither can be installed without being taken out first.
- **Installed is a fourth way to be elsewhere** (see `scopeLoose()`).
- **An implant's effects live on `items.modifiers`**, read by `InstalledImplants`.
- **Surgery happens at a clinic and the Implants screen is READ ONLY.** `ImplantFitter`
  stays the only writer of `installed_at`, but the only door a player can reach is
  `App\City\Surgeon` (see *The City*): a timed activity charged up front, a roll to fit one
  (failure = injury and the implant is rejected, staying in the stash), no roll to take
  one out, and no jacking in while on the table.
- The screen is `<x-body-map>`: `public/images/body.gif` with the regions marked on it in SVG.

### Cyberspace, runs, hunters, missions

**The full rules are in `CYBERSPACE_RULES.md` — read the matching section before editing
any of this.** It covers the world and grid, scanning, defences, node contents, raids and
lockdown, backdoors, the map, runs, extraction, egress, burn, attack tags and defeat,
hunters, the waiting room, recovery and missions. The traps that matter everywhere:

- **Each of these has one writer**: `Grid` (position queries), `Chunk` (terrain bits),
  `Navigator` (grid position), `RunStarter` (runs), `Simulator` (every in-run action),
  `Extractor`, `Burner`, `RaidLedger`, `Lockdown`, `Backdoors`, `Hunters::spawn()`,
  `MissionBoard`/`MissionLedger`, `WaitingRoom`. Go through them; never write the columns.
- **A row that REMEMBERS something must not cascade from it** (`system_contacts`,
  `system_scans`, `runs`, `mission_targets` carry no system FK). A backdoor is access, so it does.
- **Damage goes through `DamageResolver`; checks through `CheckRoller`.** Every bad ending
  runs damage, consequence, `jackOut()`, then `WaitingRoom::checkFloor()`.
- **Settling is lazy and catches up**; sweeps (`cyberspace:settle`, `missions:expire`,
  lockdowns) only keep stored rows true between visits. Do not delete the catch-up.
- **Dice are injected**, and `Probe`/`Simulator` are container-bound so tests pin screens.
- **Zero means "has none", null means "not looked"** on defences and scans.
- **Never name a hidden fact in a failure line** (OS, hidden systems, remaining loot).
- Locks: `EnsureRunIsFinished`, `EnsureHuntIsResolved`, `EnsureCharacterIsStanding`,
  `EnsureDeckStillRuns` — named allow-lists, admin not exempt.

### Physical combat (in progress — `COMBAT.md`)

**Steps 1–6 of the build are done** (damage types, armour, weapons, wielding, the engine, NPCs, the stored fight, encounters that start fights, and what a win pays and a loss costs). Left: the balance pass, which needs authored content.

- **The eight damage types are `DamageOrigin` cases** (blunt, slashing, penetrating, fragmentation =
  family `kinetic`; electrical, thermal, chemical, neural = `specialty`). The family is a second tag on
  the generated `damage.<resource>.<type>` stats, so a resistance is `all_of: ['damage', 'kinetic']`.
  The six older origins are causes and carry no family. Resistances **sum, then apply once** — never
  diminish. Adding origins changes two pinned test lists (the damage vocabulary, the Crim perk's stats).
- **Armour is an item with an `armour` row** (the slots it covers); its resistances are `items.modifiers`.
  `App\Combat\ArmourWearer` is the only writer of `worn_armour`, one row per slot. The seventh modifier
  source, `WornArmour`, wraps a worn piece's DAMAGE modifiers in `hit_location_in` with the slots it
  actually covers; `Damage` carries an optional `?ArmourSlot $location` and `DamageResolver` puts it in the
  context. **A hit with no location never meets worn armour** (ICE, an overdose, an encounter's `damage`
  outcome, a burn). Non-damage modifiers on a piece apply whenever it is worn. The editor refuses a
  condition of the armour modifier's own.
- **`Damage` has an optional `penetration`** (signed percent, default 0): `DamageResolver` scales only the
  hit's NEGATIVE percentage modifiers by `1 − p/100`, so a round can ignore part of a vest. Vulnerabilities and
  immunities are untouched. Only the combat engine passes one; ICE and every older source must not.
- **Combat numbers are generated stats** (`App\Stats\CombatStats`): `aim.<class>` (one shared pool in
  `stats.pools.aim`), `weapon_damage.<class>` (base handed in, unrounded) and the declared `defence`. A new
  `WeaponClass` case brings both with it; each needs a `character.names` entry to be offered in the editor.
- **There is no dodge, and none is planned** (decided 2026-10-05): `defence` and `pursuit` are permanent stats
  (base 8 each in `stats.bases`), and modifiers reach them like any other. A combat skill is **data on its row**,
  never code: its `skills.modifiers` target `aim.<class>`, `weapon_damage.<class>`, `defence` or `pursuit`. Close in
  rolls `pursuit`; Create distance and Disengage roll `defence` against the opponent's `pursuit`.
- **A weapon class can be gated by a skill** (`config/combat.php` `skill_gates` maps class to skill slug,
  `gate_level` is the level, 1). `WeaponWielder::wield()` is the only place it is asked, on the RESOLVED level
  (`skill.<slug>`), and refuses with `WieldRefusal::RequiresSkill`; a class left out is free, a slug with no skill row
  gates nothing, and a weapon already equipped is never taken away. Pistol, SMG, rifle and shotgun are gated; blade,
  blunt, unarmed and explosive are not. The stash card tags a gated weapon *Requires <skill> <level>* whether or not
  you can equip it (no per-card sheet).
- **The fight engine is pure and never writes** (`App\Combat\Fight\FightEngine`): a plain-data `FightState`
  in, a new one out, resistance a `Mitigation` argument, one injected die in a documented order, `chance()` and
  never `CheckRoller::roll()`. Nothing in it may call `takeDamage`, `WaitingRoom`, `WeaponLoader` or `Wallet` —
  the step that stores a fight applies its `Result`. Tested with `Tests\Support\QueueDice`, which throws when
  it runs dry and is asserted spent, so a drift in dice order fails a test.
- **Distance is a number** (`FightState::$distance`, `combat.distance`): a fight opens at 2, 0 is close combat, 6 is the
  most, and **only an action moves it** — Close in (one step) or Create distance (one or two, `far_share`). `Range` is
  derived (`Range::at()`). **Disengage works only from beyond 2.** `FightEngine::choose()` plays any side with nobody
  choosing: the one better at close combat (`prefersClose()`) closes in, the other holds range and backs off when
  closed on. Old stored fights (`range`) still load.
- **`php artisan fight:simulate <handle>` is the balance tool**: a real character's loadout against an opponent
  described by options, seeded and repeatable, writing nothing. Run it before and after touching any combat
  number (`config/combat.php`, `stats.pools.aim`, `stats.bases.defence`). `DamageResolver::against()` is the
  resolver's sum with the modifiers handed in; `preview()` uses it, and a fight must not query per hit.
- **An NPC is a row of numbers and three pointers at items** (`npcs`: ranged weapon, melee weapon, standing
  round), authored at `admin/npcs`. A fight reads it ONCE into a snapshot (`CombatantBuilder::forNpc()`), so an
  edit never touches a fight running. Bare hands at Close, no ammunition counting, resistances as percent off
  by type and by body part. Whose it is (`entity_id`), what it carries (`npc_loot`) and what it does to somebody
  it beats (`tags`) arrived with step 6 and are described under *Endings* below.
- **`App\Combat\Fights` is the only writer of `fights` and `characters.current_fight_id`.** One transaction a
  round with the character and the fight locked; the player's hit points and SI are re-read from the character at
  the start of every round and the round's drop applied with `takeDamage()` (never `DamageResolver::apply()`);
  the opponent is a snapshot on the row; ammunition goes through `WeaponLoader` and a desync throws. **Anything
  that can start something must refuse while `current_fight_id` is set** (the middleware is not the guard), and a
  fight may open inside an encounter. `fight:start <handle> <npc-slug>` is the only way to open one until
  encounters can.
- **Endings are `App\Combat\FightEnding`'s**, called by `Fights::act()` on the locked character BEFORE the waiting
  room is asked (damage, consequence, the floor), returning log lines that go onto the fight's log and into the
  `DownedCause` effects. **It has its own integer die** (`fn (int $min, int $max): int`, container-bound like
  `DefeatResolver`'s) and never draws from the fight's: that die is pinned in an exact order and asserted spent.
  A win pays the grade's band (`combat.pay.<grade>`, through `Wallet` as `Reason::Fight`), rolls each drop in the
  order authored (a chance, and a quantity only on a success; granted through `ItemGranter`) and moves the owner's
  standing by `StandingReason::Defeated` × the opponent's grade (negative, silent in the log). **Only a win pays or
  moves standing**: a loss costs, and getting away — the player's or the opponent's — is nothing either way.
- **Tags are `CombatTag`**, a fixed vocabulary (`mugged`, `maim`; a new one is a case, a string and a branch).
  **`npcs.tags` NULL is the DEFAULT, `[mugged]`, and any list REPLACES it** — the opposite of ICE, where null and
  `[]` are one answer. `mugged` takes a uniform `combat.mugging` percent of ALL creds carried, floored
  (`Reason::Mugged`); `maim` deepens `physical_trauma` by a `combat.maim` percent of the maximum as it stands. The
  editor cannot express "takes nothing": ticking no box is the default.
- **A fight copies its terms when it opens** (`fights.entity_id`, `tags`, `loot`), so an opponent edited or
  deleted mid-fight changes nothing. `fights.entity_id` is a plain column, never a foreign key. An item in the
  snapshot that has since been deleted is skipped and costs no die.
- **Drops are `npc_loot`** (item, chance 1–100, min and max quantity), replaced wholesale by `NpcEditor`, the only
  writer. **Physical items only** (`NpcRequest`): a data item would have to ask `Storage::fits()` and a drop cannot
  be refused. This is NOT `loot_tables`, which is the grid's rarity weights. `Fights::act()` copies `creds` back
  onto the caller's character, or the topbar shows the old balance after a payout.
- **Weapons and ammunition are items with rows** (`weapons`, `ammunition`). A hit's damage is ONE fixed
  figure (a firearm's is its round's); the profile (a map of type to percent, summing to exactly 100) splits it; `DamageProfile` is the
  pure judge. `WeaponWielder` is the only writer of `equipped_ranged_id` / `equipped_melee_id`.
- **Gear is only its own kind**: armour, a weapon and ammunition are each exclusive of one another and of
  hardware, software, drugs, imprints and implants (`ItemRequest`). Armour and weapons are non-stackable,
  ammunition stackable; all are physical.
- **A piece worn, a weapon equipped or a weapon with rounds in it stays in the stash grid, tagged**, and
  cannot be listed, sold or put in a container (`InventoryItem::isOnPerson()`).
- **Rounds are on the weapon's inventory ROW**, never on `weapons`: `loaded_ammo_id` (an item, because
  ammunition stacks) and `loaded_rounds`, no CHECK (error 3823) so a NULL pointer IS an empty magazine.
  `App\Combat\WeaponLoader` is the only writer; rounds come off the loose pile and return through
  `ItemGranter`. **Anything that removes a weapon or a round hands the rounds back first** (destroy, both
  editors). A mid-fight reload or switch costs a turn — the engine's, per COMBAT.md.
- **One item per kind of round**: `ammunition` is unique on (calibre, type), in the schema and in
  `ItemRequest`, because ammunition stacks per item and a twin would be a pile that never merges.
- **A firearm has no damage of its own — its ROUND does.** A weapon with a calibre has `damage` and
  `damage_profile` NULL (the editor refuses both on one; CHECK `chk_weapons_damage_or_calibre`), and the round's
  `damage` is the whole figure of a hit (1–1000, absolute, never a percent) with a required profile. A blade,
  club or grenade has no round and keeps both on the weapon. **A round's riders ADD to the weapon's.** An NPC
  with a firearm needs a standing round (`NpcRequest`); with none it has no ranged arm.
- **`config/strings.php` must not declare a top-level group twice** — PHP keeps the last silently
  (`StringKeysTest` guards it).

### Archetypes and what they hand out

**An archetype is a role, a set of perks, and a loadout.** Perks say what it *changes*; the
loadout says what it *gives*, granted once at creation.

- **`archetypes.playable` is what a new character may be, and only the Deck Jockey is.** The
  other six are **withdrawn, never deleted** — `characters.archetype` is a foreign key.
  Defaults to **false**, like `seed_quantity` defaults to 0.
- **`Archetype::playable()` is the menu; `Archetype::roster()` is the truth.** The creation
  validator uses `playable()`, so a hand-rolled POST cannot mint a role with nothing behind
  it — the disabled cards are the courtesy, not the guard.
- **A withdrawn archetype still renders**, greyed out (a `.card--select` with a disabled
  radio half-lights itself) with a *Not yet* tag.
- **`archetypes.starting_creds` is a column; the other two are tables.**
  `archetype_skills` and `archetype_items` point at the definition with a foreign key.
- **`characters.creds` is the first money in the game**, written only by `App\Wallet\Wallet`.
  No derived maximum, so it is not in `Character::RESOURCES` and is not a stat.
- **A starting skill is granted, not sold.** `SkillInjector::grant()` is the second door
  into `character_skills`, deliberately on the same class as `inject()`.
- **A granted level seeds the points that level costs**, or `skills:validate` reports drift.
- **Level 0 is the ordinary grant**, and is why level 0 is exempt from the prerequisite rule.
- **A grant above level 0 must be reachable** — every prerequisite granted too, at least as
  far. `ArchetypeRequest` refuses the rest.
- **Items land loose, never inside a container.** An unstackable item becomes one row per
  copy.
- **`App\Archetypes\LoadoutGranter` runs at creation and nowhere else**, inside the
  character's transaction. Editing a loadout changes what the NEXT character gets.
- **`App\Archetypes\LoadoutEditor` is the only writer of the two tables** and replaces both
  wholesale. **When a grant row acquires anything worth preserving, that has to become a
  diff.**
- **A granted level is drawn, not spelled out** — `.meter--seg` with `--segs` from
  `SkillProgression::maxLevel()`. The number lives in `title` and `aria-label`.
- **The roster cache stays lean.** `Archetype::roster()` is read on nearly every request and
  holds perks only; `Archetype::withLoadout()` is the uncached read for the two screens that
  need grants.

### The inbox

**A notification is something that happened WHILE NOBODY WAS LOOKING.** That is the whole
test for whether a sentence belongs here: a flash message already covers everything the
player just did, and the inbox covers what the world did on its own. Three TODO entries
stood on this not existing.

- **`App\Notifications\Notifier` is the only writer of `notifications`**, and the only
  thing that marks one read. A sender says what happened and hands over a strings KEY;
  the sentence is rendered once, at write time, and stored.
- **A message has a TITLE and a body, and the inbox is an accordion** (`<details>` per message, quiet ink marker via
  `.accordion--quiet`). The closed row is the title; opened it shows the sentence, the details list and a *Go to
  <category>* button. **The title is composed at send time** from `notifications.titles.<key>` (one per message key, held
  by a test) and stored on `notifications.title`; NULL, for a message from before titles, shows the category label.
  **Unfolding one reads it** — `ui-kit.js` POSTs `notifications.mark` (204) on the first open and lowers the rail's
  count — and *Go to* is the old `notifications.open` (read, then the category's route).
- **The body is never recomposed.** A message outlives its own subject — a machine
  relocates, a skill is renamed, an achievement is retired — so a stored sentence is the
  only one that is still true tomorrow. Same call `system_contacts` makes about a sighting.
- **It belongs to the ACCOUNT**, like achievements, and `Notifier::send()` takes a
  `Character|User` because every sender holds a character and `characters.user_id` is a
  column rather than a query.
- **`App\Notifications\Category` says where a message came from and never how loud it is.**
  Each case has a sender that runs today: `Cyberspace`, `Training`, `Achievement`, `Market`,
  `Mission`, `City` and `Wallet` (somebody sent you cash). **`route()` is a string** — every category leads somewhere now (`City` was the last to
  have nowhere to go). A category that ever has nowhere should make it nullable again rather
  than point at something unrelated.
  **A case without a sender would be a filter with nothing to filter.** There is no
  severity and no colour on them — the kit's aliases mean good, careful and bad, and
  painting Training green would say something untrue about it. The category also decides
  where reading one takes you (`route()`), so no URL is ever stored.
- **Every sender is something nobody pressed a button for** — a finished read, the nightly backdoor roll, a lockdown or burn taking a door, a skill level, an achievement (`insertOrIgnore`'s row count is what makes "said once" true), a mission expiring, a trip landing, cash arriving. Grep `Notifier::send` for the list.
- **Reading is its own POST**, never something opening the screen does — a badge cleared
  by arriving is a badge cleared for messages nobody read. All of them at once, because
  the alternative is a button per row for a state nothing reads except a count.
- **`.dock__count` is the rail's only number**, on the icon rather than beside the label
  so it is legible while the rail is shut. Filled ember at rest, which rule 2 otherwise
  keeps for hover: the same licence `.dot--live` has, spent on 16px that is only rendered
  above zero, and it does not pulse.

## Admin tools

**Three content editors — items, skills, archetypes — behind one gate.** Content lives in
the database, and these screens replace the protection config content gets from tests and
review.

- **`users.is_admin` is the whole authorisation model.** One `Gate::define('admin', …)` in
  `AppServiceProvider`, `can:admin` on the route group, `@can('admin')` in Blade.
- **The flag is outside `User`'s `#[Fillable]`.** No HTTP path to it.
  `php artisan admin:grant <email>` (`--revoke`) is the only writer, via `forceFill`.
- **`RequireCharacter` lets `admin.*` through by name prefix** — the tools edit the game,
  not a character. The gate is what guards them.
- **No `show` route on any of the three.** The edit form *is* the detail view.
- **Both loadout dropdowns are grouped.** Skills by `category`; items by deck hardware
  (`Hardware::label()`), then the two inventories, then imprints. Decided in the
  controller and rendered by `<x-grouped-options>`, shared by the four selects that need it.
- **The archetype editor has no `create` and no `destroy`** — the roster arrives by
  migration and leaves the same way. Perks are displayed and edited nowhere.
- **A slug is permanent**: `prohibited` on update, so a hand-crafted POST is refused rather
  than silently ignored.

**The deck board is arranged by dragging and by the arrow keys.** The editor renders
`<x-deck-board editable>`; nodes write coordinates into one hidden JSON field, and
`data-board-min`/`max` carry the clamp bounds from `BoardLayout`. It arranges the sockets
**as saved**, not as the counters currently read — the placement ring lives in PHP and a
second copy in `ui-kit.js` would be a second answer. Change counts, save, then arrange.

**The item editor writes three tables** — the row, plus `deck_slots` if it is a deck, plus
`deck_modules` if it is a module. `ItemController` wraps both in a transaction and delegates
to `HardwareEditor`.

**Effects are edited as rows, by one component, everywhere.** `App\Admin\ModifierField` plus
`<x-modifier-rows>` edits every modifier list in the game — `items.modifiers`,
`programs.modifiers`, `skills.modifiers`, and `archetype_perks.modifiers`.

- **A screen passes a field name and its extra keys.** `skills.modifiers` carries a `level`
  per row: `<x-modifier-rows :extras="['level']">`.
- **The picker offers stats by id and tag sets by NAME.** Groups come from a stat's leading
  tag; the sets offered are exactly those with an entry in `character.names` — **a set
  nobody has named cannot be rendered on a card.**
- **A target must reach something.** `Modifier::fromArray()` judges shape only, so a
  mistyped id would resolve, match nothing and change nothing for ever. The editor asks the
  registry and names the bad row.
- **A value is an id unless it is plainly a tag set** — more than one tag, or one that
  declared stats wear.
- **What the editor does not understand, it carries.** A `condition`, `priority` or `source`
  travels in a hidden field and comes back untouched.

**The form requests are the substantive part.** `ItemRequest`, `SkillRequest` and
`ArchetypeRequest` mirror every CHECK constraint — the constraints are the guarantee, these
are the explanation. Five rules have **no** schema counterpart, because SQL cannot express
them against one row:

- **One imprint per skill** (`items.use_skill_id` unique), or `Imprint::forSkill()` is
  ambiguous.
- **A container cannot stack**, nor a cyberdeck — `stack_key` would merge two stackable
  containers and lose the second one's contents.
- **No prerequisite cycles.**
- **A deck's socket counts** — facts about a set of rows; a CHECK sees one.
- **A granted skill level must be reachable** — a walk of the prerequisite graph.

**`App\Skills\PrerequisiteGraph` is the one graph walk.** Pure (ids, never models), so the
editor can ask what *would* happen (`->with($id, $requires)->cycleThrough($id)`).
`skills:validate` uses the same class.

**`App\Inventory\Imprint` is the one derivation.** Creating a skill creates its imprint.
`ItemSeeder` calls the same class. What follows the skill afterwards is narrow: **`base_cost`
and `on_market` stay in step on every save; `tier`, `rarity` and `seed_quantity` are derived
from rank once, at creation.**

**Deletes are real and unguarded; the confirmation is the only brake.** No soft delete, no
undo. `App\Admin\DeletionImpact` counts what a delete would reach — inventory rows, player
copies, shelf stock, purchase history, container contents, character progress, queued
training, requirements naming it — and the dialog spends that count before offering the
button. It is a description, never a guard. To retire content, set `on_market = false`.

**A skill's imprint must be deleted first.** `items.use_skill_id` is `restrictOnDelete`; the
controller drops the imprint in the same transaction. Everything else cascades.

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
- **Patch notes are for players**: short and plain, no class names, migrations or reasoning.
- It refuses to rewind a `main` that has been pushed (`git fetch` first, or `--no-branch`),
  and a patch release that adds a migration must be confirmed.

Afterwards, run the migrations the changelog named and any seeder they need.

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
arrived. Now development has its own copy (see *Two databases*), and the worker's
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

## Constraints imposed by php.ini

`docker/php/php.ini` is hardened and several settings break otherwise normal code:

- `open_basedir` is **not in php.ini**: in Docker it is `php_admin_value` in
  `docker/apache/vhost.conf` (web SAPI only; on the CLI it broke Composer and Artisan). On
  production it lives in the FPM pool — see *Deploying*.
- `allow_url_fopen = Off` — use cURL, not `file_get_contents()`, for URLs.
- `disable_functions` blocks `exec`, `shell_exec`, `system`, `passthru`, `popen`, `symlink`,
  `parse_ini_file`, `show_source`, and binds the CLI too, so `storage:link` fails (see *Deploying*).
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
