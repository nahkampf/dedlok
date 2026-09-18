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

MECHANICS.md has a status table saying exactly which parts exist, and it is the
thing to check before describing any of this as working. The resolver, the
dependency graph, the character sheet and five modifier sources are built; what
rolls a check, and most of the content that would feed one, are not.

### Rolling a check

**`App\Checks\CheckRoller` is the only die in the game**, and it is separate
from `App\Stats\Resolver` on purpose: a resolver that rolled could not be
asked what a character's chances ARE without spending them.

- **A logistic on the MARGIN** — `1 / (1 + exp(-(value - difficulty) / spread))`
  — so only the gap counts, and equal value and difficulty is always a coin
  flip. That is what makes a difficulty mean something: it is the level at
  which a character is evenly matched.
- **`checks.spread` is how decisive the whole game is, in one number.** It is
  unmeasured; nothing has been balanced against it.
- **No check is ever certain.** Every roll is clamped into
  `[floor, 1 - floor]`. A kind of roll may name its own floor in
  `checks.floors` — **config and never an argument**, because a floor passed at
  a call site is a rule you can only find by reading every caller.
- **`chance()` is public and separate from `roll()`**, so a screen that warns
  "about one in three" and the roll that follows cannot disagree.
- **The die is injected**, like the market's and the grid's. Pin it in tests.
- **The first caller is the probe**, against a difficulty per system grade —
  so a grade is the first difficulty in the game, and `scan` the first kind a
  floor could be keyed on.

### Where a modifier comes from

**`Character::modifiers()` is the whole list, and each entry is a
`ModifierSource`.** Five of them today, and the resolver learns nothing new when
a sixth arrives — that is what the interface is for.

| Source | Is |
| --- | --- |
| `ArchetypePerks` | the role's live perks. Permanent by construction |
| `RunningPrograms` | software running on the equipped deck |
| `SkillEffects` | what KNOWING a skill grants, from `skills.modifiers` |
| `InstalledImplants` | what is in the body, from `items.modifiers` |
| `CharacterModifiers` | injuries and statuses, from the `character_modifiers` table |

- **A thing with a row carries its modifiers ON that row.** `items.modifiers`
  and `skills.modifiers` are JSON columns, which is why there is no
  `config/implants.php` and no `skill_effects` table. **`config/effects.php` is
  only for causes with no row of their own** — an injury, a status, a lingering
  effect, things that merely happen to a person and have nothing to hang from.
- **Definition keys in `config/effects.php` are permanent.** Rows point at them,
  so a deleted key orphans live data. Retire one with `retired => true`.
- **A per-instance amount is the string `':amount'`**, filled from the row's
  `data`. One definition covers every depth of neural damage rather than one
  definition per depth.
- **Stacking belongs to the DEFINITION, and collapses at READ time.**
  `App\Stats\Stacking` has four answers because the right one depends on the
  fiction — two doses of a stimulant add up, two implants doing one job do not.
  Never at write time: "the strongest one wins" stops being true the moment the
  strongest expires.
- **`App\Stats\InjuryLedger` is the only writer of an accumulating injury**, and
  it DEEPENS one row rather than adding a second — -5 neural damage plus -3 is
  one row at -8. That is what lets a deck freeze, an ICE flatline and a future
  overdose write into one injury without knowing about each other, and it is
  what makes healing one number to reduce. A row that reaches zero is deleted.
- **Resolution can be asked AS OF a moment.** A training catch-up covering a
  window in which a drug wore off credits the bonus only for the part that had
  it: `TrainingDeposit` cuts the segment at every modifier change and
  `CharacterModifiers` filters rows to that instant rather than trusting a
  sweep to have been on time. Sources that cannot honestly look back say so —
  nothing records when a program STOPPED.
- **Damage is a stat too, and its base arrives with the event.** One stat per
  resource and origin (`damage.synaptic_integrity.ice`), generated from two
  enums by `App\Stats\DamageStats`, each tagged with BOTH axes plus `damage` —
  so a resistance is `percentage_of_base -15` against
  `all_of: ['damage', 'ice']`, immunity is `multiplier 0`, and a vulnerability
  is the same sentence with a sign. Percentages sum and apply once, so two −30%
  resistances are −40% rather than 49%. `DamageResolver` is the only door, and
  `taken` is for saying out loud — never for deciding whether anything
  happened, since a hit mitigated to nothing still landed.
- **Build a `Sheet` and read it; do not call `modifiers()` per stat.** Four of
  the five sources query — only `ArchetypePerks` reads the cached roster. A
  sheet is bound to the context it was built in and is cheap to rebuild rather
  than hold.

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

### Skills, and the training queue

**`/skills` is a character's own skills and nothing else** — what they know,
what is training, and the queue behind it. It was the CATALOGUE once: every
skill in the game drawn as one tree per category, public because it described
the game rather than a person, with a player's own levels laid over the top.
That page is gone, along with `SkillTree`, `SkillTreeNode` and
`<x-skill-node>`. An overlay on somebody else's list was a worse way to read
your own rows than a list of the rows you actually have. The route moved
behind `auth` with the rest, so `RequireCharacter` guarantees a character and
nothing on the screen asks whether there is one.

**HOW A PLAYER FINDS OUT A SKILL EXISTS IS THE MARKET, not a catalogue.** A
skill is learned from its book and there is exactly one book per skill, so the
shelf IS the list — and it is deliberately not the whole list. **A skill that
is off the market is content with no shop entry on purpose**: quest rewards
and the like, found rather than bought, and `Skillbook` keeping `on_market` in
step with the skill on every save is what makes that one decision rather than
two. A page enumerating every skill in the game would have undone exactly that
— it would have published the list of things a player is supposed to discover.
The skill EDITOR is where an admin sees them all, and that is the only place
that should.

- **`App\Skills\TrainingQueue` is the only writer a PLAYER reaches.**
  `TrainingDeposit` writes these rows too — it retires a finished entry and
  renumbers what is left — but that is the clock moving rather than somebody
  deciding. Injecting is the gate in front of the sheet; this is the gate in
  front of the queue.
- **A player picks a SKILL and never a level.** An entry is a (skill, target)
  pair and targets have to be consecutive, so the next level up — counting
  what is already QUEUED, not only what is reached — is the only entry the
  validator would accept. Pressing the same button five times is how a skill
  gets taken to level 5, and the count is why: two presses that both computed
  the same level would collide on the unique (character, skill, target_level)
  index. Locked for the duration, so two taps cannot race it into a 500.
- **The picker offers exactly what the writer would accept**, doing the same
  sum in `SkillsController::queueable()`. Offering an option that comes
  back refused is worse than offering none.
- **An unmet prerequisite is a WARNING and never a refusal.** A queue is
  allowed to describe training that cannot happen yet — that is the whole
  point of ordering it, and it is what lets Codebreaking sit behind the
  Hacking that unlocks it. `TrainingQueueValidator` already walks the queue
  forward from what the character knows and says what will stall; until this
  screen existed, the only thing that ever read it was `skills:validate`,
  which nobody playing the game runs. `QueueRefusal` therefore has three cases
  and none of them is about requirements.
- **Removing one is allowed to break the rest**, deliberately: dropping
  *Hacking I* out from under *Hacking II* leaves a queue the validator
  complains about, and the alternative is a delete that silently takes three
  other entries with it. **The deposit runs first**, inside `remove()` rather
  than at a call site, because removing the entry at position 1 throws away
  its clock and with it up to a minute of accrued training.
- **Renumbering is ascending**, since `(character_id, position)` is unique and
  each row has to move into a slot the row before it has already vacated —
  the same walk `TrainingDeposit::completeEntry()` makes.
- **The estimate is the rate it earns THIS INSTANT.** A drug wearing off
  tonight moves it, and the screen does not pretend otherwise: modelling
  future modifier changes would be a second, worse copy of `TrainingDeposit`'s
  segment walk and would still be wrong the moment anything was installed.
- **What is training wears the kit's `.dot--live`** rather than a colour of
  its own. Ember, one per screen, which is what the accent is rationed for —
  at most one entry trains at a time, by definition.

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
- **`on_market` is the SEEDER's question, and it will not be the player's.**
  Players are meant to list their own goods eventually — skillbooks among
  them, which is how a book for an off-market skill can still change hands —
  and a listing somebody wrote is not the world deciding to stock a thing.
  When that lands it is a table of offers, not this flag: a seeder that
  restocked player listings would be manufacturing goods out of nothing.
  **A listable book is an UN-INJECTED one, and that needs no rule** —
  `SkillInjector` consumes the copy it spends, so a book still sitting in a
  stash has by definition not been used. Nothing has to check, as long as
  injecting goes on consuming.
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
  `scopeLoose()` now leaves out four things that really are elsewhere: rows
  inside a container, modules fitted into a socket, programs that are running,
  and implants installed in a body. All four decided in one place.
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
- **Readiness gates jacking in and starting a program, and nothing else.**
  `DeckTotals::isReady()` is the whole check;
  `App\Cyberspace\Navigator::jackIn()` and `App\Programs\ProgramRunner::start()`
  are the two callers — a machine missing its NIC does not get to run a
  decryption either. Fitting and equipping are never refused for it: you build
  a deck while carrying it. CYBERSPACE.md names one more caller, the start of every
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
  The other half — losing readiness while inside, which ejects for 1 point of
  Synaptic Integrity — is `App\Cyberspace\ReadinessWatch`, under *Cyberspace*
  below.
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

### Programs

**Software is an item with a `programs` row**, exactly as deck hardware is an
item with a `deck_modules` row. No column on `items` says which — a row
carrying costs and a kind is what being a program means, and one place to look
beats two that can disagree.

- **Three costs, because they are paid at different times.** `storage_size` is
  paid by OWNING the file; `qflops_cost` and `memory_cost` only while it RUNS.
  That is the whole reason storage is a measure of its own: a deck can be full
  without being busy and busy without being full.
- **There is no "loaded".** A program is running or it is not.
  `background_process` says which a program is ALLOWED to be — a decryptor
  grinds away while its owner is signed out, an icebreaker is meaningless
  outside a run — and the refusal for getting it wrong is
  `ProcessRefusal::RunOnly`.
- **Running is two columns on the inventory row** — `running_on_deck_id` and
  `started_at`, null exactly together — and never a join table. A fitting needs
  three references because a socket is a place; a process has none, since a
  program runs on a deck full stop. **It also has to be on the row**:
  `stack_key` is derived from the row on every save and the rule is that IDLE
  copies stack while RUNNING ones do not. A flag in another table would be
  invisible to that derivation, and two running icebreakers would silently
  merge into one row with a quantity of two.
- **`App\Programs\ProgramRunner` is the only writer of those two columns.**
  Starting takes one copy off the pile and gives it a row of its own; stopping
  puts it back and MERGES into the pile if one is there. The merge is not
  optional — `stack_key` is unique, so a stopped row whose key matches an
  existing pile collides with it.
- **Always the equipped deck**, which is why `start()` takes no deck argument.
  It follows that unequipping stops everything, and so do fitting, stripping
  and destroying the deck: `stopAll()` is called by `DeckEquipper`,
  `ModuleFitter` and the destroy path.
- **`running_on_deck_id` is RESTRICT, and that was forced rather than chosen.**
  `ON DELETE SET NULL` is refused by MySQL (error 3823) because the column
  appears in a CHECK constraint — and it was right to refuse, since nulling the
  deck id would leave `started_at` behind, which is the state the "both or
  neither" check exists to forbid. CASCADE was never an option: losing a machine
  must not delete the software that was running on it. So the destroy path stops
  the processes first, inside the same transaction, the way a skill's book is
  dropped before the skill.
- **Compute and memory are measured against the deck's RESOLVED totals**, so a
  skill that frees up compute lets a player run one more program without
  `ProgramRunner` knowing that skills exist.
- **The kind is asked about BY NAME and is not derived from the modifiers.**
  Five of them — icebreaker, decryptor, evasion, analyser, daemon — because a
  program is a gate as much as a modifier: no amount of bonuses adds up to
  having a running icebreaker. A sixth is an enum case, a line in the CHECK, and
  a string, the same shape `App\Decks\Hardware` has.
- **`programs.modifiers` is nullable JSON, and null is ordinary.** A plain
  icebreaker genuinely modifies nothing, and `[]` and "nothing" should not be
  two ways of writing one fact. `App\Stats\Sources\RunningPrograms` reads them,
  and **only the equipped deck's processes count** — a character may leave
  programs running on a spare deck, and it costs that deck's budget rather than
  changing their sheet.
- **`ProgramEditor` is the only writer of the `programs` table**, and like
  `HardwareEditor` it always clears the side an item stopped being. Nothing to
  diff, unlike sockets: a process points at an INVENTORY row and never at the
  definition, so replacing it wholesale strands nothing.
- **How many copies of one program a character may run is `program_threads`.**
  One by default (`config/programs.php`), raised by the Multithreading skill,
  and it is a **ceiling per program rather than a total** — two threads means
  two scanners or two daemons, not two processes. `ProgramRunner` counts
  running rows of the same item across every deck, because a thread is
  attention and attention is the person's.
- **`programs.multithreaded` decides whether more than one is a question at
  all.** A property of the SOFTWARE: an icebreaker fighting two pieces of ICE
  at once is not a thing skill should be able to buy, so a program without the
  flag is capped at one however good its owner is. False by default, and the
  item editor exposes it.
- **`$duringRun` is the gate nothing opens yet.** A run-only program is started
  BY the intrusion simulator and never by a player pressing a button, and the
  simulator does not exist — so today only background programs can be started
  at all. Jacking into cyberspace is NOT a run and must not pass `true`.
- **Storage is stored and not enforced.** `deck_storage` is a measure and
  `DeckRequirement` has no storage case, so owning more software than the deck
  can hold currently costs nothing.
- The screens: the topbar carries a COUNT and a modal (width is the constraint
  there, see *The page frame*), the deck screen has the Running and Storage
  tabs — storage split into two grids because a program and a file share an
  inventory without being the same kind of thing — and `inventory.run` is one
  route in both directions, like `deck.equip`.

### Implants

**An implant is an item with a body to go in.** The same shape as a module and a
deck — a definition row, an inventory row, and one writer class — applied to a
person.

- **Five regions and eleven slots, as a fixed vocabulary and not rows.**
  `App\Implants\BodyRegion`: head 2, body 4, arms 2, legs 2, spine 1. Every
  character has the same body, which is the OPPOSITE of a cyberdeck — different
  decks present different sockets, so those are rows — and it is why there is no
  `implant_slots` table beside `deck_slots`.
- **Capacity belongs to the REGION, and is counted rather than stored.** Four
  body slots means four altogether, however they are filled, the same rule a
  deck's expansion slots follow. A stored count is a number that goes wrong the
  first time a row is deleted around it.
- **`items.implant_region` is the only place the region is written down**, so a
  fitted row can never disagree with its definition about where it went — the
  lesson `deck_modules` learned by carrying the numbers and not the word.
- **`inventory_items.installed_at` is the one column that decides whether an
  implant is doing anything**, and `App\Implants\ImplantFitter` is its only
  writer. An implant in the stash does nothing and one inside a memory shard
  does nothing; neither can be installed without being taken out first.
- **Installed is a fourth way to be elsewhere.** `InventoryItem::scopeLoose()`
  now leaves out four things: inside a container, fitted into a socket, running
  on a deck, and installed in a body. All four decided in one place.
- **An implant's effects live on `items.modifiers`**, read by
  `App\Stats\Sources\InstalledImplants` — there is no `config/implants.php`,
  for the reason a program has no config either.
- **Surgery is free and nothing charges for it yet.** Installing and removing
  are both surgery in the fiction; when something costs, `ImplantFitter` is the
  class that knows the character and the implant at once.
- The screen is `<x-body-map>`: a Vitruvian figure with the regions boxed off
  it, the deck board's device applied to a person.

### Cyberspace

**`CYBERSPACE.md` is the document** — the grid, the systems on it, the run, and
the phase order it is built in. **Phase 3, the grid, is done**: there is a world
and it can be walked. **Nothing can be entered**: no scan, no intrusion, no
combat, and nothing inside a system. That is phase 4 onward, and the document
carries the detail.

- **`App\Cyberspace\Grid` is the ONE place that knows how the world is stored,
  and nothing else may query `systems` by position.** It is a table of
  materialised rows today and a generator with override rows at 2048 x 2048,
  where 4.2 million systems stop being a table. The day that lands, this class
  changes and nothing else does.
- **A position is where something is; an address is what answers.** Eight hex
  characters, random, unique and MEANINGLESS — a position cannot be read out of
  one, which is what makes trading addresses harmless and expected. **Addresses
  do not survive**: a burned system takes its address out of existence and a
  relocated one comes back wearing a new one, so everything that stores one — a
  bookmark, a contact — must treat "nothing there" as an ordinary answer.
- **Discovery is two records and one act.** Terrain is *I have looked here*: a
  bitmap, 512 bytes per 64 x 64 chunk ENTERED, never a row per position.
  `App\Cyberspace\Chunk` is the only arithmetic that turns a position into a
  byte and a mask, and nothing else may touch `character_terrain.bits`. Contact
  is *what was standing here*, and it is allowed to be wrong.
- **`system_contacts` is a SIGHTING and carries no `system_id`**, deliberately.
  It has to outlive the machine it describes, because a machine that relocated
  or burned is the case the record exists to be wrong about. The map a player
  sees is their memory, not a live feed. Rewritten in place inside the swept
  window, so a position now empty FORGETS what was there —
  `SystemContact::standingIs()` is where "is this still true?" is asked, and
  none of its three answers is an error.
- **`App\Cyberspace\Navigator` is the only writer of `characters.grid_x` and
  `grid_y`.** Having a position IS being jacked in: no session row, no flag,
  nothing that can disagree with the coordinates. **Every arrival sweeps**, in
  the same transaction as the move — a player who moved and did not see is a
  player whose map went stale in front of them.
- **Readiness gates jacking in**, through `DeckTotals::forCharacter()`, so the
  gate and the deck screen cannot disagree about a deck. Starting a program is
  the second caller — see *Programs* — and `ReadinessWatch` the third. The deck
  lock that follows from being inside is under *Cyberdecks and modules*, above.
- **A deck that stops running throws its owner out**, for 1 point of Synaptic
  Integrity and no defeat effect. `App\Cyberspace\ReadinessWatch` is the rule
  and it is asked in TWO places on purpose: **in place** by the screens that can
  break a deck from inside — implants and programs, neither of which the deck
  lock forbids — so the player is told as it happens, and by **middleware**
  (`EnsureDeckStillRuns`) on every jacked-in request as the net under
  everything else. The order is the one every bad ending uses: damage, then
  consequence, then `jackOut()`. **It must never become the answer for
  something happening during a run** — a burnout or a Surge resolves its own
  ending where it happens.
- **The damage it deals is RESOLVED, like every other number.**
  `App\Stats\DamageResolver` is the door — a hit is
  `Damage::synapticIntegrity(1, DamageOrigin::ReadinessLoss)` and what it costs
  comes back through the resolver, so a resistance is a modifier, never code. See *Game mechanics*,
  and MECHANICS.md's *Damage is a resolved stat*. `Character::takeDamage()` is
  the dull half underneath it — a subtraction and a floor — and calling it
  directly skips every rule. Reaching zero is not acted on: there is no waiting
  screen yet.
- **A waypoint bookmark may only be set on swept ground, and
  `App\Cyberspace\BookmarkKeeper` is where that lives** — it is a fact about a
  bitmap in another table, so no CHECK can reach it. Without it, coordinates
  become tradeable and the map is solved by whoever posts a list. **A SYSTEM
  bookmark has no such gate**, because an address carries no position: it is a
  door rather than a map.
- **Every machine has an owner, and `corps` arrives by migration** like the
  archetype roster — an owner id ends up in thousands of rows, and faction
  standing keyed by a varchar falls apart the first time somebody fixes a typo.
  The eight hardware manufacturers in WORLDBUILDING.md are deliberately NOT
  corps: who BUILT a thing and who RUNS it are different questions.
- **A name is composed, never stored.** `<Type> - <corp> NNN`, out of columns
  the row already has; `systems.name` is the AUTHORED-name override and is NULL
  on nearly every row, and `systems.designation` is the only stored part
  because it is the only part that is nowhere else. Renaming a corp renames
  every live machine it runs and renames nothing a player remembers.
  `App\Cyberspace\SystemNamer` is the one place that decision is made.
- **An UNSCANNED grade is not visible from the map**, which is a rule and not
  an omission: a sweep is free, so nothing may put a grade into a contact. **A
  scanned one is drawn** — a bar at the foot of the cell, `--g` wide — because
  the map is where the minutes and the instrument pay off. A cell can only get
  a grade from a live `system_scans` row, which is what keeps that honest.
- **`GridSeeder` tops the world up TO a target**, the way `MarketSeeder` does —
  so running it twice is not two worlds and a pass over a burned patch replaces
  exactly what was lost. Occupancy leaves about 30% of the grid empty on
  purpose: that reserve is what relocation and instantiation move through.
  `cyberspace:seed` is run BY HAND and is not scheduled, unlike `market:seed`;
  `--dry-run` decides everything and writes nothing.
- **Every machine runs an operating system, as a foreign key to the skill that
  gates it** — `systems.os_skill_id`. A word would be a second spelling of a
  row that already carries the name, the description and the book; a fifth
  operating system is a skill row and a weight. Which one a machine runs
  follows its grade through the skills' own RANK, so that pairing is nowhere
  written down twice.
- **Scanning is `App\Cyberspace\Probe`, and it reads the machine you are
  standing on.** No target in the request: you cannot read a machine you are
  not at. One press starts a READ, a pass rolls once per thing not yet known,
  and the budget is the `scan_passes` stat — **a failed scan costs the expiry
  window, not a retry**, which is what stops the act from being ground.
- **A read goes round by itself.** A pass that matures without completing the
  file starts the next one with the same instrument, until the picture is whole
  or the budget is gone. Pressing Scan five times was never a decision — what
  it bought was a screen that had to be watched for something it could do
  itself. **Nothing about the cost moved**: passes are still spent when begun
  and still capped, and stopping the scanner still ends the read, because that
  is the one thing the player chose.
- **A pass takes MINUTES** — one against a grade 1, sixteen against a grade 9,
  scaled by the `scan_duration` stat so skill and a better instrument cut it.
  **The pass is spent when BEGUN** and **the roll is made when it MATURES**, so
  nothing is decided in advance waiting to be revealed and walking away from a
  half-finished read costs it. **Settling is lazy**: the map resolves anything
  that matured on the way in, the way a training deposit catches up, so no
  scheduler has to have been running.
- **A READ CATCHES UP; it does not advance one pass per visit.** Lazy settling
  used to mean exactly one pass resolved however long the player was away —
  press Scan, come back an hour later, find that a five-pass read had managed
  two. That made *one press is one read* false in the only way that matters: it
  needed five presses to go round, and calling them page loads did not help.
  `Probe::settle()` walks the clock forward instead, and **each pass begins when
  the one before it MATURED rather than when somebody opened the screen** — so
  an hour away is an hour of reading. It terminates because every turn either
  stops the read or begins a pass whose maturity is strictly later, and the
  budget is finite. **A lost pass still stops the loop**, because spending the
  next one is the player's decision.
- **`cyberspace:settle` sweeps every five minutes, and it is NOT what makes a
  read finish.** The catch-up above is; a player who walks away for a day gets
  the whole read resolved on arrival whether or not this ever ran. What the
  sweep buys is a row that is TRUE between visits, which is what every reader
  that is not the owner needs — a notification saying a read finished can only
  come from something that NOTICED, and lazy settling never notices. **Do not
  delete the catch-up because the sweep exists**: the sweep is a convenience and
  the catch-up is the guarantee.
- **Losing a read has two causes and they must not share a sentence.**
  `App\Cyberspace\VoidCause` — the instrument stopped (they did it, and can
  avoid doing it again) or the machine burned or relocated (nobody could have
  prevented it, and it says nothing about the deck). One message for both sent
  anybody who lost a read to a stale machine off to inspect a working deck.
- **A read is a screen you watch.** The `<dialog>` **in the layout** polls
  `cyberspace.reading` while it is open — the target, the instrument, the pass
  and everything the scan has asked — and **the endpoint settles a matured pass
  on the way past**, which is why the answers fill in the moment one finishes
  rather than at the next reload. It polls only while open: a timer behind a
  closed dialog is a request every few seconds for ever.
- **A machine's defences are decided ONCE, at creation, and stored.**
  `App\Cyberspace\Defences` is that decision: **type sets the floor and grade
  rolls the rest.** A silo always watches and always fights, a relay always
  watches and always traces, a compute node always fights and always traces and
  rolls for its detection — a rotation, so every type guarantees two and rolls
  one, and what a scan is for is never *does this have anything* but *which one
  is it missing*. The portal carries nothing, and that needs no entry anywhere:
  `SystemType::isInteractable()` already says no to everything for it.
- **The world predates its own defences, so `cyberspace:defend` arms it.** The
  columns default to 0 — *has none* — which is right for a hand-inserted row
  and wrong for a grid that already exists: `Defences::roll()` runs when a
  machine is CREATED, and relocation keeps the row, so nothing generated before
  the feature would ever gain an IDS. It is a **back-fill and not a re-roll** —
  a machine already carrying something is left alone, because a told machine is
  the case the whole design exists to allow. A command rather than part of the
  migration, because it ROLLS: a migration carrying dice is one whose result
  cannot be read off the file. Run once against the live grid on 2026-09-16.
- **This is why a mission node needs no override mechanism.** Nothing derives a
  defence on read, so nothing has to be told not to — a machine that has to be
  dangerous is a row with different numbers in the same columns, and a grade 9
  watchdog sits on a grade 2 box by being written there. `systems.stock_rule`
  exists because a node's CONTENTS are re-rolled nightly and so need a standing
  instruction; defences never are.
- **Everything hostile can notice you, not just the IDS.** A tracer carries its
  own detection value — much lower, a fifth of the IDS at grade 1 widening to
  two-fifths at grade 9 — so a machine with no IDS is quieter rather than safe.
  Detection is the EVENT and not the instrument: the lockdown table lists *the
  IDS fired* and *the ICE activated* as separate triggers for exactly this
  reason.
- **Zero means "has none"; null means "not looked".** On `systems` the three
  defence columns are NOT NULL and 0 is the absence, so *has an IDS* is
  `ids_detection > 0` in one shape everywhere. On `system_scans` the same
  columns are nullable, because there the two states are genuinely different
  and the distinction is the product: **a scan that comes back clean has told
  the player the most valuable thing it can.** Reading 0 as unknown would send
  the probe back round rolling for a fact it already has.
- **A tracer is one thing, so it is rolled for once.** `tracer_detection` and
  `tracer_rate` are zero together or positive together, and
  `chk_systems_tracer` says so — the same "null exactly together" discipline
  `running_on_deck_id` and `started_at` follow. Nothing reads the rate yet: the
  trace advances per turn and there is no turn loop.
- **ICE is a POINTER; the other two defences are numbers.** An IDS and a tracer
  are values on the machine. ICE is a definition — integrity, an attack, a hit
  chance, and a list of what it does to whoever it beats — so `systems.ice_id`
  points at an `ice` row. The catalogue **arrives by migration**, like the corps
  and for the same reason: its ids end up in thousands of system rows.
- **`skill` is a hit chance from 1 to 100, and a MISS DOES NOTHING AT ALL.** It
  is a separate number from `attack` rather than folded in as an average,
  because an average would make every fight the same fight — what makes a
  low-grade guard survivable is that it mostly misses, not that it hits softly.
- **A scan remembers the LEVEL, never the row.** `system_scans.ice_level` carries
  no foreign key, the rule `system_contacts` follows by storing an owner's name
  rather than a pointer: a scan has to outlive the machine. Null is *not found
  out*, 0 is *found out and there is none*, and a renamed piece of ICE reads by
  its new name in every file anybody holds.
- **`Defences` is handed the catalogue, it does not query for it** — the class
  stays pure, the way `Grade::roll()` is handed its weights. An empty list is
  not an error: a world whose ICE has not been migrated is machines that cannot
  fight, which beats no world.
- **A bounty belongs to the MACHINE, not to the guard.** `systems.ice_bounty` is
  rolled when a machine's ICE is assigned, at odds climbing with the piece's
  level — a tenth under ICE I, two-thirds under ICE XII. So the same piece of
  ICE on two systems is two different propositions, and a player who has met an
  ICE XII has learned what it hits for and nothing about whether the next one
  will have them followed home. It is the only part of a defence that is not a
  property of what is defending, and no ICE means no bounty. **A scan reads it,
  folded into the ICE fact** — one question and one roll, because a file that
  knew the guard but not the reprisal would describe half a decision. It is the
  one answer on `system_scans` that is not nullable: `ice_level` already carries
  whether the fact was read.
- **Nothing heals by waiting, and `synaptic_regen` floors at 0 to say so.**
  Synaptic Integrity and hit points come back through things a player DOES, and
  the single exception is bought with a rank 16 skill — **Neuroplasticity**, a
  point a minute per level. A character who has not trained it recovers nothing
  by sitting still, ever. Per MINUTE rather than per tick, so whatever credits
  it can do so lazily off elapsed time. **`characters:regenerate` credits it,
  every minute**, and keeps NO stored clock — `market:seed`'s stance rather than
  the training heartbeat's, because a deposit is credited from a clock the
  player is watching and this is a trickle nobody is counting. A minute lost to
  a scheduler restart is a point. It heals from ZERO, which is the only route
  off the floor that exists until healing is designed.
- **`Character::recover()` is `takeDamage()`'s mirror and just as dull** — an
  addition and a ceiling, knowing nothing about why. **Capped at the maximum as
  it is NOW**, which is why it cannot be damage with a minus sign: a ceiling cut
  by flatlines is the ceiling you heal to, and healing must never climb past
  what injury lowered.
- **An icebreaker is a GATE before it is a number**, and `icebreaker_damage`
  floors at 0 to say so: no running icebreaker means no damage, and no amount of
  skill adds up to having one. Its bite is a modifier on `programs.modifiers`
  like everything else a program is worth, so a skill that sharpens one and a
  better one are the same kind of thing to the resolver.
- **TWENTY TURNS IS NEVER ENOUGH.** Every icebreaker does strictly less than
  would break its matching ICE in twenty turns — the floor the whole ladder is
  set by, with the gap left for skills to close. `DefencesTest` pins it, because
  a tuning pass is exactly how a rule like that gets broken quietly.
- **So fighting is gated behind training, and that is the intended reading of
  "the bottom of the grid is walkable".** Walkable, not fightable: Icebreaker I
  needs thirty turns against ICE I, which deals about 1.05 Synaptic Integrity a
  turn — 31.5 against a starting pool of 31. The first icebreaker is a tool for
  machines that have no ICE, and choosing to fight is an investment decision.
- **Integrity and attack are BASES and expect to move.** A fight is meant to run
  2-10 turns and that depends on icebreaker throughput, which does not exist —
  the twelve pieces climb together so the ratio survives recalibration. Nothing
  resolves them yet; when something fights, it goes through `App\Stats\Resolver`
  and `DamageResolver` so a resistance to ICE is a modifier and never a branch.
- **`App\Cyberspace\ScanFact` is the vocabulary of what a scan asks**, and it
  lists the two nothing can read yet — ICE and cloaked — as *not yet* rather
  than hiding them, the same decision the item card takes with its unbuilt
  actions. `isKnownOn()` reads COLUMNS and `readFrom()` may reach
  through a relation: asking a relation whether a scan is complete says no to a
  scan that just answered, because Eloquent caches the null it loaded before
  the probe wrote the id.
- **A read runs in the BACKGROUND and belongs to the instrument doing it.**
  `system_scans.reading_process_id` points at the running copy of the scanner —
  an `inventory_items` row — so the read survives its owner walking to another
  machine or jacking out entirely. **Settling is per character, not per
  position**: `settleAll()` resolves every matured read, because the ones
  elsewhere are the whole point.
- **Two instruments read two machines.** A second read needs a second running
  instance, which costs another lot of compute and a free thread — see
  *Programs*. `ProbeRefusal::AllBusy` is "every scanner you have going is
  reading something else", which is a different sentence from having none.
- **An interrupted read is a lost read.** The analyser must have been running
  for the WHOLE pass, not merely at the end: `inventory_items.started_at` is
  later than the read began if it was stopped and restarted, which answers it
  without recording any history — and without it a player could free the
  compute for a quarter of an hour and switch it back on before looking.
  Swapping to a different analyser fails the same test, correctly. A voided
  pass is still SPENT, and the map says so on the way in.
- **`Probe` is bound in the container**, so a test can pin its dice and have
  the SCREENS use them — the map settles a scan during `index()`, which a
  `fromConfig()` built inside the controller would have made untestable.
- **Reading a machine takes software, and it has to be RUNNING.** An analyser
  on the equipped deck is the gate; one in the stash is a file. **What a better
  version buys is data, not code**: `programs.modifiers` against
  `check.systemprofiling` for a steadier read and against `scan_passes` for
  another look, so a new version is an item row and the probe never changes.
  Analysers are BACKGROUND programs — mapping happens on the open grid, and
  nothing passes `$duringRun` true.
- **A scan is a file that perishes.** `system_scans` is one row per character
  per address — a sighting, like a contact, with no foreign key to `systems`,
  because it has to outlive the machine. What is known is COLUMNS and null
  means *not found out*; a JSON bag would be shorter and unqueryable. Live
  means unspent and unexpired (`scopeLive`), and a stale file is replaced
  rather than continued, since carrying old answers forward is worse than
  having none.
- **What a node holds is its OWN inventory model.** `system_contents`, not the
  character inventory with a different owner column: it is raided rather than
  carried, safe from what ICE does to a person, and restocked on a clock — a
  different set of rules, so a different table. Three kinds in one table
  (`creds`, `item`, `data`) because they answer one question, with a CHECK per
  kind saying which columns mean anything.
- **A file is an object, not a reward.** Size in QB (turns on the way out), an
  encryption type (a skill somebody has or does not), and TWO values — sealed
  and broken. The gap between them is the whole reason decryption is a mechanic.
  Files are **generated, never authored**: there is no catalogue of them, which
  is why they are rows here rather than `items`.
- **`App\Cyberspace\NodeStocker` is the only writer, and it TOPS UP.** A node
  still holding something is left alone, so a pass run twice is not two hauls —
  the same rule `MarketSeeder` follows. `cyberspace:restock` runs nightly with
  `--dry-run`; emptying a node is temporary on purpose, and the brake on that
  loop is the raid counter rather than scarcity.
- **A node's items are a draw of NONE TO SEVERAL**, rolled again after each hit
  and capped, so a rich machine is rich rather than merely likely.
- **Nothing PHYSICAL ever comes off a machine.** You are not in the room: what
  a raid carries out is a file or a program, so the shelf is `on_market` items
  whose `container` is `data` and nothing else. That rule lives in
  `NodeStocker::stockableItems()` and is the one thing about node loot that is
  not data.
- **`loot_tables` is the weighting, and it is rows rather than config**, unlike
  the rest of `config/cyberspace.php` — a band is content to tune, and tuning
  it is not a deploy. One row per (type, grade band, rarity), `weight` out of
  the band's own total, and **a NULL `system_type` means any machine**: four
  band rows cover the whole world today, and a row naming a type is an
  override that beats them. `App\Models\LootTable::weights()` is the read
  (never `all()` — Eloquent owns that name) and `covers()` is the question.
- **The bands are the design, and both ends are hard.** Grades 1–3 draw rare
  at 1%, and **ultra-rare is weight 0 anywhere below grade 8** — a wall rather
  than a long shot, so the best software in the game cannot fall out of a
  street-corner relay however long anybody grinds it. A rarity with no items
  behind it is skipped rather than drawing nothing, so an empty shelf costs a
  roll rather than the whole draw.
- **A file is labelled for the machine it came off.** `strings.cyberspace.files.words`
  is per type — a silo yields a ledger, a compute node a model, a relay a
  routing table — which is flavour and not mechanics: type still restricts no
  kind of item.
- **`systems.stock_rule` is how a machine is TOLD what to hold**, and NULL on
  nearly every row — the derived answer is the ordinary one and the column is
  the override, like `items.board_layout`. `fixed` is exactly these, `any_of` is
  this many drawn from a set without replacement, and `plus_random` keeps the
  ordinary draw for a node that should not look special. **A rule replaces the
  draw**, because a mission cannot be built on the world happening to put the
  right thing in the right place. Items only: creds and files are still rolled,
  since a mission node holding no money would announce itself.
- **`systems.raids` is on the SYSTEM, not the character.** Three successful
  raids by anybody retires the node, and `App\Cyberspace\RaidLedger` is the
  only writer of that counter — and the only thing that moves a machine once
  it has been placed, because the reset is not a separate decision from the
  count: a relocated machine has new books by definition. A per-character
  counter would have every popular node raided into the ground by a hundred
  people who each thought they had two left.
- **A RAID IS A RUN, not a haul.** Counted once, the first time a run takes
  anything — `Simulator::recordTake()` is the one door, which is why
  `runs.extracted` is written there rather than at either call site. Creds on
  turn two and a file finishing on turn six are one visit. It is counted when
  the take LANDS rather than when the run ends, because the shelf is empty
  from that moment whatever happens to the person carrying it: a raider who
  flatlines on the way out has still emptied it.
- **Retiring is relocation, and it happens in `RunStarter::end()`** — the one
  door out of a run, so a retirement cannot depend on how somebody happened to
  leave. Asked on EVERY ending, since the counter is what decides and it is
  already spent. **Not while anybody is inside**: a live run on the address
  holds the machine in place, because relocating out from under a run is
  `RunEnding::Cancelled` — a real ending with its own damage, and not a side
  effect a successful raid should inflict on a stranger. The counter waits, and
  the next run to end there retires it.
- **A backdoor is ACCESS, so `backdoors.system_id` is a real foreign key** —
  the opposite call from `system_contacts`, `system_scans` and `runs`, which
  all carry an address precisely so they can outlive the machine. Those are
  recollections, and a memory of a machine that has gone is still a memory;
  access to one that has gone is nothing at all, so it cascades. It is also
  what makes the design's promise true: a backdoored machine does not lock down
  and does not relocate, so its address is permanent in a world where nothing
  else is. `App\Cyberspace\Backdoors` is the only writer.
- **The KIND is permanent, and `install()` will not reroll it.** A machine
  siphons or it routes, never both. Going back in on one you already hold costs
  the turn and the core roll and changes nothing — a silent overwrite would let
  somebody convert a siphon into a routing hop for free, which is the one thing
  the choice being permanent exists to prevent. It is chosen at the DOOR, with
  the purpose, on `runs.backdoor_kind`: what a machine is worth holding for is
  decided from the map with a scan in front of you, not mid-fight.
- **A landed install is the only thing in cyberspace that cleans up after
  itself.** `runs.backdoored` is what `Lockdown` reads, and a successful
  install means no lockdown **even if the IDS fired and the ICE woke up** —
  the install erases the logs and resets what it tripped, which is the whole
  value of the operation. A backdoor run that FAILED cleans up nothing and the
  ordinary triggers apply. It moves no raid counter either, because the books
  it would be read from are gone.
- **What holding one buys is exactly half the risk.** The approach roll is
  SKIPPED on a machine you hold — skipped rather than discounted, because a
  number nobody has tuned would be a second thing to balance. It buys nothing
  at the core: extracting through a backdoor is still stealing, still takes the
  second look, still counts toward the three raids and still shuts the machine
  if it is caught.
- **`Simulator::touchTheCore()` is that second look, shared.** Extraction and a
  backdoor install are the same act as far as the machine is concerned, so the
  roll lives in one place rather than in each.
- **A BACKDOOR IS NOT PERMANENT AND FOUR THINGS TAKE ONE.** Three are
  somebody's fault and one is nobody's. `Backdoors::loseAllOn()` is the rule
  and it is asked in two places — `Lockdown::shut()`, which is the machine
  finding out, and `RaidLedger::relocate()`, which is the physical fact that a
  door into a box that has moved is not a door. A BURN needs no entry at all:
  it destroys the row and the foreign key cascades. **A stranger's bad night
  costs you a door you were careful with** — a backdoor keeps a machine open
  against ITS OWN triggers and has never protected against somebody else being
  caught in there, which is the same shared world that puts the raid counter on
  the machine rather than on the person.
- **The nightly ageing roll is the one that is nobody's fault**, and the only
  thing that takes a backdoor from somebody who did nothing wrong.
  `cyberspace:age-backdoors`, one roll per backdoor, **odds rising with AGE and
  with nothing else** — not the machine's grade, not what has been done through
  it, so a backdoor cannot be held carefully enough to keep. A hit moves the
  MACHINE, which takes every other door into it along too. The player is not
  told, which is worse than CYBERSPACE.md asks for: there is no notification
  centre and, unlike a cancelled run, no screen they are about to land on. It
  is logged until there is somewhere to send it.
- **The backdoor mark is the one thing on the map that is NOT memory.** A
  contact is a sighting and is allowed to be wrong; what you hold is a fact
  about you. `MapView` reads the positions off the machines the rows point at,
  which is not the positional query on `systems` that `Grid` reserves.
- **`App\Cyberspace\Lockdown` is the window, and the only writer of
  `systems.locked_down_until`.** A machine that knows it was violated shuts for
  2-6 hours, nothing may run it while it is shut, and when the window closes it
  relocates. **Two triggers, not four**: the run was noticed, or the raid
  counter is spent. The table in CYBERSPACE.md lists four, and two of them
  collapse — **the ICE can only have activated if something noticed**, since
  detection is what wakes it, so `runs.detected` covers both rows. Burn does
  not exist.
- **It fires as a run ENDS**, from `RunStarter::end()` — the same single door
  the raid counter is read at — so it can never reach the runner who caused it:
  they are already out. A machine already shut is left alone rather than having
  its window extended; a lockdown is what the machine DOES about being
  violated, not a sentence that accumulates.
- **Anybody else still inside is thrown out**, and that is what finally gives
  `RunEnding::Cancelled` a caller. One flat point of Synaptic Integrity through
  `DamageOrigin::RunCancelled` and nothing else — no defeat effect, no raid
  counted, no trace, because the cause was not theirs. **The floor is asked
  anyway**: one point can put somebody down. `Simulator::resolveEnding()` owns
  it, so a cancellation cannot drift out of step with a defeat.
- **A cancellation gets its own damage origin** even though it costs what a
  readiness loss costs, because an origin is the axis a resistance is written
  against: "tougher when your own deck fails" and "tougher when the world moves
  out from under you" are different sentences.
- **EXPIRY IS SWEPT AND NOT LAZY — the one place cyberspace breaks its own
  rule.** A matured scan pass, a training deposit and the waiting screen all
  settle when somebody looks. This cannot: two machines relocating into the
  same free position is a race, and `cyberspace:lockdowns` (every five minutes)
  is what serialises it. It is also how a 2-6 hour window ends for a player who
  has gone to bed. A machine that opens with nowhere free to move to is logged
  and left where it is rather than staying shut for ever.
- **The map draws a lockdown from MEMORY, like everything else on it.**
  `system_contacts.locked_down_until` is what you SAW; the sweep records it, and
  a machine that shut after you walked past still looks fine until you come
  back. `cell--rebooting` was in the kit from the start with nothing setting it.
- **Relocation is `RaidLedger::relocate()`, and it is public because lockdown
  and burn both end there too.** New position, new address, `raids` back to 0.
  Bookmarks pointing at it break, every scan anybody held stops describing
  anything, and the sighting left behind is forgotten by the next sweep — none
  of which is an error anywhere, because all of it was designed around a
  machine being allowed to go. A grid with nowhere free is an ordinary false:
  occupancy leaves ~30% empty precisely so relocation has somewhere to move
  through.
- **The map screen draws from memory and never from `systems`.** The eight
  cells around you are buttons and everything else is a reading; arrows and the
  numpad reach the same eight, because a diagonal is one step here.
- **A cell says up to six things at once, and each has its OWN channel** — which
  is the only way they stay legible in one small square. **Ground** is terrain: fog sits
  below the board with a weave and no hairline, swept ground sits on it with
  one, which is a layer difference rather than a shade. **The glyph** is the
  kind of machine, drawn from the set already proved against the font stack.
  **The hairline** is its condition — dashed while rebooting. **The corners**
  are what you have on it: bookmark top-right, backdoor bottom-left in ember,
  the kit's one mark for *yours*, and **scanned bottom-right in `--info`** — a
  machine you have read something OUT of, which is not the same as one you
  pointed an instrument at, so a file with no answers in it marks nothing. **A
  bar at the foot** is the scanned grade. **Colour says type and nothing
  else** — steel, cyan, jade on the GLYPH, never a fill, because four hundred
  tinted tiles is a quilt.
- **A cell has two pseudo-elements and four marks**, so the last two are
  BACKGROUND LAYERS: the grade bar and the scanned tick composite through
  `--cell-grade` and `--cell-read`, either of which may be absent. A rule that
  set `background-image` outright would take the other one with it.
- **The scanned mark clears itself**, because it is keyed by ADDRESS like the
  grade: a machine that relocates after a lockdown comes back wearing a new
  one, and the file stops describing it. Nothing has to go round removing
  marks, which is the same property that keeps a stale grade off the map.
- **The window is capped at what anybody could ever see.** `map_range` is 7
  because `view_range` is **2** and the modifiers meant to widen it are worth
  about +5 — drawing memory further out than a character can ever refresh it
  from where they stand is drawing ground they cannot do anything about. **A
  cell is 60px**, which is what carrying a glyph, a grade bar and two corner
  ticks costs; the map scrolls and centres on the player. A bare character's
  lit square is 5 x 5, which is exactly what a 360px phone shows — so the thing
  a player is actually looking at fits a screen, and the memory around it is
  what they scroll for.
- **The key sits beside the map, as its own collapsible rail.** `.gridmap-frame`
  reserves the collapsed width and `.maplegend` OVERLAYS the map's right edge
  when it opens — the same discipline the dock follows, and for a sharper
  reason: the map re-centres on scroll, so a column that pushed it narrower on
  hover would move the ground under the cursor. Three ways in, all three the
  dock's: hover where a pointer exists, the toggle everywhere, focus-within for
  a keyboard. **Below 720px it drops under the map and stays closed** — a
  legend is something you consult, not something you watch — and opens into the
  wrapping row it always was. The frame declares `--key-w`, because a custom
  property set on the child cannot be read by the parent that has to reserve
  the room.
- **What is outside the current view range is dimmed**, not hidden. The map is
  memory and the sweep lights a square of it: `cell--distant` steps the rest
  back to **.55** so the square being looked at is obvious while everything
  remembered stays readable. It was .8 once, and .8 is a difference that is
  real in the numbers and invisible on a screen where most of the cells are
  distant — the step has to be big enough that the few lit ones win. Nothing is lit at all while jacked out, because
  somebody outside cyberspace is reading the whole thing from memory.
- **An ordinary machine has no hairline.** The fill lifts it off the ground and
  the glyph says what it is, which leaves the border free to mean something: a
  hairline is only ever the portal, a machine that has just appeared, one that
  is rebooting, or you.
- **Two things move, and they move differently on purpose.** The portal
  BREATHES between bone and brass over four seconds — a pulse in place, which
  the ration affords because there is exactly one portal in the world. A
  machine that has only just appeared has a light TRAVEL round its hairline, in
  its own type colour, the same idea as the sheen crossing a button: movement
  that says *something changed here* rather than *this is a different kind of
  thing*. A pulse and a sweep read as two statements; two pulses would read as
  neither. Everything else is static — rebooting is a dashed hairline for
  exactly that reason — and `prefers-reduced-motion` stops both.
- **A new cell keeps its type colour.** The first version went bone, and that
  was wrong twice: a new compute node stopped looking like a compute node, and
  a bone hairline is the loudest thing the kit has short of the accent. Forty
  cells can be new at once after a sweep, so their phases are staggered by
  position — forty synchronised sweeps is a disco, not a map.

### Being on the floor

**Reaching 0 in either resource puts a character on the waiting screen**, and
`App\Characters\WaitingRoom` is the whole of it. Until this, `takeDamage()`
would take somebody to zero and say so in a comment.

- **The wait is NOT the punishment.** What losing costs is whatever the defeat
  tags carried; the minute is only there so a defeat carrying *nothing* still
  costs something. A long wait would punish the player who lost to a harmless
  guard hardest, which is backwards.
- **Zero to one, and no further.** Getting up is not healing — it is getting up.
  One point is enough to act and not enough to be safe, and the way back from
  there is Neuroplasticity, a clinic, or something a player does. **Both
  resources come back if both were empty**, or waking still broken in the other
  half would put somebody straight back down; a resource that was NOT empty is
  left exactly where it was.
- **Released lazily, with no scheduler.** `EnsureCharacterIsStanding` asks on
  the way past, so the minute ends the moment the player looks — the same
  settlement a matured scan pass and a training deposit use. A player who never
  comes back never needs to be got up.
- **A second hit does not restart the clock.** A second hit on an unconscious
  character is not a second wait.
- **It runs BEFORE the run lock.** A defeat ejects and only then shows this
  screen, so the two should never overlap — and if they do, being unconscious
  wins, because sitting on the waiting screen while still holding a position in
  cyberspace is the one state nothing else knows how to read.
- **`ReadinessWatch` is its first caller**, and the order there is the order
  every bad ending follows: damage, the consequence, the ejection, and **only
  then** the screen. The screen comes after `jackOut()` because a character must
  never be sitting on it while still holding a position in cyberspace — which is
  why those are four lines apart rather than one call. **One point can do it**:
  somebody on their last point of Synaptic Integrity whose deck stops running
  goes down, which is a hard way to lose and not an unfair one.
- **`characters.waiting_cause` is JSON, and that is temporary on purpose.** What
  put somebody down is a run, a named machine, a named piece of ICE, damage and
  a list of tags — none of which is built. Columns modelled against an unwritten
  simulator get modelled wrong. `App\Characters\DownedCause` is the single
  author and reader of that blob, and is the shape it becomes when it settles.
  **Every field is optional**: a readiness loss knows nothing about ICE, and the
  screen shows less rather than showing "unknown".

### Runs

**The spine, the fight and extraction are all built.** A run can be begun,
played turn by turn, and left with something to show for it. Detection, the
ICE, the trace, a defeat's own consequences and taking what a machine holds all
roll now.

- **`runs` is durable state, and that is the cost of making a run interactive.**
  An atomic run needed no row — it began and ended inside one request. **The row
  carries the ADDRESS and never a system id**, the rule `system_scans` and
  `system_contacts` already follow: a machine burned or relocated out from under
  a run is what CANCELS one, and a foreign key would make that an integrity
  error instead of a game event. `Run::system()` returning null is ordinary.
- **`characters.current_run_id` is what "in a run" means** — a column rather
  than a flag on `runs`, so "one at a time" is not a rule anybody enforces. The
  same call `equipped_deck_id` makes, and the lock reads it on every request.
- **`App\Cyberspace\RunStarter` is the only writer**, and it sets the character's
  pointer inside the same transaction that writes the run: a character pointing
  at nothing, or a live run nobody points at, are both states the lock cannot
  read.
- **It refuses four things and deliberately not a fifth.** Not in cyberspace,
  nothing there, the portal, and a deck that stopped meeting its requirements
  (readiness, checked a second time — cheap, and it closes the window between
  entering and attacking). **It does NOT refuse a machine whose operating system
  you lack**: that run is entered and `runs.os_locked` records the verdict,
  because a refusal naming the OS would hand over a scan fact for one click.
- **`EnsureRunIsFinished` is the lock, and it is `RequireCharacter`'s shape** —
  one middleware, a named allow-list, everything else redirected, 409 and a URL
  for a JSON caller. **Admin routes are not exempt**, which is the one
  difference: the tools edit the game, but a run is a character in jeopardy and
  an admin could edit their way out of one.
- **The readiness net stands down inside a run.** `EnsureDeckStillRuns` returns
  early while `current_run_id` is set, because a burnout is *supposed* to break
  the deck and resolves its own ending — two things racing to end one run would
  let the one that knows least win, throwing away a defeat and calling it a
  readiness loss.
- **Leaving is always on the list.** Egress is never absent and never disabled,
  whatever else a turn offers. A run with no exit is a character deleted by a
  door they could not see.
- **`App\Cyberspace\Simulator` is one door for every action**, so a turn always
  costs the same thing whatever it was spent doing — the economy the whole run
  rests on. The die is injected, like the market's and the probe's.
- **The player acts, then the machine does**, and that order is not arbitrary:
  somebody who lands the blow that breaks an ICE does not then take a hit from
  something already destroyed, and somebody who leaves gets their attempt before
  the thing they are leaving gets another swing.
- **What is fighting lives on the RUN, not on the machine.** `runs.ice_id` and
  `runs.ice_integrity` — the catalogue says what an ICE *is*, and these say what
  this fight has left of it. It also means a machine burned out from under a
  live run does not take the fight with it.
- **A failed cut says one thing for three causes**, deliberately: no icebreaker
  running, an operating system the runner cannot speak, or nothing awake to cut
  at. The gate must never announce itself, so the log line a missed pass gets is
  the line an unspeakable OS gets, and `FightTest` asserts the words *operating
  system* appear nowhere in it.
- **`stealth` is what an IDS is rolled against**, and it floors at 0 the way
  `icebreaker_damage` does: somebody who has not trained for it is seen, every
  time, by everything. Its base is a **POOL** — `(intellect + focus + memory +
  perception) / 8.5`, kept to one decimal — which is a different mechanism from
  `formulas` and deliberately so: a formula divides each attribute, rounds each
  UP and adds the results, while a pool adds first and divides once. Four
  attributes of 20 are 12 as a formula and 9.4 as a pool. Hit points and
  Synaptic Integrity are pools you SPEND, so being generous per term is right;
  stealth is a capability, and a capability reads like an average.
- **Pools are kept out of `stats.formulas` on purpose.** That array is shipped
  to the character-creation screen and recomputed in JavaScript — one shape,
  asserted by `DerivedStatTest` — so a second shape in it would have to be
  mirrored in the browser. They are in the stat graph all the same, because
  resolution order and cycle detection are about WHAT a stat reads, not how it
  adds it up.
- **"Per level of something else" is FIVE MODIFIERS, each conditional on one
  more level.** A modifier's amount is a number and never an expression, so
  that is the only shape the resolver has for it — and it is the same shape a
  skill's own per-level grants already use. `skill_at_least` is the predicate,
  and `Sheet::contextFor()` puts a character's levels into the context as
  `skill.<slug>` for it to read. **Only the skills they actually have**: an
  absent key and a level of zero are the same answer, and writing every skill in
  the game into every context would be a row per skill per resolution for the
  sake of the ones nobody has.
- **Arriving is the first thing anything gets to notice**, and
  `Simulator::rouse()` is where. **Every watcher rolls, not just the IDS** — a
  tracer and a piece of ICE each carry a value of their own, so a machine with
  no IDS is quieter rather than safe. They are not competing: **any one success
  detects you**, because they are all in the room.
- **Detection is what wakes things.** An undetected run walks past the ICE and
  the tracer both, so the ICE is only written onto the run when something
  noticed — which means stealth does not make a fight easier, it makes the
  fight not happen. Everything downstream asks `runs.ice_id`, so a quiet run
  simply has nothing to fight.
- **It takes the GLOBAL certainty floor**, and `checks.floors` names no kind for
  it on purpose: the best runner alive still trips a household IDS about once in
  a hundred and twenty attempts, and detection is the case that rule was written
  for rather than an exception to it.
- **The trace advances once something has noticed**, at the machine's
  `tracer_rate` a turn — detection is what wakes the tracer, so a run nothing
  saw is a run nothing is following. The rate is read off the machine rather
  than copied onto the run, unlike the ICE's integrity: integrity is being
  ground DOWN and belongs to the fight, while a tracer's speed is a property of
  the hardware and does not change.
- **The run screen draws the trace as the CHAIN it walks** — the machine at one
  end, you at the other, and every backdoored relay routed through in between.
  Routing is not built, so that chain is two nodes today; it is drawn as a chain
  rather than a pair so it will not have to change when hops arrive.
  `Run::turnsUntilTraced()` is the number the egress decision is made on, and it
  is worked out server-side because a player should not be dividing in their
  head while something is hitting them.
- **A TRACE WALKS THE CHAIN, one hop a turn.** Each turn is a single attempt at
  the NEXT system in the route: pass it and the trace moves up and the turn
  ends, fail and it stays put and the turn ends anyway. **A hop once passed is
  behind it** and is never rolled again — `runs.trace_hop` is that position, and
  it is why the column exists. Rolling every hop every turn would make a long
  chain not slow but impassable: ten simultaneous successes for ever, rather
  than ten successes eventually.
- **The chance is what the hop's GRADE allows** — 95% at grade 1 down to 20% at
  grade 9 — and **grade is the only thing a hop reads**. Owner and dig-in were
  considered and decided against: a per-corp weight means a number for 121
  corps, and reading age would pull against the ageing roll, which makes an old
  backdoor likelier to vanish rather than better.
- **A hop only delays by FAILING**, since passing one advances the trace in the
  same turn. So a chain is worth the held turns its failures buy, and **grade 1
  is 95 rather than 100 for exactly that reason**: at 100 a junk hop could never
  fail and so could never delay anything, which contradicts the design's own
  promise that a routing backdoor is useful on any machine at all. Ten junk hops
  is a 40% chance of one held turn — a chance rather than an income.
- **`routing_hops` is the ceiling, and it is all skill**: base zero, one hop per
  level of Routing and of Advanced Routing, ten in total. It is the first entry
  in `stats.bases` — the third map `Sheet::baseFor()` walks, after pools and
  formulas — which exists so `Character::stat()` can answer for a stat that is
  neither computed from attributes nor stored. The older stats of that family
  (`icebreaker_damage`, `scan_passes`) still hand a base in at their call sites
  and were deliberately left alone.
- Past the end of the chain the trace comes on unopposed, and an empty chain is
  that case from the first turn.
- **A completed trace puts heat on the character and burns the chain.**
  `characters.heat` is persistent and theirs; **one point is a placeholder** and
  what heat DOES is undesigned — see TODO.md. Burning the chain is called and is
  a no-op, because there are no backdoors; the day there are is not the day it
  should be remembered.
- **`characters.heat` and `runs.thermal_load` are different quantities.** The
  design calls both "heat", which is why the run's column is named for what it
  is: a per-run number in thermal units that resets, against a permanent one
  that follows you home. One word for both is a bug waiting to be read at three
  in the morning.
- **The two thresholds ACT, and they act BEFORE the player's choice.** Checked at
  the start of the turn; when one is breached the egress is attempted then and
  there, whatever was asked for. That is deliberately not the gentler rule — a
  threshold that only warned would leave an unresponsive player in a run that
  never resolves — and it is the standing instruction they left for exactly this
  moment. **Zero turns one off**, which saves a second column whose only job
  would be to say whether the first counts.
- **They are set from the MAP, never from inside a run.** An accordion on the
  cyberspace screen, folded away, because they are a play style rather than a
  per-run decision — and changing them mid-fight would be changing the rules of
  a fight while standing in it.
- **The IDS's second chance is built, and it is `Extract`'s own roll.**
  `Simulator::spottedBy()` is the shared roll behind BOTH chances — `rouse()`
  calls it on approach, `extract()` calls it again on the core touch, and
  either one can independently find a runner the other missed. It fires
  whether or not the extraction it accompanies goes on to succeed: touching
  the core is the act that risks it, not the result.
- **`App\Cyberspace\DefeatResolver` is what makes the tag set real.** Until it
  existed, `Ice::defeatTags()` fed `DownedCause` names for the waiting screen
  and nothing rolled or applied a single one — losing to ICE XII cost exactly
  what losing to ICE I did. Called from `Simulator::resolveEnding()` **before**
  the ejection, which is the same ordering everything else in a bad ending
  follows: damage (the ICE's hit, already landed), then the consequence, then
  `jackOut()`, then the floor.
- **Flatline deepens the injury; Neural Shock lands on the body.** Flatline
  rolls its percentage against maximum SI **as it stands right now** — after
  every earlier flatline, which is what "accumulates" means — and writes
  through `InjuryLedger::inflict()`, the same *neural damage* row a freeze and
  a future overdose both deepen. Current SI clamps to the new ceiling on the
  save that follows, via the model's own hook, not a second call. Neural Shock
  goes through `DamageResolver` like every other hit, with `DamageOrigin::Ice`,
  and touches no maximum at all — that is Flatline's job. It called
  `takeDamage()` directly at first, which skipped every rule the door exists
  for: nothing could resist it, and the Crim's "Hard knocks" could not touch it
  although its target set (`damage` + `hitpoints`) covers exactly that stat.
  **Flatline deliberately does NOT come through the door** — it writes a
  ceiling through `InjuryLedger` rather than landing a hit, and mitigation has
  nothing to say about an injury.
- **Surge deletes the module outright**, chosen from whatever is CURRENTLY
  fitted to the equipped deck — `deck_fittings.module_id` cascades on delete,
  so the fitting row cleans itself up without a second write. One roll per
  module the level names, exactly as designed: a module already taken by an
  earlier roll this same Surge cannot be taken twice, because it is gone. No
  deck, or nothing left fitted, is an ordinary no-op.
- **Erase draws from storage AND whatever is running — deliberately wider than
  `inventoryIn()`'s ordinary meaning.** `InventoryItem::scopeLoose()` excludes
  anything running, fitted or installed on purpose, which is correct for a
  stash listing and wrong here: "software or data held on the deck" has to
  include what is running on it, or Erase would spare exactly the programs a
  character cared enough about to load. A running row is stopped through
  `ProgramRunner::stop()` before it is deleted, never blown through.
- **Skim reaches `creds` directly**, floored so a share of nothing takes
  nothing rather than throwing. Bounty never appears in this list — it cannot,
  structurally, because it is rolled onto the MACHINE (`systems.ice_bounty`)
  and never written into `Ice::defeat_tags` — so `DefeatResolver`'s match over
  `DefeatTag` handles it by returning null, honestly, rather than by omitting
  the case.
- **`DownedCause` grew an `effects` field.** `tags` is names; `effects` is
  `DefeatResolver`'s own rendered account — "2 points of your ceiling, gone for
  good" rather than "flatline" — and the waiting screen shows `effects` in
  preference to the bare tag pills whenever a resolver actually ran. A cause
  built before `DefeatResolver` existed has the first and not the second, which
  is why the screen still falls back to the tag list.
- **`cyberspace:tag-ice` is a console command, not a migration.** It is how the
  live database's twelve ICE rows got their `defeat_tags`, and it means a fresh
  clone or a test database's ICE carries none until somebody runs it — tests
  that need a specific tag set write it onto the row directly rather than
  relying on the seeded catalogue.
- **`App\Cyberspace\Extractor` is the only door between a node's inventory and
  a stash.** It reads and deletes `system_contents` rows and nothing else in
  the codebase touches either side of that exchange — the same discipline
  `NodeStocker` keeps on the writing end. **Creds and items are instant; a
  file costs turns.** `takeCredsAndItems()` and `takeFiles()` are the two
  halves a multi-turn pull needs kept separate, and `totalFileQb()` is what
  sizes one before committing to it; `extract()` still composes all three into
  the one-call haul every non-interactive caller (tests included) wants.
- **A file's turn cost lives on the RUN, not the file.** `runs.extracting_qb`
  is null whenever no pull is running and otherwise the QB still owed —
  `Simulator::beginTransfer()` sets it from `Extractor::totalFileQb()` minus
  one turn's bandwidth, `continueTransfer()` spends another bandwidth's worth
  each time `RunAction::Extract` is chosen again, and `completeTransfer()`
  calls `takeFiles()` once it reaches zero. **Continuing a pull is still an
  ordinary call to `Simulator::extract()`, inside the ordinary turn `take()`
  resolves** — there is no separate code path that skips the machine's half of
  the turn, which is the whole requirement: an ICE woken by the core touch
  keeps swinging on every turn a transfer runs, exactly as it would against
  Attack or Hold. Choosing Attack instead simply does not call `extract()`
  that turn, so `extracting_qb` sits untouched until the player comes back to
  it — a pull is paused by spending the turn elsewhere, never lost.
- **The core-touch detection roll fires only on the turn that BEGINS a pull,
  never on one that continues it.** Touching the core is the act that risks
  it; a transfer already running is not touching it again. That is why
  `beginTransfer()`'s caller checks `extracting_qb === null` before rolling at
  all, ahead of the OS/purpose/empty-node check that shares `extract_fails`.
- **Bandwidth is read through `DeckTotals::forCharacter()`, floored at one.**
  Same rule as everywhere else a deck measure is used: it is a RESOLVED stat,
  never the row, so a skill or an implant that moves `deck_bandwidth` changes
  how fast a runner pulls data without `Simulator` knowing either exists. The
  floor is a degenerate-case guard — nothing today can drive resolved
  bandwidth to zero on a deck that met the Link requirement to jack in — not a
  balance number, and it is there so a transfer can never stall forever rather
  than to make one faster.
- **A transfer's own "somebody else took it first" gets its own log line,
  `transfer_gone`, rather than sharing `extract_fails`.** Losing a race after
  a countdown finishes is an ordinary outcome a player can learn from, unlike
  the OS gate `extract_fails` exists to protect — nothing here is secrecy the
  way the core-touch failure lines are.
- **Three kinds, three ways of becoming a possession.** Creds go straight onto
  the character. A catalogue item is granted through the SAME merge-or-create
  `LoadoutGranter::grantItem()` already uses — two piles of one stackable thing
  in one place are one pile, however each pile arrived, so the logic could not
  be allowed to diverge. A file has no catalogue row of its own — see
  `ExtractedFile` — so it becomes one unstackable "Encrypted Data Dump", the
  same generic item every extracted file becomes.
- **A file's real numbers live in `inventory_items.extracted_file`, as JSON, on
  the ROW and not on the item.** Every dump looks alike on the shelf, the way a
  memory shard's contents are not the shard. `App\Cyberspace\ExtractedFile` is
  the one author and reader, the same shape `DownedCause` takes for
  `waiting_cause` and for the same reason: decryption and selling are not
  designed, and columns modelled against either would be modelled wrong.
- **Two files never merge, on purpose, even though the item they share is
  unstackable anyway.** Two dumps with different sizes and different values
  merging into one row with a summed quantity would destroy information a
  quantity cannot hold — the one item in the game where merging would be worse
  than the ordinary case `stack_key` exists to prevent.
- **`RunEnding::Completed` finally has a caller.** It existed in the enum from
  the start — "they did what they came to do" — with nothing to trigger it.
  `runs.extracted` is the flag: egress after it is set reads as Completed
  rather than a plain Egress, checked in BOTH doors to leaving — the turn-based
  Egress action and the older, still-routed standalone `run.egress` endpoint —
  so the two cannot disagree about whether a haul happened.
- **LEAVING IS A ROLL.** `Simulator::egressChance()` is the derived chance and
  it is PUBLIC and separate from the roll, the `CheckRoller` rule: the button
  quotes the number the attempt uses, so the two cannot disagree. The value is
  the `egress` stat — a POOL of Reflex, Focus and Intellect, resolved like
  everything else, so the first skill that helps somebody leave is a modifier
  on that id and never a line in the simulator. The difficulty is the machine's
  GRADE plus a term for whatever is currently fighting you (`cyberspace.egress`),
  which is CYBERSPACE.md open question 12: tearing free mid-fight is not the
  same act as walking out of a quiet machine, and an ICE that never woke is a
  cheap exit — one more thing an undetected run is worth.
- **THE SCORE IS ON THE SCREEN, AND SO IS ITS WORKING.** `Simulator::egressReport()`
  assembles one `EgressReport` that the button, the explanation card, the roll
  and the tests all read, so none of them can hold a different opinion about
  the same attempt — `egressChance()` is a field of it rather than a second
  calculation. The card is the FIRST renderer of `ResolvedStat::$steps`, which
  has carried "why is my Focus 14?" since the resolver shipped and had nothing
  drawing it: one line per LAYER rather than per modifier, because percentages
  are summed and applied once, with the contributing sources named. That is how
  *Egress Protocols* appears on the card without the card knowing skills exist.
- **"Evasion" is the capability; "Egress" is the act.** Two words for two
  things, which is why the panel and the button do not say the same one. The
  panel's meter is the CHANCE and not the score, because a pool has no maximum
  to fill and the number a decision is made on is the one that earns the bar.
  The explanation is one `<template>` the kit clones into the hover layer and
  the modal both, the `data-inspect` contract — a phone has no hover at all, so
  the click path is not a nicety.
- **`character.names` names the two pools**, so a modifier targeting either can
  be described on a card. `egress` reads *Evasion* there, deliberately: the key
  names the stat and the string names what a player calls it.
- **Egress Protocols is the first thing that moves `egress`** — rank 8, behind
  Cyberdecks 3, +3% of base per level as five grants on one row. It arrives by
  MIGRATION, the call the corps and the ICE catalogue made: content rows point
  at arrives once and stays, where a seeder would rewrite it on every re-seed.
  Nothing in `Simulator` learned that it exists.
- **A failed egress costs the turn like anything else**, so the machine gets
  its half of it: being held is paid for in trace, in heat and in one more
  swing. **A threshold-forced egress rolls too** — a mark is an instruction to
  try to leave, not a door that opens.
- **Both doors to leaving go through the same roll.** The standalone
  `run.egress` endpoint used to end a run outright, which was correct while
  there was nothing to roll and became a free exit the moment there was:
  `RunController::egress()` now takes the ordinary turn, and `resolve()` is the
  shared tail so the two cannot end a run differently.
- **`Simulator` is bound in the container**, like `Probe` and for the same two
  reasons plus one: its constructor takes an injected die, so nothing could
  build one by reflection and every controller that type-hinted it was a 500
  waiting to be requested. Bound to the container's `CheckRoller`, so a test
  that pins the die gets a pinned SCREEN as well.
- **ATTACK TAGS are what a piece of ICE does WHILE it fights**, and they are
  the mirror of the defeat tags rather than a variation on them: a defeat tag
  fires once, at the end, on somebody who already lost, while an attack tag is
  in force for as long as the thing is awake and alive. One changes the price
  of losing; the other changes the decisions inside the fight.
  `ice.attack_tags` is the column, `App\Cyberspace\AttackTag` the vocabulary,
  `config/cyberspace.attack_tags` the numbers, and `cyberspace:tag-ice` writes
  both lists — so a second attack tag is an enum case, a config table and a
  line in a grid.
- **`Ensnare` is the first one, and it MULTIPLIES the egress chance** — a
  quarter off at I, two-fifths at II, rather more than half at III. A
  multiplier because every other percentage in the game is one: a flat 55
  points off would annihilate a chance that was already poor and barely dent a
  good one, which is backwards. **`CheckRoller` owns the multiplication**, as a
  third argument to `chance()` and `roll()`, because the factor has to be
  applied to the raw curve and the floor applied AGAIN after it — a caller
  doing its own arithmetic would be the first mechanic with a real certainty in
  it. Ensnare makes leaving expensive in turns; it can never close the door.
- **It is PASSIVE while the ICE lives, and nothing is stored on the run.** Read
  off `runs.ice_id` every time it is needed, so there is nothing to expire,
  nothing to clean up, and no condition that can outlive its cause — breaking
  the guard is the whole of getting free of it, which makes cutting worth doing
  to somebody whose only plan was to leave. An undetected run never wakes the
  ICE and so is never held by it.
- **`stealth` is read off a SHEET and not the resolver**, and that was a bug
  worth naming: `stealth` is a POOL, so its base is four attributes added and
  divided once — arithmetic that lives in `Sheet`. `Resolver::resolve('stealth',
  0.0, …)` returns the modifiers and nothing else, which is a bare zero for
  every character carrying no implant, and detection had been rolling that
  against every IDS in the world. **Any pool stat asked for outside a Sheet
  reads as zero**; `Character::stat()` is the way in.
- **One line covers every reason a core touch can fail**, the same discipline
  `attack_fails` already keeps: an operating system the runner cannot speak, a
  purpose that is not `RunPurpose::Extraction`, and a machine with nothing left
  to give all wear one sentence. The UI only offers the Extract button on an
  extraction run, so the purpose check is a defence against a hand-built
  request rather than something a player ordinarily meets — but it still has to
  fail exactly as quietly as the other two, or a player could learn which cause
  applied by which sentence came back.

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
  the same device the skills screen uses for a level, so the two read alike. An
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
  raw the way a skill's own row carries it; items group by their own vocabulary — deck
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

**Effects are edited as rows, by one component, everywhere.**
`App\Admin\ModifierField` plus `<x-modifier-rows>` is the editor for every
modifier list in the game — `items.modifiers`, `programs.modifiers`,
`skills.modifiers`, and `archetype_perks.modifiers` when perks get a screen.
Four tables carry the same JSON, and until this existed every one of them was
authored by hand: a textarea for software and a migration for the rest, which
is a content pipeline only a programmer can use for the half of the game
MECHANICS insists must be data.

- **A screen passes a field name and its extra keys.** `skills.modifiers`
  carries a `level` per row — from which level the grant applies — and
  `<x-modifier-rows :extras="['level']">` is the whole of that difference.
- **The picker offers stats by id and tag sets by NAME.** Groups come from a
  stat's leading tag; the sets offered are exactly the ones with an entry in
  `character.names`, which is self-limiting on purpose — **a set nobody has
  named cannot be rendered on a card**, so offering it would be offering an
  effect nothing can describe.
- **A target must reach something.** `Modifier::fromArray()` judges the shape
  and knows nothing about which stats exist, so a mistyped id builds, resolves,
  matches nothing and changes nothing for ever. The editor asks the registry as
  well, and names the row that is wrong.
- **A value is an id unless it is plainly a tag set** — more than one tag, or
  one that declared stats actually wear. Anything else stays an id and is
  refused, rather than quietly becoming `all_of: ['hitpints_max']`.
- **What the editor does not understand, it carries.** A `condition`, a
  `priority` or a `source` travels in a hidden field and comes back untouched.
  Dropping them on save would be the kind of damage nobody notices until a drug
  stops wearing off.

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
