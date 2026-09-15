# Changelog

## [Unreleased]

## [0.9.1] — 2026-09-15

Damage becomes a mechanic rather than a subtraction, and a deck that stops
working no longer strands the person standing inside it.

### Added

- **A deck that stops running throws its owner out of cyberspace**, costing 1
  point of Synaptic Integrity and nothing else — no ICE was involved, so nothing
  flatlines. It is checked where a deck can be broken from the inside (pulling an
  implant, starting a program) and by middleware behind that, so you are told as
  it happens rather than at your next click. Nothing can currently cause it,
  which is the point of having it before something can.
- **Damage knows what it hurts and what caused it.** A hit is resolved like any
  other number in the game, so resistances, vulnerabilities and immunities are
  modifiers on ordinary rows instead of code: −15% against everything ICE does,
  −5% against physical damage, ×0 to shrug off a burnout entirely. Resistances
  add up rather than compounding, mitigation can never heal, and a hit absorbed
  down to nothing still counts as a hit.
- **The Crim gains "Hard knocks"** — −5% physical damage taken, and the first
  perk that affects a kind of damage rather than a single stat. It sat on the
  card as a decorative −15% for months because there was nothing for it to name.

### Changed

- CLAUDE.md documents cyberspace, implants, programs and the modifier sources
  again: every built system is described in the file read first.
- The roster has never had a Netrunner or a Solo. MECHANICS.md said otherwise in
  three places and four test comments repeated it.
- Display-only perks are no longer used by anything that ships, and the shape is
  deliberately kept for content that may want it.

### Fixed

- A perk targeting a generated stat — a skill's check, or now a damage stat —
  was reported as targeting nothing by the check that validates perks.

### Migrations

- `2026_09_15_100000_the_crim_learns_to_take_a_hit`


## [0.9.0] — 2026-09-15
Lots of cyberspace additions.

### Added

- feat(cyberspace): a machine is named for what it is and who runs it (cf85e3a)
- feat(db): the schema explains itself, and a command that says where it does not (71c9498)
- feat(cyberspace): the grid, the machines standing on it, and the deck lock (4e143a2)
- feat(stats): timed modifiers, skill effects, and implants in the body (47aa757)
- feat(images): pictures of things, found by convention (9f941db)
- feat(ui-kit): a warn variant, the sheen, the dials and the entity image (df85f3a)
- feat(programs): software that runs on a deck, and the storage it sits in (dcef5c3)
- feat(stats): conditions, the dependency graph and the character sheet (abb47ad)

### Changed

- docs: the world's corps, and what the last two features changed (08f3f9a)
- docs(mechanics): what cyberspace asks of the stat system (128eb47)
- refactor(decks): heat becomes thermal load (eef8e84)
- docs(cyberspace): write down the grid, the systems and the run (c9959f6)

### Fixed

- fix(home): the landing page advertises only what is listed (fcd16d9)

### Migrations

- `2026_09_04_100000_rename_heat_to_thermal_load`
- `2026_09_04_120000_create_programs_and_processes`
- `2026_09_04_140000_add_storage_to_cyberdecks`
- `2026_09_04_160000_create_cyberspace_grid`
- `2026_09_04_170000_create_cyberspace_discovery`
- `2026_09_04_180000_add_cyberspace_position_to_characters`
- `2026_09_04_190000_comment_every_column`
- `2026_09_05_100000_create_bookmarks`
- `2026_09_05_110000_comment_the_bookmarks_bookkeeping_columns`
- `2026_09_05_120000_create_corps_and_give_systems_an_owner`
- `2026_09_05_130000_add_grade_to_systems`
- `2026_09_05_140000_create_character_modifiers`
- `2026_09_05_150000_add_modifiers_to_skills`
- `2026_09_05_160000_grant_the_first_skill_effects`
- `2026_09_05_170000_scale_skill_grants_down`
- `2026_09_05_180000_create_implants`
- `2026_09_05_190000_add_the_dummy_implants`
- `2026_09_05_200000_remember_when_a_system_was_first_seen`
- `2026_09_14_100000_name_systems_for_their_owners`


## [0.8.1] — 2026-09-04

### Added

- feat(inventory): item actions live on the card, and the sidemenu gets a Deck (566a510)

## [0.8.0] — 2026-09-03

### Added

- Modules now create heat that has to be managed.
- All Deck modules now have heat tolerances: one value for where
  the module starts being at risk for melting, and a cutoff where
  the module 100% gets destroyed.
- We now have a new slot for Thermal Dissipators on decks.
- New modules: Thermal Dissipators. These can be active or passive.

### Migrations

- `2026_09_03_170000_add_board_layout_to_items`
- `2026_09_03_180000_create_deck_fittings`
- `2026_09_03_190000_add_equipped_deck_to_characters`
- `2026_09_03_200000_add_heat_to_cyberdecks`
- `2026_09_03_210000_add_heat_tolerance_to_deck_modules`
- `2026_09_03_220000_remove_deck_heat_capacity`


## [0.7.1] — 2026-09-03

### Changed

- Inventory view - list items + mousover modal with info
- Move top menu to a sidemenu, clear out unused stuff
- Introduce a fixed topbar containing cash, hp, si


## [0.7.0] — 2026-09-03

### Changed

- Archetype starting items/skills/cash (6d60b66)

### Migrations

- `2026_09_03_140000_create_archetype_loadouts`
- `2026_09_03_160000_narrow_the_roster_to_the_deck_jockey`


## [0.6.1] — 2026-09-03

### Changed

- docs: deck numbers are bases, and one deck is equipped (de94e66)

### Fixed

- fix(decks): label the PSU socket PSU, not Power (600a359)

### Added
- content: a bunch of modules and decks added

## [0.6.0] — 2026-09-03

### Changed

- Decks and modules (4bab64a). There are now cyberdecks and modules, decks
  have slots for various types of hardware, and hardware have various 
  properties.

### Migrations

- `2026_09_03_090000_create_cyberdeck_hardware`


## [0.5.1] — 2026-09-03

### Changed

- Remove the hardcoded catalogue of items (0670e10)


## [0.5.0] — 2026-09-02

### Changed

- Initial admin tools (items, skills) (aadd96f)

### Migrations

- `2026_09_02_190000_add_admin_flag_to_users_table`


## [0.4.0] — 2026-09-02

### Changed

- Change tiers to T1 & T2 only (c978030)
- Updated the release script so we can do our work with main checked out, it
  branches automatically to a feature and does all the magic when called

### Migrations

- `2026_09_02_180000_narrow_item_tiers_to_two`


## [0.3.0] — 2026-09-02

### Added

- Market restocker, every eight ours the market gets seeded. Depends on rarity,
  and previous purchase demand.
- Items now have rarity (common, uncommon, rare, ultra_rare).
- `market_purchases` now contains last 7 days purchases of items.
- `config/market.php` sets the rarity seed chances, caps and thresholds

### Migrations

- `2026_09_02_170000_create_the_market_supply_side` — adds `rarity` (defaults
  to `common`) and `seed_quantity` (defaults to **0**, meaning the market never
  stocks it) to `items`, and creates `market_stock` and `market_purchases`.
  Run `db:seed --class=ItemSeeder` afterwards to give the existing catalogue
  its shelf sizes, or nothing will ever be in stock.

## [0.2.3] — 2026-09-02
### Added
- build: add bin/release script to handle versioning, changelog etc.

## [0.2.2] — 2026-09-02
### Added

### Changed
- Skill tree changed to a list with accordions. Marks which skills you have injected
  and displays current level. 

### Fixed
- Overspill from skill level breakpoints now handled correctly.
- Reflex is supposed to be a physical attribute, not a mental one. Fixed now.
- The meters on skill levels on the skill page now displays correctly.

## [0.2.1] — 2026-09-02

### Added
- Skill tree at /skills

## [0.2.0] — 2026-09-02

### Removed
- Completely remove i18n. Having the game translatable at this stage is just too
  much effort and leads to weird stuff where some things live in db and some
  things in files.

### Changed
- Moved archetypes fully to db

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

- Self host **IBM Plex**. We already host Overpass, and we don't want the round-
  trip to google servers.

## [0.1.0] — 2026-09-02

First tagged version. Everything before this was just pushed straight onto `main`.

### Added

- There are now inventories, and `container` decides if it's a physical inventory
  or data. 
- We can now create skillbooks and inject them
- There are now container items
- /inventory is a first step

### Changed

- Items can now stack (`stack_key`).
- Increased the font-size slightly across the board

### Migrations

- `2026_09_02_120000_create_items_tables` — `items` and `inventory_items`.
- `2026_09_02_130000_add_containment_to_inventory_items` — adds `capacity` and
  `accepts` to `items`, `parent_id` and `stack_key` to `inventory_items`, and
  drops the old composite unique key.

[Unreleased]: https://github.com/nahkampf/dedlok/compare/v0.9.1...HEAD
[0.9.1]: https://github.com/nahkampf/dedlok/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/nahkampf/dedlok/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/nahkampf/dedlok/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/nahkampf/dedlok/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/nahkampf/dedlok/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/nahkampf/dedlok/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/nahkampf/dedlok/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/nahkampf/dedlok/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/nahkampf/dedlok/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/nahkampf/dedlok/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/nahkampf/dedlok/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/nahkampf/dedlok/compare/v0.2.3...v0.3.0
[0.2.3]: https://github.com/nahkampf/dedlok/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/nahkampf/dedlok/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/nahkampf/dedlok/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/nahkampf/dedlok/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/nahkampf/dedlok/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/nahkampf/dedlok/releases/tag/v0.1.0
