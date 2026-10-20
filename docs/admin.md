# Admin tools

_Split out of CLAUDE.md. Heading levels are as they were there._

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

**The items list has one Type filter** (`?type=`, a value is `kind` or `kind:detail`): deck hardware (and each piece), software (and each kind), drugs (any, fight-only, or one family), armour, weapon, ammunition, consumable, implant, imprint, and *plain* (none of those). `ItemController::typeGroups()` builds the dropdown and `ofType()` narrows; the old `?hardware=` and `?program=` URLs still work. A new kind of item is a line in each.

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

**Grants live at `admin/characters`**: a searchable list of characters with *Give creds* (1 to 1,000,000, through `Wallet::move()` as `Reason::Granted`, so the ledger has the line), *Full HP* and *Full SI* (`Character::recover()` to the resolved maximum, never past it), each under a row lock. Not a writer of its own: it only calls the ones that own the numbers.

