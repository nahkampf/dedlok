# Changelog

## [Unreleased]

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

[Unreleased]: https://github.com/nahkampf/dedlok/compare/v0.8.0...HEAD
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
