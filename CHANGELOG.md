# Changelog

Notable changes to this project, newest first.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Pre-1.0 is meant literally.** While the major version is 0, a minor bump may
change the database schema. Every entry that needs one says so under
*Migrations*, because migrations here are run by hand and never with
`migrate:fresh` against the remote — see CLAUDE.md.

## [Unreleased]

### Removed

- **Translation.** The game speaks English and only English: no `lang/`
  directory, no `config/i18n.php`, no `SetLocale` middleware, no locale
  switcher, no `locale.update` route and no `i18n:check` command. `__()` and
  `trans_choice()` are gone from the codebase — 132 call sites across 25 files.
  The `users.locale` column is dropped.

### Changed

- **Player-visible strings live in one of two places now.** On the row if the
  thing has one — `items.name` and `items.flavor` are columns, joining the
  `title` skills always carried — and in `config/strings.php` otherwise, read
  through `App\Support\Text`. Archetype names, roles and descriptions moved
  into `config/archetypes.php` beside their ids and perks.
- Counted strings are `{zero, one, many}` groups instead of ICU pipe syntax.
- The landing page loses its plurals table and its locale-aware formatting
  panel; both existed only to demonstrate translation. The item showcase stays.
- `config/app.php` keeps `locale` and `fallback_locale`: the framework's own
  validation and auth messages still come from vendor lang files keyed by them.

- **Archetypes are rows.** `archetypes` and `archetype_perks` replace
  `config/archetypes.php`. `characters.archetype` was a foreign key in
  everything but name — an id pointing into an array, which nothing could
  enforce — and is now a real one. A perk's two shapes are columns the database
  polices: live perks carry `modifiers` as JSON, display-only ones carry
  `display_modifier` and `display_unit`, and a row may not be both or neither.

### Migrations

- `2026_09_02_150000_remove_the_translation_layer` — adds `name` and `flavor`
  to `items` (backfilled for the seeded catalogue), drops `users.locale`.
- `2026_09_02_160000_create_archetypes_tables` — creates both tables and
  **inserts the roster**, ids preserved. Reference data whose ids are
  load-bearing has to exist the moment the schema does, so it is not a seeder.
  Clears any `characters.archetype` that names no archetype before adding the
  constraint; such a row was already broken and granted no perks.

## [0.1.1] — 2026-09-02

### Changed

- **IBM Plex is self-hosted**, the way Overpass already was. Nine faces (three
  weights each of Sans, Sans Condensed and Mono), split into `latin` and
  `latin-ext` by `unicode-range`, declared in `ui-kit.css`. The Google Fonts
  `<link>` and both `preconnect` hints are gone from every layout and from the
  kit gallery, so no request in the render path leaves the origin.

## [0.1.0] — 2026-09-02

First tagged version. Everything before this was two untagged commits on `main`.

### Added

- **Two inventories, and a hard gate in front of every skill.** `items` holds
  definitions and `inventory_items` holds what a character carries, one row per
  (character, item) with a quantity. `container` is a property of the item, so a
  row can never disagree with its definition about which inventory it is in.
- **Skillbooks and injection.** A `character_skills` row means the skill has been
  injected, and `App\Inventory\SkillInjector` is the only thing that creates one:
  it consumes one copy of the book and writes the row at 0 points, level 0, in one
  transaction. Training will not create that row — it refuses and logs — so owning
  and spending the book gates every skill in the game. Injecting a skill already
  known is refused and the player keeps the book.
- **Skillbooks need no lang entry.** There is one book per skill, so `ItemSeeder`
  generates them from the skills table and the name composes from the skill's own
  title. Adding a skill adds its book, in every locale, for free.
- **Containers — items that hold items.** An item with `capacity > 0` and an
  `accepts` kind holds other items; the memory shard is a physical object that
  accepts data, which `container` alone could not express. `inventory_items.parent_id`
  says where a row actually sits, depth is capped at one level, and
  `App\Inventory\ItemPlacer` is the only thing that moves rows between containers.
- **The stash screen** (`/inventory`), with both inventories, a container's
  contents shown on it, and Inject / Store / Take out actions.
- `config/version.php`, this changelog, and the release checklist in CLAUDE.md.

### Changed

- **The landing page reads the `items` table** instead of a hardcoded array, and
  takes tier names from the shared `items.tiers.*` keys. The per-item `tier` lang
  keys are gone — the tier number on the row already decided that word.
- **Two copies of an item are no longer interchangeable.** The unique key on
  (`character_id`, `item_id`) said they were, which a memory shard with a book on
  it disproves. It is replaced by a nullable unique `stack_key`, derived by the
  model: null for unstackable items so each object gets its own row, and
  `character:item:parent` for stackable ones so two piles in one place still
  collide.
- **`size` is enforced**, as what a container's contents cost against its capacity.
  `weight` remains stored and unenforced, and neither inventory has an overall
  capacity yet.
- **The UI kit type scale is one step larger** across the board — body 15→16px,
  and every `--fs-*` token with it. No component CSS changed; they all read the
  tokens.
- The signed-in nav points "Stash" at the inventory, and marks the active item
  with `aria-current`. It previously used `@current(...)`, which is not a Blade
  directive and rendered as literal text in the markup.

### Migrations

Two, both additive and safe to run forward:

- `2026_09_02_120000_create_items_tables` — `items` and `inventory_items`.
- `2026_09_02_130000_add_containment_to_inventory_items` — adds `capacity` and
  `accepts` to `items`, `parent_id` and `stack_key` to `inventory_items`, and
  drops the old composite unique key.

[Unreleased]: https://github.com/nahkampf/dedlok/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/nahkampf/dedlok/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/nahkampf/dedlok/releases/tag/v0.1.0
