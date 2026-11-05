# The Ash & Ember UI kit

_Split out of CLAUDE.md. Heading levels are as they were there._

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

### ANSI art in a bio is the one place the kit's colours do not apply

`App\Support\Ansi` draws a bio's ANSI escape codes in the real **DOS palette** (`--dos-0`…`--dos-15` and `.a-f*`, `.a-b*`,
`.a-bl` in `ui-kit.css`, numbered as the palette is: 1 blue, 4 red), on a black ground, in **8-pixel cells** (`.ascii.ansi` sets
`letter-spacing: -1px` on the 9px font). It is deliberately outside Ash & Ember: a file must look as it did on a DOS screen. Nothing
else may use these tokens. Spans carry only those fixed class names, never player text. Gallery: `public/ui-kit.html#ansi`.

A text file an encounter left (`inventory.text`) is drawn the same way, in `#text-reader` (layouts/app): `data-listing="URL"` fetches the card
into `[data-listing-body]` of `#listing`, or of the dialog `data-dialog` names.

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
