## 0.33.0 (2026-10-02)

- Bounties now exist for real: creds are added to character bounties, growing with heat and grade of systems you made runs on. Nobody can collect it yet, though.
- Wallet now in sidemenu. Every transaction is logged.
- Transfer cash to other characters using the Wallet.
- Standings now in Character sheet. Current standings + every standing "transaction" logged.

## 0.32.1 (2026-09-30)

- Fixed the "Close window" button on the scan-reading screen not working on narrow screens
- Updated Laravel to patch a security advisory.

## 0.32.0 (2026-09-30)

- Heat now triggers "Hunters" jumping you in cyberspace. This triggers a straight up fight, like a run but without the trace, IDS etc.
- Automatically loads the best icebreaker available (that the deck can run) for a Hunter fight
- If an encounter with a Hunter is won, gain some creds and some heat disappears. Lose, and take damage (and lose a chunk of heat as well).
- Hunters have "grades" that are relative to the amount of Heat you have.

## 0.31.0 (2026-09-29)

- Minor rework for OS on systems you make a run on. Added skill `Kernel Exploitation` that gives a general boost against all OSes. OS skills repriced.
- New skill `Signature Scrambling`, to decrease the risk of Ensnare.
- Two new expansion cards: `BlackBox II` and `Pandora SC-652`, both boosting icebreaker damage.

## 0.30.0 (2026-09-29)

- More tuning of ICE damage.
- Big rebalancing of many, many stats, attribvutes and derived values for, hopefully, more meaningfull skill modifiers.
- Rebalanced Reactive Firewalling, Mitigation Protocols and Neural Sheathing (dead at level 1).
- SI slowly regenerates over time.
- Added skill `Opaque Routing Protocols`, for creating sturdier hop chains to protect against tracing.

## 0.29.0 (2026-09-29)

- `ORACLE` scanner buffed to +19% chan checks, and +25% scan speed, but is not available on market (only through loyalty stores).

## 0.28.0 (2026-09-29)

- Reduced the gradient for how much ICE damages you (higher grades were not survivable).
- Skills added to further reduce ICE damage taken (`Reactive Firewalling`, `Defensive Compartmentalization`).
- Added a daemon (`Spectral`) that also reduces SI damage taken from ICE.
- `Trauma Recovery` skill to reduce waiting time on the "kill" screen.
- `Showmanship` skill to increase standing gains when performing missions etc.

## 0.27.0 (2026-09-28)

- Actual implementation of storage, QB etc. Software, files and imprints now take up space. All decks got internal storage. 
- Reject "saves" to storage if storage is full (e.g files from an `Extract` operation).
- A deck over storage limit will not jack in until you make room.
- Linked imprint QB storage to their rank. Higher rank skills take up much more space on disk.
- Changed the New Character settings so no attribute can be below 10 (no fully dumpable stats).
- Fixed a bug where `Mitigation Protocols` actually reduced your icebreaker strength instead of increasing it.
- Cleaned up the effects on `Advanced Routing`, and tweaked the effects for `Power Management`.

## 0.26.1 (2026-09-28)

- Introduced stacking penalties for programs of the same type. Diminishing returns up to 6, the 7th gives 0% increase.
- Recuced mission credits reward by a factor of 10, since it was wildly unbalanced.
- Linked `Heat` to the grade of a system. Burns give more heat than a completed trace.

## 0.26.0 (2026-09-28)

- Implemented support for `Services` at `Exchanges`.
- Lockjaw exchange now has a service that takes away heat for loyalty points.

## 0.25.1 (2026-09-28)

- Halved the nightly decay of standings 
- Tweaked standing payouts for higher grade jobs. 

## 0.25.0 (2026-09-28)

- In the prep screen for a run on a system you now get to pick "loadout". This is saved as default for your next run.
- First drug introduced: `Rewire`, increases SI regeneration while reducing Intellect and Focus, for a short time.
- Changed the mechanics for how ICE guards the core of a system. If ICE is activated, extract/backdoor/burn operations pause until ICE is defeated (or you egress or are flatlined etc).
- The IDS systems now make a detection check every turn, including during the extraction phase. Higher chance of getting detected over long runs.
- Tweaked the curve for IDS detection scaling at higher grade systems, to make it possible to remain undetected.
- Added skills `Clandestine Intrusion Methods` and `Polymorphic Code` to increase stealth options.

## 0.24.0 (2026-09-28)

- New skills: `Attack Vectors` (increase damage of your Icebreakers), `Frational Siphoning` (increase siphon payouts), `Noise Seeding` (increase Heat decay), `Memory Optimization` (more RAM), `Power Management` (increases power output at the cost of higher thermal load).

## 0.23.1 (2026-09-28)

- Don't reload page on every purchase on the Market.
- Popup notifications gets removed after a delay

## 0.23.0 (2026-09-28)

- Send a combined report of all your siphons each night.
- `Training Queue` now shows how long each entry will take to complete.

## 0.22.1 (2026-09-28)

- Info-modal on imprints on hover.
- Lots of SQL optimizations, to greatly reduce page loading time and DB overhead.

## 0.22.0 (2026-09-28)

- Mark items you already have in stash on the market, and mark skills you've alredy injected.
- `Bounty` warning sent once a week.

## 0.21.2 (2026-09-28)

- Receive a message when losing a backdoor to a system.

## 0.20.0 (2026-09-24)

- `Loyalty store` now exists at exchanges.

## 0.19.5 (2026-09-24)

- Minor bugfix: A few itemn spec lines and labels showed stubs instead of titles.

## 0.19.4 (2026-09-24)

- Display game version under the logo

## 0.19.3 (2026-09-24)

- Added Ko-Fi button to the main page.

## 0.19.1 (2026-09-24)

- Some minor language changes

## 0.19.0 (2026-09-24)

- `Missions` now exist. Agents at various `Exchanges` (exchanges seeded on the grid) hand out missions. Complete for credits, standing increase and loyalty points. Exchanges have grades, which determines difficulty and reward.
- Every player has their own mission board, refreshed every hour.
- Message when a scan file goes stale.
- Message when someone buys one of your listed items.

## 0.18.0 (2026-09-24)

- `Scan files` are now created upon completed scan. Can b e used to sell it to others, as "payment" for certain missions, or to transfer knowledge to other players. Consumed on "read".
- `List on market` skeleton, for selling items to other players.

## 0.17.0 (2026-09-23)

- Icebreakers now exist. Various grades, with various requirements and bonuses.
- Bugfix: `Jacking in` failing near the entry portal

## 0.16.0 (2026-09-23)

- Systems on grid now owned by entities. Various entities created.
- Exchanges added to grid (non-hackable interactable systems for these "mission-giving" entities)
- Renamed skillbooks to Eidetic Imprints

## 0.15.1 (2026-09-23)

- Some work on drugs: simple tolerance, overdose mechanics, effects etc.
- Training Queue now shows total time, skills have the skill modal on hover.
- Opening a message marks it as read
- Added a logo to the front page

## Earlier versions
- Earlier version changelogs not in this document.
