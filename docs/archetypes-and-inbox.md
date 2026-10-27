# Archetypes, inbox

_Split out of CLAUDE.md. Heading levels are as they were there._

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
  **What rides on a message is `details`, in one of two shapes**: a siphon report's list of lines, or a finished read's
  `facts` (the scan's rows as `SystemScan::facts()` returned them, drawn by `<x-scan-facts>`, so the message shows what
  the system overview shows as it stood when the read finished). Messages from before titles were given the title they
  would have had by `2026_10_06_130000_old_messages_get_titles`, read back off their stored sentences.
  **Unfolding one reads it** — `ui-kit.js` POSTs `notifications.mark` (204) on the first open and lowers the rail's
  count — and *Go to* is the old `notifications.open` (read, then the category's route).
- **Messages can be deleted**, one from its opened body or the ticked ones from the top (a select-all box and *Delete
  selected*). The ticks sit in the rows and belong to one form through their `form` attribute, so no row form is nested
  in another. `Notifier::delete()` is the writer, scoped to the account: somebody else's id is simply not counted.
  Real and unrecoverable, with no confirmation (a message is never the only record of anything).
- **A player can write to another** (*New message*, `MessageController`, `messages.create` / `.store`): To (a character's handle, suggested
  as you type from `messages.recipients`, handles only, eight at most, never your own), Title (100), Message (500, the column's
  width). It lands as an ordinary notification in the recipient's account (`Category::Personal`, `Notifier::sendFrom()`), with
  who it is from in `details['from']`; the inbox shows *From:* and a *Reply* link (`messages.create?to=`) instead of *Go to*.
  **Everything typed is reduced to plain text before it is judged and stored** (`App\Support\PlainText`: entities decoded, script/style
  blocks and every tag removed in a loop, control and invisible formatting characters such as bidi overrides dropped; "I <3 you"
  stays), so "<b></b>" is an empty title; **it is written as typed and never through `Text::get()`** (a `:placeholder` in somebody's
  words is not filled), and every view still prints it escaped. Sending is throttled (15 in 10 minutes). A message to yourself is refused.
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
- **Two boxes: Priority and Standard** (`App\Notifications\Priority`, `notifications.priority`, default standard). It is how much a
  message matters, which `Category` still refuses to say. **Which system key is Priority is the list in `config/notifications.php`**;
  an unlisted key is Standard, and a test holds the list to real message keys. **A message a player wrote (`sendFrom()`) and a story
  message (`story()`) are always Priority.** The inbox shows one box at a time (`?box=important|standard`, tabs with their unread counts)
  and lands on Priority unless only Standard has unread mail. *Mark all read* reads the box it is in. **The rail badge counts both
  boxes but is ember only while a Priority message is unread, grey (`.dock__count--quiet`) otherwise**; the poll carries `priority`
  beside `unread`. **`latest`, which plays the chime, is the newest Priority id**, so Standard mail is silent. Messages sent before
  the column were flagged by `2026_10_30_100000_message_priority` (by category and by the title a listed key would have had).
- **Every sender is something nobody pressed a button for** — a finished read, the nightly backdoor roll, a lockdown or burn taking a door, a skill level, an achievement (`insertOrIgnore`'s row count is what makes "said once" true), a mission expiring, a trip landing, cash arriving. Grep `Notifier::send` for the list.
- **The inbox is polled every five seconds from every page** (`notifications.unread`, JSON `{unread, latest}`: two indexed
  reads, in every lock's allow-list so a player locked into a run, fight, hunt, encounter or the down screen keeps their
  badge). `ui-kit.js` redraws the rail's badge and plays `public/assets/sound/message.wav` when `latest` is higher than
  the newest id the page knew, at most once in ten seconds (the moment is kept in `localStorage`, so changing page does
  not restart the count). Browsers refuse sound until the person has touched the page; that refusal is swallowed and the
  badge still moves.
- **Accepting a job writes one message, the inbox's one exception to "while nobody was looking"** (`MissionLedger::briefing()`,
  `notifications.messages.mission_accepted`): what the job is and for whom, the systems BY ADDRESS (in `details['targets']`, drawn with
  the copy-an-address button, and in the sentence), what it pays and costs, and when it is due (`Y-m-d H:i UTC`: absolute, because the
  body is never recomposed and "in 9 hours" would be false tomorrow). The desk's board is gone once closed and a runner works from the
  addresses, so a record to come back to is worth the exception. A refused acceptance writes nothing; a supply job names a file count.
- **A SURVEY (`MissionAction::Scan`) is handed in as files** (`MissionLedger::handInSurvey()`, called by `complete()`): one whole, unexpired,
  loose scan file per system the job names, of THAT system and made before the job or during it; all or nothing, the files are spent
  (one file is not sold twice) and `satisfied_at` is written on the targets as the receipt. **Nothing records a scan against a survey
  as it happens** (only Burn and Plant record where they happen), so scanning alone never ticks it: the player has to export the
  files first. Until 2026-10-08 `complete()` never consulted files for a survey at all, so none could be completed. The desk's card
  tags each system *File in hand* / *Needs a scan file* (`heldAddresses()`; `heldFiles()` is the one definition of what counts).
- **Reading is its own POST**, never something opening the screen does — a badge cleared
  by arriving is a badge cleared for messages nobody read. All of them at once, because
  the alternative is a button per row for a state nothing reads except a count.
- **`.dock__count` is the rail's only number**, on the icon rather than beside the label
  so it is legible while the rail is shut. Filled ember at rest, which rule 2 otherwise
  keeps for hover: the same licence `.dot--live` has, spent on 16px that is only rendered
  above zero, and it does not pulse.

