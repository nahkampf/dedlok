# Leaderboard

_Built. Seven boards; more categories later. Text is the owner's: every string is a `TODO` key in
`config/strings.php`._

## The boards

| Board | Counts | Source |
| --- | --- | --- |
| Successful intrusion runs | Runs that ended `completed`, whatever their purpose | `runs.ending = 'completed'`, per character |
| Net worth | Wallet + bank balance + every item at its stock price, minus any loan still owed | `characters.creds`, `bank_accounts.balance`, `inventory_items`, `loans` |
| Most skill points | The sum of points over all skills | `character_skills.skill_points` |
| Explorers | Grid positions discovered, over a lifetime | `achievement_progress` `grid_explored` (the account's counter, read for its one character) |
| Scanners | Systems read all the way through | `achievement_progress` `systems_scanned` |
| Attributes | The ten attributes summed as resolved: base, archetype, skills, implants, running programs, armour, modules. Not drugs, injuries, statuses or fatigue | `Character::modifiers(lastingOnly: true)` through a `Sheet` |
| Murder Hobos | Physical fights won (the opponent taken to zero) | `fights.result = 'b_down'`, so it counts the history, which the Murder Hobo achievement's own counter does not |

- **All time.** No weekly or monthly window.
- **Items count wherever they are**: carried, installed in a deck, running, stored in a safehouse (the box too). Each row is quantity x
  `InventoryItem::stockPrice()`, the one place a stock price is worked out (a decrypted file keeps its rolled value).
- **Loans**: `owed()` of the open loan is subtracted. Net worth can be negative.
- **Everyone counts**: admins and characters who have not played for a long time. No opt-out.
- **A value of 0 or less is not ranked.** Nobody is on a board for having done nothing.

## Ranking

- **Ties share a rank** (competition ranking: 1, 2, 2, 4) and are listed **alphabetically by handle**.
- **The page shows five rows**, the first five in that order. A tie across the fifth place is cut by the alphabet, and every row
  keeps its true rank.
- **Net worth shows an approximation**, the nearest of a ladder of nice figures (1, 2, 3, 5, 7 times a power of ten: `20k`, `50k`, `3M`; `Board::LADDER`), never the exact sum. The other boards show the number.
- **Below each board, your own line**: your rank, even outside the five, or "unranked". Only the rank, on every board,
  including your own net worth.

## How it works

- **A snapshot, written by a command.** `leaderboard:snapshot` runs **hourly at minute 12** (`hourlyAt(12)` in
  `routes/console.php`) and rewrites `leaderboard_entries` (board, character, value, rank) in one transaction: every ranked character
  of every board, not only the top five, so the own-rank line is a lookup. The page only reads that table.
- **Net worth is the reason for a snapshot**: it walks every item row of every character. The other two would be cheap live.
- **Where it is**: `App\Leaderboard\Tally` owns the sums, `Snapshot` writes and reads, `Board` names the seven. The page is
  `/leaderboard`, **a tab of the character window** (no rail glyph), a panel per board, each handle a link to `characters.show`.
- **The snapshot is as old as the last run**, up to an hour. The page says when it was written.

## Tests (`LeaderboardTest`)

Rank with ties and the alphabetical order; the five-row cut; net worth with an installed module, a stored item, a loan and a
negative total; a value of 0 unranked; the own-rank line inside and outside the five; the net worth figure rounding; the schedule at minute 12.

## Not decided

- Further boards (the categories to come).
- Whether a board earns an achievement for reaching the top.
