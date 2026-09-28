# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

A cyberpunk browser game. PHP 8.4 + MySQL, Laravel 13, developed entirely in Docker.
Two systems are already binding: the Laravel app and the **Ash & Ember** UI kit. Read
both before writing code.

Companion docs: `MECHANICS.md` (anything producing a number), `CYBERSPACE.md` (the grid,
systems, runs), `WORLDBUILDING.md`, `TODO.md`.

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
| MySQL 8.4 | `cyberpunk-db` | `localhost:3308` host, `db:3306` containers |
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

**Tests are isolated from the remote database.** `phpunit.xml.dist` forces
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
  it, `Item::imageTypes()` is the list composed from `Hardware`, `ProgramKind`, the book
  and the two inventories, so a new kind brings its folder with it.
- **An imprint calls itself data and files itself as a book** — one book per skill, one
  picture for all of them.
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

**`Character::modifiers()` is the whole list; each entry is a `ModifierSource`.** Five
today, and the resolver learns nothing new when a sixth arrives.

| Source | Is |
| --- | --- |
| `ArchetypePerks` | the role's live perks |
| `RunningPrograms` | software running on the equipped deck |
| `SkillEffects` | `skills.modifiers` |
| `InstalledImplants` | `items.modifiers` |
| `CharacterModifiers` | injuries and statuses, `character_modifiers` table |

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
- **Build a `Sheet` and read it; do not call `modifiers()` per stat.** Four of the five
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
  the book and writing the row at 0 points, level 0, in one transaction.
- **`TrainingDeposit` does not create that row** — it refuses and logs. Owning and
  spending the book is a hard gate.
- **Level 0 is a real state** (injected, untrained), so the gate is
  `Character::hasInjected()`, never a level check.
- **Injecting a skill already known is refused**, and the player keeps the book.
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
- `size` is enforced only as contents against a container's capacity. `weight` is stored
  and unenforced; neither inventory has an overall capacity.

### Skills, and the training queue

**`/skills` is a character's own skills and nothing else.** The public catalogue (and
`SkillTree`, `SkillTreeNode`, `<x-skill-node>`) is gone. The route is behind `auth`, so
`RequireCharacter` guarantees a character.

**HOW A PLAYER FINDS OUT A SKILL EXISTS IS THE MARKET.** One book per skill, so the shelf
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
  Nine entries into a queue that holds five, or a route through a book that was never
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
  will be a table of offers, not this flag. **A listable book is an UN-INJECTED one, and
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

**`App\Market\Purchaser` is the only thing that spends `characters.creds`**, the only
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
- **Storage is stored and not enforced** — `DeckRequirement` has no storage case.
- Screens: topbar count + modal, deck screen's Running and Storage tabs (storage split
  into two grids), `inventory.run` in both directions.

### The character sheet

**One rail item stands where four did.** Skills, Implants and Achievements each had their
own, and are tabs of `/character` now — because to a player they are one screen: who you
are, rather than what you are carrying.

- **THE TABS ARE FOUR ROUTES, NOT FOUR PANELS.** Each kept its URL, its controller and its
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
- **Surgery is free.** When it costs, `ImplantFitter` is the class that knows both sides.
- The screen is `<x-body-map>`: `public/images/body.gif` with the regions marked on it in SVG.

### Cyberspace

**`CYBERSPACE.md` is the document.** Phase 3 (the grid) is done. Runs, the fight, the trace
and extraction are built. See below for what is not.

#### The world

- **`App\Cyberspace\Grid` is the ONE place that knows how the world is stored, and nothing
  else may query `systems` by position.** Materialised rows today, a generator with
  override rows at 2048×2048 later.
- **A position is where something is; an address is what answers.** Eight hex characters,
  random, unique and MEANINGLESS. **Addresses do not survive** — a burned system takes its
  address out of existence, a relocated one comes back with a new one, so everything
  storing one must treat "nothing there" as ordinary.
- **Discovery is two records and one act.** Terrain is *I have looked here*: a bitmap, 512
  bytes per 64×64 chunk entered. `App\Cyberspace\Chunk` is the only arithmetic turning a
  position into a byte and a mask, and nothing else may touch `character_terrain.bits`.
  Contact is *what was standing here*, and it is allowed to be wrong.
- **`system_contacts` is a SIGHTING and carries no `system_id`** — it must outlive the
  machine. Rewritten in place inside the swept window, so a position now empty FORGETS.
  `SystemContact::standingIs()` asks "is this still true?" and none of its three answers is
  an error.
- **`App\Cyberspace\Navigator` is the only writer of `characters.grid_x`/`grid_y`.** Having
  a position IS being jacked in — no session row, no flag. **Every arrival sweeps**, in the
  same transaction as the move.
- **A deck that stops running throws its owner out**, for 1 point of Synaptic Integrity.
  `App\Cyberspace\ReadinessWatch`, asked in TWO places: **in place** by the screens that can
  break a deck from inside (implants, programs), and by **middleware**
  (`EnsureDeckStillRuns`) on every jacked-in request. The order is the one every bad ending
  uses: damage, consequence, `jackOut()`. **It must never become the answer for something
  during a run.**
- **Damage is RESOLVED.** `App\Stats\DamageResolver` is the door;
  `Character::takeDamage()` is the dull half underneath (subtract and floor) and calling it
  directly skips every rule.
- **A waypoint bookmark may only be set on swept ground** —
  `App\Cyberspace\BookmarkKeeper`, since no CHECK can reach a bitmap in another table.
  Without it, coordinates become tradeable. **A SYSTEM bookmark has no such gate**, because
  an address carries no position.
- **Every machine has an owner, and `entities` arrives by migration.** The eight hardware
  manufacturers in WORLDBUILDING.md are deliberately NOT entities — who BUILT a thing and
  who RUNS it are different questions. `entities.category` is a fixed vocabulary read as
  `App\Entities\EntityCategory` (corporation, government, military, fringe), and
  **`entities.interactable` is the whole difference between scenery and somebody you can
  work for** — false by default, the call `archetypes.playable` makes. Standing, the
  Exchange, missions and the loyalty store are all built.
- **Standing is what an entity thinks of you, and `App\Standing\StandingLedger` is its
  only writer.** Two tables: `entity_books` (one row per character and entity, standing as
  `DECIMAL(6,3)` and loyalty as an integer) and `entity_ledger` (immutable, one row per
  change, both kinds in one table). **THE BOOKS ARE ALWAYS THE SUM OF THE LEDGER** —
  clamping trims the DELTA rather than the total, so an act against somebody already at
  −100 writes a delta of 0, which says honestly that it changed nothing. `standing:verify`
  asks; `standing:drift` takes 0.25% of what is carried toward zero nightly at 05:20 and
  writes a `Decay` entry for every move. **Flavour entities keep no books**: a write
  against scenery is a no-op, not a refusal. Loyalty never decays and is spent at the
  store. The hooks are in `Simulator` (noticed, raided, burned — the burn
  BEFORE `Burner` deletes the row) and `cyberspace:age-backdoors`.
- **An Exchange is a system that is not one.** `SystemType::Exchange`, with an `exchanges`
  row carrying grade 1–5, the agent's name and `min_standing`. **That grade is NOT
  `systems.grade`**, which stays at its floor: one means what is on offer, the other means
  difficulty, and the difficulty column is what scans, loot and the OS ladder read.
  **`isInteractable()` split into four** the day this arrived, exactly as its docblock
  predicted — `isInteractable()` (a run), `isScannable()`, `isDefended()` and `isOwned()`,
  which is the one an Exchange answers yes to. Placed by `cyberspace:exchanges`, a TOP-UP
  run by hand: higher grades sit deeper, and a desk that exists is never moved, shut or
  re-rolled. **ONE GATE, NOT TWO** — `exchanges.min_standing` (0/5/20/40/60 by grade) is
  read by the work and the shelf alike. `/exchange` is the Missions & Loyalty screen,
  reached by standing on the desk. The board is real — see *Missions* below — and so is
  the store.
- **The loyalty store is `entity_offers`, and `App\Standing\LoyaltyExchange` is the only
  thing that spends loyalty.** A row is an entity, a grade span, a price and EITHER an item
  OR a service — `App\Standing\Service`, something the entity DOES and hands nothing
  over for (`ShedHeat`: `service_amount` points of heat off, floored, `bounty_word_at`
  untouched). A fixed vocabulary like `Drugs\Family`: a new service is a case. **Item XOR
  service is not a CHECK** — `item_id` cascades and MySQL refuses a CHECK on such a column
  (3823), so `EntityOfferRequest` holds it. A service that would do nothing is refused
  (`LoyaltyRefusal::NothingToDo`) rather than charged. Lockjaw's 1000-for-10-heat arrived
  by migration.
- **A name is composed, never stored** — `<Type> - <entity> NNN`. `systems.name` is the
  authored-name override (NULL on nearly every row) and `systems.designation` the only
  stored part. `App\Cyberspace\SystemNamer` decides.
- **`GridSeeder` tops the world up TO a target**, like `MarketSeeder`. Occupancy leaves
  ~30% empty on purpose — that reserve is what relocation moves through.
  `cyberspace:seed` is run BY HAND, not scheduled; `--dry-run` writes nothing.
- **Every machine runs an operating system, as a foreign key to the skill that gates it**
  (`systems.os_skill_id`). Which one follows the machine's grade via the skills' RANK.

#### Scanning

- **Scanning is `App\Cyberspace\Probe`, and it reads the machine you are standing on.** No
  target in the request. One press starts a READ; a pass rolls once per thing not yet
  known. **There is no pass budget** — a failed scan costs the expiry window and an
  analyser's compute for the length of it, not a retry.
- **A read goes round by itself** until the picture is whole or the FILE GOES STALE. The
  window in `cyberspace.scan.lifetime_hours` is the whole budget, and it is what bounds
  the catch-up walk. Stopping the scanner ends the read — the one thing the player chose.
- **A pass takes MINUTES** — one against grade 1, sixteen against grade 9, scaled by
  `scan_duration`. **Spent when BEGUN, rolled when it MATURES.**
- **Settling is lazy**, and **a READ CATCHES UP**: `Probe::settle()` walks the clock
  forward, each pass beginning when the previous MATURED, so an hour away is an hour of
  reading. It terminates because every turn either stops the read or begins a strictly
  later pass. **A lost pass stops the loop** — spending the next one is the player's call.
- **`cyberspace:settle` sweeps every five minutes and is NOT what makes a read finish.**
  What it buys is a row that is TRUE between visits. **Do not delete the catch-up because
  the sweep exists.**
- **Losing a read has two causes that must not share a sentence** —
  `App\Cyberspace\VoidCause`: the instrument stopped, or the machine burned/relocated.
- **A read is a screen you watch.** The `<dialog>` in the layout polls
  `cyberspace.reading` while open, and the endpoint settles a matured pass on the way past.
  It polls only while open.
- **A read runs in the BACKGROUND and belongs to the instrument** —
  `system_scans.reading_process_id` points at the running scanner, so the read survives
  walking away or jacking out. **Settling is per character** (`settleAll()`).
- **Two instruments read two machines.** `ProbeRefusal::AllBusy` is distinct from having
  none.
- **An interrupted read is a lost read.** The analyser must have run for the WHOLE pass:
  `inventory_items.started_at` later than the read began answers it without recording
  history. Swapping analysers fails the same test. A voided pass is still SPENT.
- **`Probe` is bound in the container**, so a test can pin its dice and have the SCREENS
  use them (the map settles during `index()`).
- **Reading a machine takes software, and it has to be RUNNING.** **What a better version
  buys is data, not code**: `programs.modifiers` against `check.systemprofiling` and
  `scan_duration`. Analysers are BACKGROUND programs.
- **A scan is a file that perishes.** `system_scans` is one row per character per address,
  no foreign key to `systems`. Columns, not a JSON bag; null means *not found out*. Live
  means unspent and unexpired (`scopeLive`); a stale file is replaced, never continued.
- **`App\Cyberspace\ScanFact` is the vocabulary of what a scan asks**, and it lists the two
  nothing can read yet (ICE, cloaked) as *not yet* rather than hiding them. `isKnownOn()`
  reads COLUMNS; `readFrom()` may reach through a relation (asking a relation whether a
  scan is complete says no to one that just answered — Eloquent caches the null).

#### Defences

- **Decided ONCE, at creation, and stored.** `App\Cyberspace\Defences`: **type sets the
  floor and grade rolls the rest.** A silo always watches and fights, a relay always
  watches and traces, a compute node always fights and traces and rolls for detection — so
  every type guarantees two and rolls one, and a scan answers *which one is it missing*.
  The portal carries nothing (`SystemType::isInteractable()` already says no).
- **`cyberspace:defend` arms a world that predates its own defences.** A **back-fill, not a
  re-roll** — a machine already carrying something is left alone. A command rather than a
  migration, because it ROLLS. Run once on 2026-09-16.
- **This is why a mission node needs no override mechanism** — nothing derives a defence on
  read, so a dangerous machine is a row with different numbers. `systems.stock_rule` exists
  because CONTENTS are re-rolled nightly; defences never are.
- **Everything hostile can notice you.** A tracer carries its own detection value (a fifth
  of the IDS at grade 1, two-thirds at grade 9), so a machine with no IDS is quieter rather
  than safe. Detection is the EVENT, not the instrument.
- **Zero means "has none"; null means "not looked".** On `systems` the three defence
  columns are NOT NULL and 0 is absence. On `system_scans` they are nullable — **a scan
  that comes back clean has told the player the most valuable thing it can.**
- **A tracer is one thing, so it is rolled for once.** `tracer_detection` and `tracer_rate`
  are zero together or positive together (`chk_systems_tracer`).
- **ICE is a POINTER; the other two defences are numbers.** `systems.ice_id` points at an
  `ice` row (integrity, attack, hit chance, defeat tags). The catalogue **arrives by
  migration**.
- **`skill` is a hit chance 1–100, and a MISS DOES NOTHING AT ALL** — separate from
  `attack` rather than averaged in, because an average makes every fight the same fight.
- **A scan remembers the LEVEL, never the row.** `system_scans.ice_level` has no foreign
  key. Null is *not found out*, 0 is *there is none*.
- **`Defences` is handed the catalogue, it does not query for it.** An empty list is not an
  error.
- **A bounty belongs to the MACHINE, not the guard.** `systems.ice_bounty`, rolled when ICE
  is assigned, odds climbing with level (a tenth under ICE I, two-thirds under ICE XII). A
  scan reads it folded into the ICE fact — the one answer on `system_scans` that is not
  nullable.
- **TWENTY TURNS IS NEVER ENOUGH.** Every icebreaker does strictly less than would break
  its matching ICE in twenty turns. `DefencesTest` pins it.
- **So fighting is gated behind training**, which is the intended reading of "the bottom of
  the grid is walkable" — walkable, not fightable. Icebreaker I needs thirty turns against
  ICE I, which deals ~1.05 SI a turn against a starting pool of 31.
- **Integrity and attack are BASES and expect to move.** A fight should run 2–10 turns; the
  twelve pieces climb together so the ratio survives recalibration.
- **An icebreaker is a GATE before it is a number** — `icebreaker_damage` floors at 0. Its
  bite is a modifier on `programs.modifiers`.

#### Node contents

- **What a node holds is its OWN inventory model.** `system_contents`, not the character
  inventory with a different owner column: raided rather than carried, safe from ICE,
  restocked on a clock. Three kinds in one table (`creds`, `item`, `data`) with a CHECK per
  kind.
- **A file is an object, not a reward.** Size in QB, an encryption type, and TWO values —
  sealed and broken. The gap is why decryption is a mechanic. Files are **generated, never
  authored**, which is why they are rows here rather than `items`.
- **`App\Cyberspace\NodeStocker` is the only writer, and it TOPS UP.**
  `cyberspace:restock` runs nightly with `--dry-run`; the brake on the loop is the raid
  counter, not scarcity.
- **A node's items are a draw of NONE TO SEVERAL**, rolled again after each hit and capped.
- **Nothing PHYSICAL ever comes off a machine** — the shelf is `on_market` items whose
  `container` is `data`. `NodeStocker::stockableItems()` is the one thing about node loot
  that is not data.
- **`loot_tables` is the weighting, and it is rows rather than config** — one row per
  (type, grade band, rarity), `weight` out of the band's own total, and **a NULL
  `system_type` means any machine**. `LootTable::weights()` is the read (never `all()`) and
  `covers()` the question.
- **The bands are the design and both ends are hard.** Grades 1–3 draw rare at 1%, and
  **ultra-rare is weight 0 below grade 8** — a wall, not a long shot. A rarity with no items
  behind it is skipped.
- **A file is labelled for the machine it came off** (`strings.cyberspace.files.words` per
  type) — flavour, not mechanics.
- **`systems.stock_rule` is how a machine is TOLD what to hold**, NULL on nearly every row.
  `fixed`, `any_of` (drawn without replacement), `plus_random`. **A rule replaces the
  draw.** Items only — creds and files are still rolled, since a mission node holding no
  money would announce itself.

#### Raids, lockdown, relocation

- **`systems.raids` is on the SYSTEM, not the character.** Three successful raids retires
  the node. `App\Cyberspace\RaidLedger` is the only writer of that counter and the only
  thing that moves a machine once placed.
- **A RAID IS A RUN, not a haul.** Counted once, the first time a run takes anything —
  `Simulator::recordTake()` is the one door, which is why `runs.extracted` is written there.
  Counted when the take LANDS, because the shelf is empty from that moment whatever happens
  to the person carrying it.
- **Retiring is relocation, and it happens in `RunStarter::end()`** — the one door out of a
  run, asked on EVERY ending. **Not while anybody is inside**: a live run holds the machine
  in place, because relocating out from under a run is `RunEnding::Cancelled`.
- **`App\Cyberspace\Lockdown` is the window, and the only writer of
  `systems.locked_down_until`.** A violated machine shuts for 2–6 hours, nothing may run it,
  and the window's close relocates it. **Two triggers, not four**: the run was noticed
  (`runs.detected` covers both the IDS and the ICE, since detection is what wakes ICE), or
  the raid counter is spent.
- **It fires as a run ENDS**, so it can never reach the runner who caused it. A machine
  already shut is left alone — a lockdown is what the machine DOES, not a sentence that
  accumulates.
- **Anybody else still inside is thrown out** — `RunEnding::Cancelled`, one flat point of SI
  through `DamageOrigin::RunCancelled`, no defeat effect, no raid, no trace. **The floor is
  asked anyway.** `Simulator::resolveEnding()` owns it.
- **A cancellation gets its own damage origin** even though it costs what a readiness loss
  costs, because an origin is the axis a resistance is written against.
- **EXPIRY IS SWEPT AND NOT LAZY — the one place cyberspace breaks its own rule.** Two
  machines relocating into one free position is a race, and `cyberspace:lockdowns` (every
  five minutes) serialises it. A machine that opens with nowhere free is logged and left.
- **The map draws a lockdown from MEMORY** — `system_contacts.locked_down_until` is what you
  SAW.
- **Relocation is `RaidLedger::relocate()`**, public because lockdown and burn end there
  too. New position, new address, `raids` back to 0. Bookmarks break, scans stop describing
  anything, the sighting is forgotten by the next sweep — none of it an error.

#### Backdoors

- **A backdoor is ACCESS, so `backdoors.system_id` is a real foreign key** — the opposite
  call from contacts, scans and runs. A memory of a gone machine is still a memory; access
  to one is nothing. It is also what makes the promise true: a backdoored machine does not
  lock down and does not relocate. `App\Cyberspace\Backdoors` is the only writer.
- **The KIND is permanent and `install()` will not reroll it.** A machine siphons or it
  routes. Going back in changes nothing — a silent overwrite would convert a siphon into a
  routing hop for free. Chosen at the DOOR, on `runs.backdoor_kind`.
- **A landed install is the only thing in cyberspace that cleans up after itself.**
  `runs.backdoored` means no lockdown **even if the IDS fired and the ICE woke**. A FAILED
  install cleans up nothing. It moves no raid counter either.
- **What holding one buys is exactly half the risk.** The approach roll is SKIPPED (skipped
  rather than discounted — an untuned number would be a second thing to balance). It buys
  nothing at the core.
- **`Simulator::touchTheCore()` is that second look, shared** by extraction and install.
- **FOUR THINGS TAKE A BACKDOOR.** Three are somebody's fault, one is nobody's.
  `Backdoors::loseAllOn()` is asked by `Lockdown::shut()` and `RaidLedger::relocate()`; a
  BURN needs no entry (the cascade handles it). **A stranger's bad night costs you a door
  you were careful with.**
- **A siphon pays `(3000 × grade) / 100` a night** — `cyberspace:siphons`, run BEFORE the
  ageing roll. Deliberately modest: a siphon is the SAFE half of a portfolio.
- **The base is the MACHINE'S, the modifiers are the PERSON'S.**
  `App\Cyberspace\SiphonPayout` hands the per-grade figure to the resolver as
  `siphon_yield`. That stat is deliberately NOT in `stats.bases` — there is no such thing as
  a character's siphon yield, so `Character::stat()` throwing for it is right.
- **Paying out is not a write to `backdoors`**, so it does not live on `Backdoors`.
- **The nightly ageing roll is nobody's fault.** `cyberspace:age-backdoors`, one roll per
  backdoor, **odds rising with AGE and nothing else**. A hit moves the MACHINE, taking every
  other door with it. The player is not told — logged until there is somewhere to send it.
- **The backdoor mark is the one thing on the map that is NOT memory.** `MapView` reads
  positions off the machines the rows point at (not the positional query `Grid` reserves).

#### The map

- **The map screen draws from memory and never from `systems`.** The eight cells around you
  are buttons; arrows and the numpad reach the same eight, because a diagonal is one step.
- **A cell says up to six things at once, each on its OWN channel.** **Ground** is terrain
  (fog below the board with a weave and no hairline, swept ground on it with one — a layer
  difference, not a shade). **The glyph** is the kind of machine. **The hairline** is its
  condition (dashed while rebooting). **The corners** are what you have on it: bookmark
  top-right, backdoor bottom-left in ember, **scanned bottom-right in `--info`** (a machine
  you have read something OUT of, so an empty file marks nothing). **A bar at the foot** is
  the scanned grade. **Colour says type and nothing else** — steel, cyan, jade on the GLYPH,
  never a fill.
- **A cell has two pseudo-elements and four marks**, so the grade bar and the scanned tick
  are BACKGROUND LAYERS composited through `--cell-grade` and `--cell-read`. A rule setting
  `background-image` outright would take the other with it.
- **The scanned mark clears itself**, keyed by ADDRESS like the grade.
- **An UNSCANNED grade is not visible from the map** — a sweep is free. A scanned one is
  drawn, and only a live `system_scans` row can supply it.
- **The window is capped at what anybody could ever see.** `map_range` is 7 because
  `view_range` is 2 and the modifiers meant to widen it are worth ~+5. **A cell is 60px**;
  the map scrolls and centres on the player. A bare character's lit square is 5×5, exactly
  what a 360px phone shows.
- **The key sits beside the map as its own collapsible rail.** `.gridmap-frame` reserves the
  collapsed width and `.maplegend` OVERLAYS the map's right edge — the map re-centres on
  scroll, so a column that pushed it narrower on hover would move the ground under the
  cursor. Three ways in, the dock's three. **Below 720px it drops under the map and stays
  closed.** The frame declares `--key-w`, because a parent cannot read a property set on the
  child.
- **What is outside the current view range is dimmed, not hidden** — `cell--distant` at
  **.55** (it was .8, which is invisible when most cells are distant). Nothing is lit while
  jacked out.
- **An ordinary machine has no hairline.** A hairline is only ever the portal, a machine
  that has just appeared, one rebooting, or you.
- **Two things move, and differently on purpose.** The portal BREATHES between bone and
  brass over four seconds (one portal in the world). A new machine has a light TRAVEL round
  its hairline in its own type colour. A pulse and a sweep read as two statements; two
  pulses read as neither. `prefers-reduced-motion` stops both.
- **A new cell keeps its type colour** (bone was wrong twice), and phases are staggered by
  position — forty synchronised sweeps is a disco.

### Runs

**The spine, the fight and extraction are built.** Detection, ICE, the trace, defeat
consequences and taking what a machine holds all roll.

- **`runs` is durable state**, the cost of making a run interactive. **The row carries the
  ADDRESS and never a system id** — a machine relocating out from under a run is what
  CANCELS one, and a foreign key would make that an integrity error. `Run::system()`
  returning null is ordinary.
- **`characters.current_run_id` is what "in a run" means** — a column, like
  `equipped_deck_id`.
- **`App\Cyberspace\RunStarter` is the only writer**, setting the character's pointer in the
  same transaction.
- **It refuses four things and deliberately not a fifth.** Not in cyberspace, nothing there,
  the portal, and a deck that stopped meeting its requirements (checked a second time —
  cheap, and it closes the window). **It does NOT refuse a machine whose OS you lack** —
  that run is entered and the core is rolled for from the inside, because a refusal naming
  the OS would hand over a scan fact for one click.
- **SPEAKING THE MACHINE'S OS IS A ROLL, NOT A WALL.** `Simulator::opensTheCore()` is
  asked by Extract, Install and Burn — the three actions that already share
  `touchTheCore()` — and by nothing else. The check value is the runner's **LEVEL** in
  `systems.os_skill_id`, read off the `cyberspace.os.values` table, against a flat
  difficulty: 4.7% / 18% / 48% / 77% / 89%, and the certainty floor at level 5. **A table
  and not a multiplier** — the first level is worth nearly twice the fourth, which no
  straight line draws. **No grade term** — a
  machine's grade already decides WHICH operating system it runs, via the skill's rank, so
  charging for it again would charge twice.
- **AWAKE ICE GUARDS THE CORE.** Extract, Install and Burn each make the core touch
  (the IDS's second look) and then, if any ICE is awake — woken now or on arrival — fail
  with `ice_guards` BEFORE the operating system is rolled. The turn is spent and the ICE
  swings. The runner breaks it or egresses; there is no third way to the core. Said out
  loud, unlike the core's silent failures: the ICE is on screen, so naming it gives
  nothing away. The run screen disables the three buttons while `Run::inAFight()`.
- **A cut never asks.** An icebreaker against an ICE is one program against another; the
  vocabulary only matters when the MACHINE is being told to do something.
- **`runs.os_locked` is the state of the door, not a verdict about the runner.** True from
  the start whenever the machine runs anything, cleared by the roll that lands, and **open
  for the rest of that run** — so a two-turn extraction does not re-roll. Going back in
  tomorrow means finding the way in again.
- **A failed attempt spends the turn and says nothing.** That silence is why the CHANCE is
  on the run screen (`Simulator::coreChance()`, null when there is nothing to get past):
  a streak of nothing has to be a number the player was shown rather than a bug they have
  to guess at. The line says *core* and never names the OS.
- **`EnsureRunIsFinished` is the lock**, `RequireCharacter`'s shape: named allow-list,
  everything else redirected, 409 and a URL for JSON. **Admin routes are not exempt** — an
  admin could otherwise edit their way out of a run.
- **The readiness net stands down inside a run.** `EnsureDeckStillRuns` returns early while
  `current_run_id` is set, because a burnout is *supposed* to break the deck and resolves
  its own ending.
- **Leaving is always on the list** — egress is never absent and never disabled.
- **`App\Cyberspace\Simulator` is one door for every action**, so a turn always costs the
  same thing. The die is injected. **Bound in the container**, so a test that pins the die
  gets a pinned SCREEN.
- **The player acts, then the machine does.** Somebody who breaks an ICE does not then take
  a hit from it; somebody who leaves gets their attempt first.
- **What is fighting lives on the RUN** — `runs.ice_id` and `runs.ice_integrity`. The
  catalogue says what an ICE is; these say what this fight has left of it.
- **A failed cut says one thing for two causes**: no icebreaker, or nothing awake. The OS
  is not one of them — see *speaking the machine's OS* above. `FightTest` still asserts the
  words *operating system* appear nowhere in it.
- **`stealth` is what an IDS is rolled against**, floored at 0. Its base is a **POOL** —
  `(intellect + focus + memory + perception) / 8.5`, one decimal.
- **Arriving is the first thing anything gets to notice** — `Simulator::rouse()`. **Every
  watcher rolls**, and **any one success detects you**.
- **Detection is what wakes things.** An undetected run walks past the ICE and the tracer
  both — the ICE is only written onto the run when something noticed. So stealth does not
  make a fight easier, it makes the fight not happen.
- **It takes the GLOBAL certainty floor** — `checks.floors` names no kind for it on purpose.
- **The trace advances once something has noticed**, at the machine's `tracer_rate` a turn,
  read off the machine rather than copied onto the run (unlike integrity: integrity is being
  ground down, a tracer's speed is hardware).
- **A TRACE WALKS THE CHAIN, one hop a turn.** One attempt at the NEXT system: pass and the
  trace moves up, fail and it stays — the turn ends either way. **A hop once passed is
  behind it** (`runs.trace_hop`). Rolling every hop every turn would make a long chain
  impassable rather than slow.
- **The chance is what the hop's GRADE allows** — 95% at grade 1 down to 20% at grade 9, and
  **grade is the only thing a hop reads**.
- **A hop only delays by FAILING**, so **grade 1 is 95 rather than 100**: at 100 a junk hop
  could never delay anything. Ten junk hops is a 40% chance of one held turn.
- **`routing_hops` is the ceiling, and it is all skill** — base zero, one per level of
  Routing and Advanced Routing, ten total. First entry in `stats.bases`.
- Past the end of the chain the trace comes on unopposed; an empty chain is that case from
  turn one.
- **THE CHAIN IS BUILT, NOT PICKED.** `App\Cyberspace\ChainBuilder` takes every routing
  backdoor, best grade first, capped at `routing_hops`, never the target. A picker's right
  answer is always "all of them, best first".
- **`runs.chain` is system ids and never grades**, snapshotted at the door and **never
  rewritten**. `trace_hop` indexes it, so a hop that goes dark becomes a GAP rather than a
  shift — `routingGrades()` returns one slot per stored hop with null where the machine or
  backdoor has gone, and `traceGetsThrough()` steps over nulls for free. **Use
  `array_key_exists`, not `??`**: a present null is a gap and an absent key is the end.
- **`Simulator::burnTheChain()` shuts every machine on the route** through
  `Lockdown::shut()` — one call does "the backdoors are gone and the systems move".
- **A completed trace puts heat on the character and burns the chain.** `characters.heat` is
  persistent; **a trace costs the system's GRADE × 2** (`cyberspace.trace.heat_per_grade`),
  **charged as the run ENDS** (`RunStarter::chargeTheTrace()`, any ending) rather than as
  it lands, because **a landed burn REPLACES it — the two heats never compound**. The chain
  still burns the moment the trace lands. A traced runner losing nothing else is
  deliberate: heat is meant to become untenable once hunters and bounties have teeth.
  What heat DOES today is attract attention (random encounters are the plan):
  `App\Characters\Bounties` rolls every character carrying any, once a night at five, against
  the chance somebody decides they are worth money. The uncertainty floor under five points,
  climbing in a straight line to **certain at a hundred** — a straight line because nothing
  is balanced against it yet, and `chanceFor()` is one method to change when it is.
- **That roll is NOT a check, which is why it may reach certainty.** A check is somebody
  attempting something against a difficulty and the floor exists so skill never becomes a
  guarantee; this is the world deciding about you, with nothing to be good at. It takes the
  percentile shape the market's rarity roll and the backdoor ageing roll use — injected die,
  strictly inside the bound, so nothing never fires and everything always does.
- **Zero heat is not rolled at all.** A floor is what an attempt keeps, not something the
  world owes somebody who has done nothing.
- **What a hit IS has not been built.** No bounty row, no hunter, no price — the message
  says the word is out and stops where the mechanic stops.
- **Word already out is not news.** `characters.bounty_word_at` remembers the last time,
  and nobody is rolled again until `cyberspace.heat.bounty.cooldown_days` have passed — a
  memory on the character, never a gentler curve, and a column rather than a look through
  the inbox, because a player clearing their messages must not reset the world's opinion.
- **And it comes off again, slowly.** `App\Characters\HeatDecay` takes half a point a
  night at 05:10, **after** the bounty roll — a character answers for the heat the night
  ended with, and the cooling is the morning after rather than a discount applied before
  anybody looked. **The rate is the `heat_decay` stat**, based at 0.5 in `stats.bases`, so
  the first skill that speeds it up is a modifier and nothing in the command learns it
  exists. Resolved **through the Sheet** (`Character::stat()`), never `Resolver::resolve()`
  with a base handed in — `stats.bases` is a map only `Sheet::baseFor()` walks.
- **`characters.heat` is a DECIMAL**, one place, because what earns heat is whole and what
  removes it is not. It floors at nothing and has no ceiling.
- **`characters.heat` and `runs.thermal_load` are different quantities.** The design calls
  both "heat"; the run's column is named for what it is.
- **The two thresholds ACT, and BEFORE the player's choice** — checked at the start of the
  turn, and a breach attempts egress then and there whatever was asked for. A threshold that
  only warned would leave an unresponsive player in a run that never resolves. **Zero turns
  one off.**
- **They are set from the MAP, never from inside a run** — an accordion on the cyberspace
  screen, because they are a play style, and changing them mid-fight would be changing the
  rules of a fight while standing in it.
- **The run screen draws the trace as the CHAIN it walks** — the machine at one end, you at
  the other, backdoored relays between. Two nodes today, drawn as a chain so it will not
  have to change. `Run::turnsUntilTraced()` is worked out server-side.
- **`run.setup` is the briefing, and the only way a run begins.** The map says *Plan a run*.
  Everything on the briefing is permanent: purpose fixed at the door, backdoor kind
  unchangeable, route snapshotted at start.
- **The briefing's product is what is NOT known.** An unread machine says so in words — blank
  reads as *nothing there*, which for an IDS is the opposite of the truth. Every `ScanFact`
  is listed either way. Beside it: deck requirements, thermal verdict per part, what is
  running and **what each thing IS**, and the route as the same chain widget.
- **An intention is a form, not a select** — each its own button with its own hidden purpose
  and kind. All three are real submits now that burn is built.
- **It reads the machine LIVE, not the contact**, and everything else on it is the same call
  the run makes.

#### Extraction

- **`App\Cyberspace\Extractor` is the only door between a node's inventory and a stash.**
  **Creds and items are instant; a file costs turns.** `takeCredsAndItems()` and
  `takeFiles()` are the two halves a multi-turn pull needs; `totalFileQb()` sizes one;
  `extract()` composes all three for non-interactive callers.
- **A file's turn cost lives on the RUN** — `runs.extracting_qb`, null when no pull is
  running. `beginTransfer()` sets it from `totalFileQb()` minus one turn's bandwidth,
  `continueTransfer()` spends another each time Extract is chosen, `completeTransfer()`
  calls `takeFiles()` at zero. **Continuing is an ordinary call to `Simulator::extract()`
  inside the ordinary turn** — no path skips the machine's half. A pull can only BEGIN
  with no ICE awake (see below). Continuing never touches the core again, but the IDS
  still watches (below). Spending the turn elsewhere pauses a pull, never loses it.
- **The core-touch detection roll fires only on the turn that BEGINS a pull**, every
  watcher rolling. Hence the `extracting_qb === null` check ahead of the OS/purpose/
  empty-node check that shares `extract_fails`.
- **Every later turn of a pull, THE IDS ALONE rolls again** (`idsWatchesThePull()`) —
  only while undetected, never on a system with no IDS. A long file is a gamble as well as
  slow, which makes bandwidth a stealth stat. Caught, the ICE wakes and guards the core:
  the pull stops where it was, KEPT, and resumes once the ICE is broken.
- **Bandwidth is read through `DeckTotals::forCharacter()`, floored at one.** The floor is a
  degenerate-case guard, not a balance number.
- **`transfer_gone` is its own log line** — losing a race after a countdown is an ordinary
  outcome, unlike the OS gate `extract_fails` protects.
- **Three kinds, three ways of becoming a possession.** Creds go onto the character. A
  catalogue item goes through the SAME merge-or-create `LoadoutGranter::grantItem()` uses. A
  file becomes one unstackable "Encrypted Data Dump".
- **A file's real numbers live in `inventory_items.extracted_file`, as JSON, on the ROW.**
  `App\Cyberspace\ExtractedFile` is the one author and reader — the shape `DownedCause`
  takes, and for the same reason.
- **Two files never merge**, even though the item is unstackable anyway — a summed quantity
  would destroy information.
- **`RunEnding::Completed`'s trigger is `runs.extracted`**, checked in BOTH doors to leaving
  so they cannot disagree.
- **One line covers every reason a core touch can fail** — unspeakable OS, wrong purpose,
  empty machine. The UI only offers Extract on an extraction run, so the purpose check
  defends against a hand-built request, but it must fail exactly as quietly.

#### Egress

- **LEAVING IS A ROLL.** `Simulator::egressChance()` is public and separate from the roll:
  the button quotes the number the attempt uses. The value is the `egress` stat — a POOL of
  Reflex, Focus and Intellect — so the first skill that helps is a modifier, never a line in
  the simulator. The difficulty is the machine's GRADE plus a term for whatever is fighting
  you (`cyberspace.egress`): tearing free mid-fight is not walking out of a quiet machine.
- **THE SCORE IS ON THE SCREEN, AND SO IS ITS WORKING.** `Simulator::egressReport()`
  assembles one `EgressReport` that the button, the card, the roll and the tests all read;
  `egressChance()` is a field of it. The card is the FIRST renderer of
  `ResolvedStat::$steps` — one line per LAYER, with the contributing sources named.
- **"Evasion" is the capability; "Egress" is the act.** The panel's meter is the CHANCE, not
  the score, because a pool has no maximum. The explanation is one `<template>` cloned into
  the hover layer and the modal both.
- **`character.names` names the two pools**; `egress` reads *Evasion* there.
- **Egress Protocols is the first thing that moves `egress`** — rank 8, behind Cyberdecks 3,
  +3% of base per level as five grants on one row. Arrives by MIGRATION. Nothing in
  `Simulator` learned it exists.
- **A failed egress costs the turn**, so the machine gets its half. **A threshold-forced
  egress rolls too** — a mark is an instruction to try, not a door that opens.
- **Both doors to leaving go through the same roll.** `RunController::egress()` takes the
  ordinary turn and `resolve()` is the shared tail.

#### Burn

- **`App\Cyberspace\Burner` is the only thing in the game that deletes a
  system.** A burn is not a relocation and not a lockdown: the row goes, the
  address goes out of existence with it, and the position it held is simply
  empty until a seeding pass puts something new there. CYBERSPACE.md: *dead,
  then empty, then eventually something new.*
- **It is a core touch**, so it takes the IDS's second look through the shared
  `Simulator::touchTheCore()`, exactly as extraction and an install do. Being
  seen changes nothing about whether the machine burns.
- **One line for every reason it can fail** — a machine already gone, an
  unspeakable operating system, a purpose that is not `RunPurpose::Burn` — the
  same silence `extract_fails` and `install_fails` keep, so the OS gate never
  announces itself.
- **A landed burn is the last turn of the run.** Nothing is left to strike
  back, advance a trace or be shut, so `take()` returns early and the run ends
  `RunEnding::Completed` — the second thing besides `runs.extracted` that means
  *they did what they came to do*.
- **It is why lockdown's fourth trigger needs no code.** `Lockdown::afterRun()`
  looks the machine up by address as the run ends and finds nothing: the
  trigger resolves itself by making its own subject absent.
- **Delete FIRST, then cancel everybody else.** The obvious order is wrong —
  cancelling first walks each other runner's ending through `afterRun()` on a
  machine still standing, and one that had noticed any of them would shut,
  which throws out everybody inside, which re-enters the loop already running.
- **`Simulator::cancelEveryRunOn()` is that shared consequence**, keyed by
  ADDRESS so it can be called after the machine is gone, and taking the
  burner's own run as the exception. Lockdown calls it too — it used to be a
  private method there, and two copies would be two places for the ending order
  to drift.
- **What it costs is heat and nothing else.** The system's grade × 2.5
  (`cyberspace.burn.heat_per_grade`), against a trace's grade × 2 — burning a
  system is always worse than being caught hacking it. A traced burn pays this and not
  the trace's heat as well. No loot, no
  raid counted, no lockdown: the real price is everything the machine would
  ever have been worth again, which is the design's own answer to what a burn
  costs the runner.

#### Attack tags and defeat

- **ATTACK TAGS are what a piece of ICE does WHILE it fights** — the mirror of defeat tags:
  a defeat tag fires once at the end, an attack tag is in force while the thing is awake.
  `ice.attack_tags`, `App\Cyberspace\AttackTag`, `config/cyberspace.attack_tags`;
  `cyberspace:tag-ice` writes both lists.
- **`Ensnare` is the first, and it MULTIPLIES the egress chance** — a quarter off at I, two
  fifths at II, rather more than half at III. A multiplier because every other percentage in
  the game is one. **`CheckRoller` owns the multiplication** (a third argument to `chance()`
  and `roll()`), because the factor applies to the raw curve and the floor applies AGAIN
  after it. Ensnare makes leaving expensive; it can never close the door.
- **It is PASSIVE while the ICE lives and nothing is stored on the run** — read off
  `runs.ice_id`, so there is nothing to expire and no condition outliving its cause.
- **`App\Cyberspace\DefeatResolver` is what makes the tag set real.** Called from
  `Simulator::resolveEnding()` **before** the ejection: damage, consequence, `jackOut()`,
  floor.
- **Flatline deepens the injury; Neural Shock lands on the body.** Flatline rolls its
  percentage against maximum SI **as it stands right now** and writes through
  `InjuryLedger::inflict()` — the same *neural damage* row a freeze deepens; current SI
  clamps on the save that follows, via the model's hook. Neural Shock goes through
  `DamageResolver` with `DamageOrigin::Ice` and touches no maximum. **Flatline deliberately
  does NOT come through the door** — mitigation has nothing to say about an injury.
- **Surge deletes the module outright**, chosen from what is CURRENTLY fitted;
  `deck_fittings.module_id` cascades. One roll per module the level names; a module already
  taken cannot be taken twice. No deck, or nothing fitted, is a no-op.
- **Erase draws from storage AND whatever is running — deliberately wider than
  `inventoryIn()`.** A running row is stopped through `ProgramRunner::stop()` before it is
  deleted.
- **Skim reaches `creds` directly**, floored. Bounty never appears in this list — it is
  rolled onto the MACHINE, so `DefeatResolver` returns null for it honestly rather than
  omitting the case.
- **`DownedCause` has an `effects` field.** `tags` is names; `effects` is the resolver's
  rendered account. The waiting screen prefers `effects` and falls back to tag pills for a
  cause built before `DefeatResolver` existed.
- **`cyberspace:tag-ice` is a console command, not a migration** — a fresh clone's ICE
  carries no tags until it is run, so tests write tags onto the row directly.

### Being on the floor

**Reaching 0 in either resource puts a character on the waiting screen** —
`App\Characters\WaitingRoom`.

- **The wait is NOT the punishment.** What losing costs is whatever the defeat tags carried;
  the minute is there so a defeat carrying nothing still costs something. A long wait would
  punish the player who lost to a harmless guard hardest.
- **Zero to one, and no further.** Getting up is not healing. **Both resources come back if
  both were empty**; one that was not empty is left exactly where it was.
- **Released lazily, with no scheduler** — `EnsureCharacterIsStanding` asks on the way past.
- **A second hit does not restart the clock.**
- **It runs BEFORE the run lock.** If they overlap, being unconscious wins.
- **`ReadinessWatch` is its first caller**, and the screen comes after `jackOut()` — a
  character must never sit on it while holding a position in cyberspace. **One point can do
  it.**
- **`characters.waiting_cause` is JSON, temporary on purpose.** Columns modelled against an
  unwritten simulator get modelled wrong. `App\Characters\DownedCause` is the single author
  and reader. **Every field is optional.**

### Recovery

- **Nothing heals by waiting, and `synaptic_regen` floors at 0 to say so.** The single
  exception is **Neuroplasticity** (rank 16), a point a minute per level. Per MINUTE so
  anything crediting it can work off elapsed time. **`characters:regenerate` credits it
  every minute** and keeps NO stored clock — a minute lost to a restart is a point. It heals
  from ZERO.
- **A dose can carry `synaptic_regen` too, and that is how healing is SOLD.** Rewire
  (nootropic, by migration) is +2 a minute for ten minutes, −8% Intellect and Focus while it
  runs, tolerance 10 a dose, not addictive. **Two a minute and not one because regen
  FLOORS**: tolerance scales one point to 0.9, which floors to nothing. Tolerance scales
  only the positive amounts, so a tolerant user heals at half the rate and pays the full
  side effect.
- **`Character::recover()` is `takeDamage()`'s mirror** — addition and a ceiling. **Capped
  at the maximum as it is NOW**, which is why it cannot be damage with a minus sign: healing
  must never climb past what injury lowered.

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
- **`characters.creds` is the first money in the game**, and nothing spends it. No derived
  maximum, so it is not in `Character::RESOURCES` and is not a stat.
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

### Missions

**Work done for an entity: a designated set of systems, an action required of
each, and a clock.** `App\Missions\MissionBoard` rolls them,
`App\Missions\MissionLedger` does everything afterwards, and the two never
overlap.

- **A BOARD IS ROLLED PER CHARACTER AND NEVER SHARED**, refreshed on the hour
  and **lazily** — `Probe::settle()`'s shape rather than a sweep, because a
  scheduled refresher would roll boards for every character at every desk they
  have ever walked past. Nobody can take your job and two players at one desk
  see different work.
- **Declining DELETES the offer**, and it stays gone until the hour comes
  round. That is what makes turning something down a decision. An offer nobody
  took is not history; only accepted work has a story worth keeping. **An empty
  board is stale by definition**, which is what makes the floor work — somebody
  who declined everything gets a fresh roll rather than an empty desk.
- **Four actions, and each is a different kind of EVIDENCE.**
  `mission_targets.satisfied_at` is one column filled several ways, and an
  action is what decides which way. A **survey** carries files in and spends
  them at the desk; a **demolition** and a **placement** are satisfied by the
  act, in the transaction that performs it; **supply** names no systems at all.
- **The act reaches the ledger, not the other way round.** `Simulator` calls
  `MissionLedger::recordAgainst()` inside its own transaction, and it is
  deliberately quiet: a runner who burns a machine they happen to owe somebody
  is not interrupted to be told, and the desk is where they find out it
  counted.
- **A PLACEMENT IS NOT AN INSTALL.** No `backdoors` row is written for the
  runner — the door belongs to the entity, so it pays nobody nightly, is not a
  routing hop, never enters `ChainBuilder`'s chain, and none of the four things
  that take a backdoor away has anything to take. **The flag on the target row
  IS the record.** It is still an install for the one consequence that is not
  about ownership: `runs.backdoored` is set, so the machine does not lock down.
  A run installs once, so a run spent planting for somebody else is a run not
  spent planting for yourself.
- **Three endings and three prices.** Completing pays; failing charges;
  **abandoning charges exactly what failing charges**, because if giving up
  were free nobody would ever do it — they would sit on the job and let it run
  out. **Voiding costs nothing at all.**
- **A target that stops existing VOIDS the job**, detected by the snapshot
  rather than watched: the id resolves to nothing, or to a row wearing a
  different address. A lockdown's close is a relocation, so it is the same
  case.
- **`mission_targets.system_id` NULLS and never cascades.** A burn deletes a
  system, and a cascade would take the target row with it — leaving a job with
  nothing outstanding, which reads as *finished*. The row has to survive its
  own subject. **The general rule: a row that exists to REMEMBER something must
  not cascade from the thing it remembers.**
- **The rewards are snapshotted at the OFFER**, so retuning
  `config/missions.php` never changes a job somebody already took. The same
  call `market_purchases.unit_price` makes.
- **The reward is a RESOLVED stat and the penalty is not.** `standing_gain` is
  declared in `config/stats.php` and deliberately NOT in `stats.bases` — there
  is no such thing as a character's standing gain in the abstract, only what
  this job at this desk was worth — so the base is handed in at the call site
  the way `scan_duration` is. The penalty is flat: being bad at consequences is
  not a thing to be good at.
- **One active mission per ENTITY**, not per character. Three desks can have
  you working at once; one agent cannot hand you two jobs.
- **The window is 4–24 hours and starts on ACCEPT**, never at the offer.
  `accepted_at` and `expires_at` are null together and set together, which a
  CHECK enforces. **It must stay under `cyberspace.scan.lifetime_hours`** or a
  file's expiry becomes a second, invisible deadline.
- **`missions:expire` sweeps every five minutes**, the lockdown sweep's cadence
  and its reason: the penalty has to land whether or not the player ever comes
  back. A job whose target has gone voids rather than fails, even there.
- **Supply spends complete, unexpired files only**, and the target rows are
  written AT HAND-IN as the receipt — one per file accepted — so the job still
  records which systems were sold. Asking for more than is held is refused
  rather than partly taken.
- **The same gate guards the work and the shelf.** `exchanges.min_standing` is
  read by both: an agent who will not sell to somebody will not employ them
  either.
- **There is no mission index and never will be.** A board belongs to a DESK,
  and a desk is reached by standing on it — the whole of the permission, the
  same call the run briefing makes.

### The inbox

**A notification is something that happened WHILE NOBODY WAS LOOKING.** That is the whole
test for whether a sentence belongs here: a flash message already covers everything the
player just did, and the inbox covers what the world did on its own. Three TODO entries
stood on this not existing.

- **`App\Notifications\Notifier` is the only writer of `notifications`**, and the only
  thing that marks one read. A sender says what happened and hands over a strings KEY;
  the sentence is rendered once, at write time, and stored.
- **The body is never recomposed.** A message outlives its own subject — a machine
  relocates, a skill is renamed, an achievement is retired — so a stored sentence is the
  only one that is still true tomorrow. Same call `system_contacts` makes about a sighting.
- **It belongs to the ACCOUNT**, like achievements, and `Notifier::send()` takes a
  `Character|User` because every sender holds a character and `characters.user_id` is a
  column rather than a query.
- **`App\Notifications\Category` says where a message came from and never how loud it is.**
  Four cases, each with a sender that runs today: `Cyberspace`, `Training`, `Achievement`,
  `Street`. **`route()` may be null** — a message about the street has nowhere to lead until
  there is a street, and the row stops being a link rather than pointing somewhere unrelated.
  **A case without a sender would be a filter with nothing to filter.** There is no
  severity and no colour on them — the kit's aliases mean good, careful and bad, and
  painting Training green would say something untrue about it. The category also decides
  where reading one takes you (`route()`), so no URL is ever stored.
- **Five senders, and all five are things nobody pressed a button for**: a read finishing
  (`Probe::resolve()`), a backdoor found by the nightly roll (`cyberspace:age-backdoors`),
  a door lost to somebody else's lockdown, a relocation or a burn
  (`Backdoors::loseAllOn()`, which a burn asks before its cascade could say nothing),
  a skill crossing a level (`App\Listeners\NotifyOfSkillLevel`), an achievement unlocking
  (the tail of `Achievements::check()`, where `insertOrIgnore`'s row count is what makes
  "said once" true without a column recording it).
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

- **One book per skill** (`items.use_skill_id` unique), or `Imprint::forSkill()` is
  ambiguous.
- **A container cannot stack**, nor a cyberdeck — `stack_key` would merge two stackable
  containers and lose the second one's contents.
- **No prerequisite cycles.**
- **A deck's socket counts** — facts about a set of rows; a CHECK sees one.
- **A granted skill level must be reachable** — a walk of the prerequisite graph.

**`App\Skills\PrerequisiteGraph` is the one graph walk.** Pure (ids, never models), so the
editor can ask what *would* happen (`->with($id, $requires)->cycleThrough($id)`).
`skills:validate` uses the same class.

**`App\Inventory\Imprint` is the one derivation.** Creating a skill creates its book.
`ItemSeeder` calls the same class. What follows the skill afterwards is narrow: **`base_cost`
and `on_market` stay in step on every save; `tier`, `rarity` and `seed_quantity` are derived
from rank once, at creation.**

**Deletes are real and unguarded; the confirmation is the only brake.** No soft delete, no
undo. `App\Admin\DeletionImpact` counts what a delete would reach — inventory rows, player
copies, shelf stock, purchase history, container contents, character progress, queued
training, requirements naming it — and the dialog spends that count before offering the
button. It is a description, never a guard. To retire content, set `on_market = false`.

**A skill's book must be deleted first.** `items.use_skill_id` is `restrictOnDelete`; the
controller drops the book in the same transaction. Everything else cascades.

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

### Cutting a release

**Work on `main`. Branching is the release script's job.**

```bash
bin/release                  # asks which version, showing the latest tag
bin/release 0.2.3
git push origin main --follow-tags
```

`bin/release X.Y.Z` moves the commits since the last tag onto a feature branch, drafts the
changelog entry, opens `$EDITOR`, then commits, merges and tags. Run with no version it
reports the state and proposes the version the schema rule implies. `--branch NAME`,
`--no-branch`, `--no-tests`, `--no-merge`.

- **Branches the work backwards** — `main` rewinds to the tag and the release returns as a
  `--no-ff` merge, so history reads as though it had been branched from the start.
- **Suggests the branch name from the commits** (commonest conventional-commit scope wins).
- **Refuses to rewind a `main` that has been pushed** — it names `--no-branch` instead. The
  check reads the last fetch, so `git fetch` first.
- **Deletes the branch it made**, after merging.
- **Drafts, never writes.** Grouped `feat`→Added, `fix`→Fixed, rest→Changed, with a DRAFT
  marker it refuses to proceed past.
- **Enforces the schema rule** — new migrations are listed under **Migrations**, and a patch
  release that adds one must be confirmed.
- **Tags on `main`**, after the merge.
- **Restores the tree and the branches on any exit that is not a successful commit.**
- **Never pushes.**

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

**`.github/workflows/manual.yml` is dead and should go.** It POSTs to
`https://dedlok.online/deploy.php` on any tag push, and `deploy.php` has been removed —
so it now fails on every release rather than doing anything. The polling worker replaced
it.

**`APP_DEBUG` stays `false`.** Turning it on is the fastest way to read a production
fault and it prints the environment to the public internet; turn it back the moment the
trace has been read.

## Constraints imposed by php.ini

`docker/php/php.ini` is hardened and several settings break otherwise normal code:

- `open_basedir` is **not in php.ini**. It lives in `docker/apache/vhost.conf` as
  `php_admin_value open_basedir "/var/www/cyberpunk:/tmp"`, so it binds the web SAPI only —
  applying it to the CLI broke Composer and Artisan and bought nothing. `php_admin_value`
  cannot be overridden by `.htaccess` or `ini_set()`.
- `allow_url_fopen = Off` — `file_get_contents()` cannot fetch URLs. Use cURL.
- `disable_functions` blocks `exec`, `shell_exec`, `system`, `passthru`, `popen`, `symlink`,
  `parse_ini_file`, `show_source`. `PHP_INI_SYSTEM`, so it binds the CLI too: **`php artisan
  storage:link` will fail** — create `public/storage` from the host with `ln -s`.
- `register_argc_argv = Off`, but the CLI SAPI hard-overrides it to On, so Artisan works.
- `display_errors = Off`; errors go to `/dev/stderr` (`docker compose logs web`).
- Sessions use strict mode, cookie-only IDs, HttpOnly, SameSite=Lax.
  `session.cookie_secure` is commented out for local HTTP — **enable it before any HTTPS
  deployment.**

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

**Re-verified 2026-09-21, and the 2026-09-01 audit's worst findings are fixed.** It was
`ALL PRIVILEGES ON *.*` — `SUPER`, `FILE`, `SHUTDOWN`, `CREATE USER`, `PROCESS` — on an
account that could also see two unrelated production schemas. None of that is still true,
so the warning that used to stand here does not.

- **`dedlok@%`**, with global privileges of **`USAGE` only**.
- **`ALL PRIVILEGES` on `cyberpunk`.\* and nowhere else.** `SHOW DATABASES` returns
  `cyberpunk` and the two system schemas; `gryningsland` and `lod` are out of its view.

Two findings from that audit still stand, and they are what is left to do:

- **The host mask is still `%`** — connectable from any host on the internet.
- **The connection is still unencrypted.** `Ssl_cipher` is empty on a live session and
  `require_secure_transport` is 0, so credentials and every query cross the internet in
  clear text. Enable TLS (`DB_SSLMODE` / `MYSQL_ATTR_SSL_CA` in `config/database.php`)
  before this goes anywhere near production.

**Never run `migrate:fresh`, `migrate:reset` or `db:wipe` against the remote.** Locally they
are harmless now — the app's own connection IS the container, and `bin/pull-db` puts the
data back.

## Dependencies

Laravel 13 with **Eloquent**. Doctrine DBAL is gone; Monolog and `vlucas/phpdotenv` are
present only as transitive dependencies — use `Log::` and `config()`.

`roave/security-advisories` is installed, so a `composer require` pulling a package with a
known CVE fails. Resolve by upgrading, never by removing the constraint.
