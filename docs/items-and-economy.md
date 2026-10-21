# Crafting, inventory, skills, market

_Split out of CLAUDE.md. Heading levels are as they were there._

### Code Crafting

**`CRAFTING.md` is the document** (the design, the component table, the skills). Components and a Design, over time, into a program.

- **A component is an item the decryption queue turns a stolen file into** (`cyberspace.decryption.components`: weights by the grade of the system,
  every grade summing to 100, nothing under 5 where it appears), and it **keeps where the file came from** (`App\Cyberspace\FileOrigin` inside the
  `extracted_file` JSON: system type, owner, OS, IDS and ICE level, encryption). Unstackable; `InventoryItem::label()` names it by one origin field,
  "Kernel Log (AuraOS)". A file taken before origin was recorded matches no recipe filter.
- **A Design is an item with a `designs` row** (`App\Crafting\DesignEditor` the only writer, the item editor's Design panel): a product (always a program of
  a kind with a gate in `config/crafting.php`), a base time, a Copy (`single_use`, spent when a craft starts) or an Original, an optional extra
  skill, and `design_components` lines (item, quantity, optional filters on grade, OS, owner, type, ICE, IDS). Filters live as slugs and plain ids
  with no foreign key, like the file's own copy.
- **`App\Crafting\Crafting` is the only writer of `crafts`.** Starting spends the components (lowest qualifying grade first; spending a higher grade
  than a line's minimum asks first, `CraftingRefusal::WastesGrade`) and snapshots the time (`crafting_seconds.<kind>`, base the design's, ceil) and
  the quantities (`crafting_cost` percent, ceil, min 1). Slots are the stat `crafting_slots` (base 1), not a queue. A build never fails, and cancelling
  forfeits. **The clock runs only while the equipped deck is ready**: `settle()` credits elapsed time if the deck is ready NOW, runs when
  somebody looks, in `ProgramRunner::stopAll()` (so before any deck change) and from `crafting:settle` every minute. A finished build whose product does
  not fit waits, finished, holding its slot.
- **The gates:** `skill.code_crafting` plus the kind's skill (`crafting.gate`), on the RESOLVED level; a skill that is not in the game gates nothing.
  Code Crafting shortens every build (`crafting_time` tag) and each kind's skill shortens its own (`crafting_seconds.<kind>`).
- Screens: the deck's *Crafting* panel and Current processes. Starting a build is not offered during a run (the run lock), but one in progress goes on.

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
  deck's storage (see *Programs* in `docs/decks-and-programs.md`). `weight` is stored and unenforced; the physical
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
- **The plan picker's level list follows the skill chosen**: it offers only levels above what is reached or queued
  (`SkillsController::planFloors()`, handed to the page as `data-plan-floors` and redrawn by `ui-kit.js`; drawn for
  the first skill server-side too, so it is right before any script runs). A skill with every level reached or queued
  is not offered to plan at all.
- **The queue can be reordered by dragging, and a reordering IS refused for a requirement** — unlike queueing and
  removing, which only warn. `TrainingQueue::reorder()` takes all the entry ids in the new order and asks
  `TrainingQueueValidator::problemsForEntries()` about it: refused (`ReorderRefusal::BreaksRequirement`) when the new
  order has a problem the current one lacks (a level ahead of the one before it, a skill ahead of its prerequisite),
  so a queue the player was left with by removing something can still be tidied. **The deposit runs first; the entry
  that stops being first loses its clock** (a stale one would credit its waiting time) and the new first starts one.
  The page sends the order as JSON (`training.reorder`, 422 and a message on refusal); the handle is the position
  number and works with pointer events (HTML5 drag does nothing on a phone) and ArrowUp/ArrowDown.
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
change goes through `App\Wallet\Wallet` — see *The wallet* in `docs/character-and-city.md`), the only
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


### Contracts

**A contract is a player offer (`market_offers`, written only by `App\Market\OfferBook`) with a named buyer** — `recipient_character_id`, NULL for an ordinary listing. It is made from the same card form as "Put on market", with a *Recipient* field and its own **Add contract** button (the market button refuses a typed name and Add contract refuses a blank one, so a name can never quietly become a public listing), that suggests handles as it is typed (the message screen's `data-suggest`, delegated in `ui-kit.js` so it also works in the inspector's cloned card).

- **Visibility is one scope, `MarketOffer::visibleTo($character)`**: every ordinary listing, the viewer's own, and contracts made out to them. The market screen and the `market:<id>` modal read through it; a hidden contract's link answers "this listing is gone".
- **`OfferBook::buy()` answers anybody but the recipient `Gone`**, inside the lock, so a stale page or a guessed URL learns nothing. The seller can still withdraw.
- **Chat never names one**: `Chat\Markup` leaves a contract's `market:<id>` as plain text.
- **The recipient is told** by an inbox row (`offer_contract`) when it goes up. A name that matches nobody, or the seller's own, is refused by the form. The FK cascades, so a deleted recipient takes the contract with it rather than turning it into a public listing.
