# Character sheet, equipment, wallet, City, chat, tasks, supporters, fortresses

_Split out of CLAUDE.md. Heading levels are as they were there._

### The character sheet

**One rail item stands where four did.** Skills, Implants and Achievements each had their
own, and are tabs of `/character` now (as is Standing, below) — because to a player they are one screen: who you
are, rather than what you are carrying.

- **THE TABS ARE ROUTES, NOT PANELS** (six now: character, skills, implants, equipment, standing,
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
  **A bio may carry ANSI art**: `ESC[…m` (reset, high intensity, blink, 16 DOS colours) and the cursor moves (`A B C D`, `H`/`f`, `s`/`u`), everything else
  dropped, drawn on an 80-column grid by `App\Support\Ansi` (see *Frontend*): moving never paints, a later write replaces a cell, and a
  character that would land in column 81 wraps. The *Add ANSI file* button (`character.bio.ansi`,
  `CharacterController::ansi`) converts a CP437 file to UTF-8 and appends it after a newline; it reads the SAUCE iCE flag into
  `characters.bio_ice` and cuts the record (everything from the first U+001A). Pasted ANSI has no SAUCE, so it is never iCE. Chat does
  not render ANSI: `PlainText` still strips ESC there.

### Equipment

**The Equipment tab (`/equipment`, read only) draws what is on a body**: armour in four places, a belt, and a weapon in
each hand, on the same wireframe as Implants (`<x-equipment-map>`, `.bodymap`). Things go on from the stash card and each
filled slot here carries the *Take off* button for the route that put it there (`inventory.wear`, `.wield`, `.belt`).
The sheet no longer carries an equipment panel.

- **Every slot line has an *Equip* button that opens that place's dialog** (`Equip: <place>`, a table of what the stash
  holds that would go there: armour whose wear slots include it, drugs for the belt, ranged weapons for Primary and
  close ones for Secondary; what is already equipped is not offered). Each row's button posts to the same toggle route
  (`inventory.wear`, `.wield`, `.belt`), so one writer serves the stash card and this screen. The body map uses short
  names (`equipment.map.*`) so its labels cannot run into each other.
- **Armour is worn on four `WearSlot`s — head, torso, arms, legs — and arms and legs are one slot each, covering both
  sides.** `ArmourSlot` (six hit locations) is still where a hit LANDS and what `worn_armour`, `WornArmour` and an NPC's
  per-part resistances use. `Armour::covers()` expands whatever is stored to whole groups, so a legacy one-armed piece
  covers both arms and nothing under it changed; the item editor ticks four boxes and `ArmourEditor` stores the six they
  protect.
- **Primary weapon (right hand) is the ranged weapon and Secondary (left hand) the close one.** A relabel: a weapon's
  class already picks its hand, and `equipped_ranged_id` / `equipped_melee_id` are unchanged.
- **The belt is three slots (`config/equipment.php`) on `inventory_items.belt_slot`**, written only by
  `App\Equipment\BeltEquipper`. A slot holds one ROW, so a pile of stims is one slot and the whole pile goes on. Only
  drugs and consumables may be belted today. A belted row has no stack key (like a running program) so it does not merge with the loose
  pile; taking it off merges it back. A belted row is on the person: it cannot be listed, sold or put in a container.
- **A FIGHT-ONLY drug IS usable from the belt in a fight** (see *Drugs* in `docs/encounters-drugs-cyberspace.md`): `ActionKind::Use`, the row id in `Action::$round`.
  **An ordinary belted drug and a consumable still are NOT** (`Fights` refuses them as `CannotUse`, `Consumer` as `InAFight`):
  that stays unbuilt, and the belt holds them for nothing yet.

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

**`docs/CITY.md` is the document.** Maps, travel, exploring, shops, clinics and surgery are built.

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
- **BUYING FROM PLAYERS IS ONE RULE FOR LOCATIONS AND EXCHANGES** (`App\Trade\Buying`; `LocationBuy` and `EntityBuy` share `BuysFromPlayers`).
  A listing is an item, a `percent` of its STOCK PRICE (default 100; `InventoryItem::stockPrice()`: `base_cost`, or for a file its OWN
  value — sealed on an Encrypted Data Dump, broken on a Memory Dump) and a `max_quantity` per window (NULL: any number). **The
  limit is the buyer's, shared by every player**, counted in `bought` and zeroed lazily when the window (`city.sell.reset_hours`, 8)
  rolls over, under the listing's lock. A sale over the limit is REFUSED (`CityRefusal::WontTakeMore`), never reduced. A location's list
  is edited in the location editor; an exchange's is the entity's, over a range of desk grades, on the entity shelf page
  (`entity_buys`, `DeskBuyer`, `exchange.sell`) and obeys the desk's welcome gate. `BuyListEditor` is the only writer of both and
  DIFFS, so saving never hands a buyer a fresh limit.
- **DECRYPTION** (`App\Decryption\DecryptionQueue`, `docs/CYBERSPACE_RULES.md` under *Node contents*): a reorderable queue of stolen files broken in
  cycles by one roll each, by **every Decryptor program running on the equipped deck, one file apiece** (`decryption_queue.worker_id`); the Encrypted Data Dump row becomes a Memory
  Dump IN PLACE. `decryption_queue` is written only there; `decryption:settle` runs every minute; `ProgramRunner::putBack()` settles
  before any decryptor stops. A Decryptor can be limited to some encryptions (`programs.decrypts`, slugs; NULL is any, edited as ticks in the item editor): a file waits
  for a running decryptor that can break ITS encryption (`DecryptionQueue::decryptorRunning()`). Ghostkey (Cinder only) is the first. Difficulties and gates are `cyberspace.decryption`, the cycle length the stat `decryption_cycle_seconds`, each cycle's chance scaled by `decryption_chance.<slug>` (tag `decryption_chance` for all), the floor `checks.floors.decryption`.
- **A location is a shop by having `shop_items` rows and a clinic by having
  `clinic_services` rows, and buys from players by having `location_buys` rows;
  `FeatureEditor` is the only writer of all three.** Shop listings are edited as a DIFF —
  a listing carries a stock count, so saving the editor must not refill a shelf — and
  the buy list and clinic are replaced wholesale. **Shop stock restocks lazily**
  (`ShopItem::restock()`, settled under a lock by `ShopKeeper`); no `max_stock` is
  unlimited. **What a place buys vanishes** and `ShopKeeper::sell()` only takes what is in
  the character's hands. **`ShopKeeper` is the only door from a City shop to a stash** and writes
  neither of the market's tables nor looks at `on_market`; **`Clinic` prices per point
  restored** (and treats a drug family's `drug_<family>_addiction` / `_tolerance`, twelve `ClinicService` cases, by the
  stored figure rounded up, through `DrugLedger::lowerAddiction()` / `lowerTolerance()`), and its `quote()` and `treat()` share one `owed()`. Both ask `Presence` inside
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

### Chat and websockets

**`/chat` is its own page, never locked** (every lock's allow-list names `chat.*` and `broadcasting.auth`). General, rooms (`/invite`,
`/leave`, `/ignore`, `/unignore`, `/msg`) and direct lines; `App\Chat\Chats` is the only writer of `chats`, `chat_messages`,
`chat_members`, `chat_invites`, `chat_ignores`, and the one place that says who may see a chat (a stranger gets 404).

- **Lines are pushed over a websocket** (Laravel Reverb, the Pusher protocol; `ChatLine` event, private channels in `routes/channels.php`:
  `chat.general`, and `chat-user.{characterId}` which receives every room and direct line that character is in). The page draws no
  line in script: the event carries the server-rendered `chat._line`. **A socket that is down never costs a message**: sending
  is a POST, and the page polls every five seconds (`chat.poll`) whenever it has no socket.
- **PRODUCTION NEEDS TWO THINGS THE GIT WORKER CANNOT DO**: a `php artisan reverb:start --host=127.0.0.1 --port=8080` process kept
  alive as `dedlok` (needs ext-pcntl on the CLI PHP), and an Apache proxy of `/app` to it (`ProxyPass /app ws://127.0.0.1:8080/app`,
  mod_proxy_wstunnel). `.env` there: `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST=127.0.0.1`,
  `REVERB_PORT=8080`, `REVERB_SCHEME=http` (what the app publishes to) and `REVERB_PUBLIC_HOST=<the site>`, `REVERB_PUBLIC_PORT=443`,
  `REVERB_PUBLIC_SCHEME=https` (what the browser connects to). Until then the chat works by polling.
- Dev: the `reverb` compose service, browser on `localhost:8085`.

### Standing, as a tab

`/standing` is the fifth character tab: entities with a non-zero `entity_books.standing`
(band word and figure), and the `entity_ledger` standing entries newest first. **Loyalty is
not shown** — it is spent at the store and has its own story. Zero-delta entries (an act
against somebody already at −100) are hidden. Read-only: `StandingLedger` stays the only
writer.

### Player counts on the front page

**Under the version number, two small lines: *Total players* and *Active players last 7 days*, the figures green** (`home.blade.php`,
`HomeController`, cached five minutes under `home.player_counts`). A player is an account that has made a character. Active is
`users.last_active_at` within seven days, written by `App\Http\Middleware\TouchLastActive` at most once every five minutes and
**never by the inbox poll** (`notifications.unread`: an open tab polls every five seconds whether anyone is there or not).
Accounts that existed before the column are NULL until their next visit, so the active figure starts low and fills in.

### Tasks & Missions

**A rail item and a screen (`/tasks`, `TasksController`) with three headings.** *Missions* lists the exchange jobs the character has
ACCEPTED and not yet finished (`Mission::accepted()`: failed, completed and voided are history, an offer is the desk's to show),
soonest deadline first, each as the desk's own `<x-mission-card>` with `:actions="false"` (no Complete: a job is handed in at the desk
it came from) and `:abandon="true"` (giving one up needs no desk; its tooltip is *Costs -X standing*, the row's penalty) and `:desk="true"` (who it is for, the agent, the desk's address). *Journal* lists the notes an encounter has written
(`App\Journal\Journal`, the only writer of `journal_notes` and `journal_entries`): a note is a heading with a slug, a line is a row under one with a
slug of its own; newest first at both levels, a completed note is not shown and a completed line is struck through. **Encounter outcomes write
it** (`OutcomeKind::Journal`: a note, a line, or both, idempotent by slug; and `JournalDone` by slug). *Tasks* is the player's own list (`PlayerTask`, table `player_tasks`; `PlayerTaskController`; a reminder that
nothing in the game reads): a one-line text (255) and a priority range (low, medium, high; `App\Tasks\TaskPriority`, whose tags are grey, yellow, ember),
listed high first and newest first within one. The checkbox beside a title ticks it off at once (`[data-autosubmit]`) and sends it to the
*Completed tasks* modal, where *Reintroduce* brings it back. Safehouse leads are listed beneath. The rail glyph is U+25C7, one already proved to render.

### Supporters

**A flag on the account** (`users.is_supporter`, `supporter_since`; outside `User`'s `#[Fillable]`, like `is_admin`). **`App\Supporters\Supporters` is the only
writer** (`grant()` / `revoke()`), reached from `php artisan supporter:grant <email> [--revoke]` and the admin screen at `admin/supporters` (a searchable
list of accounts with a button each; the command is no use on a host whose CLI PHP is not 8.4, hence the screen). A supporter gets:

- a **badge** (`<x-supporter-badge :character>`, a `.tag--ember`) by the character's name on the sheet and on market offers;
- an **unlimited training queue** (`TrainingQueue::limitFor()` is null for them, `LIMIT` = 5 for everybody else; the skills screen draws no `/5`);
- a place on the public **Supporters page** (`/supporters`, linked from the front page): the name they gave for the credits (`users.credit_name`),
  else their character's handle, in the order they became one; an account with neither is not listed;
- **Ko-fi**: `POST /kofi_webhook` (`KofiWebhookController`, CSRF-exempt) takes a *Subscription* payment, trusts it by `verification_token` (`KOFI_VERIFICATION_TOKEN`; unset refuses everything), matches the payer's email to `users.email`, records it once by `message_id` in `kofi_payments`, and grants the flag until now + 1 month (`users.supporter_until`; NULL is for good, and a payment never shortens a for-good grant). `isSupporter()` is false once the date has passed; `supporters:expire` (hourly) clears the stored flag. An unmatched email is kept and logged, and nobody is granted.
- a **picture of their own** on the character sheet: `App\Characters\Avatar` (the only writer) decodes the upload, centre-crops it to 3:4, resamples to 300x400 and writes `public/images/characters/avatars/<id>.jpg` (gitignored), shown only while the account is a supporter (the sheet picks `Image` kind `characters/avatars`, whose default walks back up to `characters/_default.jpg`) — a lapse shows the default and keeps the file, so paying again switches it back; nothing is kept of the original; uploading is `403` for a non-supporter; capped at 16 megapixels (128M memory limit) and EXIF orientation is not applied;
- **one message** when the flag goes from off to on (`Category::Support`, `notifications.messages.supporter_welcome`): thanks, what they now have, and the
  Discord invitation (`strings.discord.url`). Revoking sends nothing; granting again sends it again.

### The Discord invitation

**A modal that opens by itself, once, on a new account's first sign-in** (`<x-discord-invite>` in `layouts/app.blade.php`, shown while
`users.seen_discord_at` is NULL and somebody is signed in). The same shape as the patch notes: `data-show-on-load`, and a form
marked `data-post-on-close` posts `discord.seen` however the dialog is closed (`DiscordInviteController`, idempotent). The post is
in `RequireCharacter`'s allow-list because the first thing a new account sees is character creation. **Existing accounts are
marked seen by the migration**, and `UserFactory` makes test users seen, so only a test that sets `seen_discord_at` to NULL meets it.
Text and URL are `strings.discord.*`.

### Data Fortresses

**`docs/CYBERSPACE.md`, *Data Fortresses*, is the document and has the list of what is built.** A player's own node: a `System` of type `fortress`
with `owner_character_id`, founded by spending a Kernel item, run on resources its Leechers borrow.

- **Everything is in `App\Cyberspace\Fortress*` and `Leeches`, one writer each**: `Fortresses` (founding, freezing, alarms), `Leeches`,
  `FortressHost` (hosting and the shutdown order, and the one place the running ICE/IDS/cloak are synced onto the system row),
  `FortressAccess` (roles, members, settings), `FortressAreas`, `FortressVault`, `FortressMarket`, `FortressDefence`, `FortressRaid`,
  `FortressRelocation`, `FortressLog`. The simulator reads the system row it always read; nothing in it knows about a fortress except
  the Extract/Burn branches, the Leech action and the turn gap.
- **A fortress is never shut, restocked or re-rolled like an NPC node** (`Lockdown::afterRun`, `NodeStocker` and `DefendSystems` skip the type).
- **`fortress:settle` (every five minutes) and the fortress screen both call `FortressHost::settle()`**, so a fortress is true when looked at
  and between looks. A hosted program is an `inventory_items` row with `fortress_system_id` set: out of the stash (`scopeLoose`), no stack
  key, one copy per row.
- **Every number is a config placeholder** (`cyberspace.fortress.*`); balance is the last step.

