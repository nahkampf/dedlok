# Insurance (designed 2026-10-25, phase 1 built 2026-10-26)

Two policies a character can hold at the same time: **property** (what you lose) and **medical** (what permanent injury costs you). Both pay
**creds**, never items, and both are for a fixed term, then lapse unless renewed. Every figure below is a first proposal for the user to adjust.

## Decided

- **Two policy types, three tiers each**: reimbursement of **50%, 70% or 90%**, each tier with its own price per term.
- **One active policy per type.** Signing again while one runs **renews** it: the new term starts when the old one ends, so nothing is wasted.
  A different tier takes effect at renewal (no downgrade or upgrade mid-term).
- **Fixed term** (proposed 14 days, `config/insurance.php`). The inbox warns **two days before cover ends** (a reminder to sign again) and
  says so again when it lapses. The player writes the strings.
- **Property pays creds at base value.** The claim is the sum of the base value of what was lost, times the tier. No item comes back.
- **Medical pays cash, priced from the clinics.** It covers **permanent injuries only**: max hit points (`physical_trauma`) and max Synaptic
  Integrity (`neural_damage`), the two `InjuryLedger` rows. Current HP and SI loss is never covered.
- **Self-inflicted losses are not claims.** Selling, trading, dropping, destroying or crafting away your own things never pays. Only a
  listed cause does. Nothing else guards against abuse: no waiting period and no cap; the price and the share under 100% are the control.

## Property: what is covered, and by what

Covered **things**: deck hardware (fitted modules), programs, files and data on the deck, armour, weapons, and items in stash or safehouse.
Covered **causes**: ICE defeat tags (Surge, Erase), a Bloodhound, a narrative encounter outcome, a combat, thermal overload (a burned module).

Every one of these already destroys or takes a row from one place, so the design is **one door, `Insurer::claim(Character, Cause, list of lost
rows)`**, called by each writer after it has deleted. It looks up the active property policy, sums `base_cost` times quantity, pays
`floor(total × share)` through `Wallet::move()` with a new `Reason::Insurance`, and sends one inbox message listing the claim. No active
policy: it does nothing. A row already settled never pays twice (see below).

First writers to wire (read each before editing, this is the inventory, not yet verified):
- `DefeatResolver::surge()` and `erase()` (they already collect `$lost` by name for the Damage report; the claim wants the rows).
- Bloodhound hunts (`HunterCombat`, `Hunt`).
- `EncounterOutcomes` where an encounter takes an item.
- `Fights` where a defeat takes gear.
- Thermal overload / burnout, where a module is destroyed.

**Value of a loss**: `items.base_cost`. Open: imprints and crafted items may have a different worth than the catalogue base (a decrypted
file is worth what the buyer pays, not a base); the first build can pay file/data at the catalogue figure or exclude them.

**Concurrency**: the claim runs inside the same transaction as the loss, after the locked check that already guards an ending (`resolveEnding()`
now settles once), so a doubled request cannot double-pay.

## Medical: the pay-out

Clinics price the two injuries per point (production, 2026-10-25): **neural damage 80 to 110 (average 95)**, **physical trauma 80 to 86 (average 83)**.
When `InjuryLedger::inflict()` deepens either, the policy pays `points × average clinic price × tier`, rounded down, as `Reason::Insurance`.
Taking the average from the **clinic table at claim time**, not a copied constant, keeps it true if prices are retuned.

Example: a Flatline that takes 6 points of max SI under a 90% policy pays 6 × 95 × 0.9 = 513 creds.

The injury still lands in full (the player pays the clinic to mend it as ever); insurance only returns cash.

## Price per term (proposal)

| Tier | Property | Medical |
| --- | --- | --- |
| 50% | 400 | 250 |
| 70% | 900 | 600 |
| 90% | 1,800 | 1,200 |

Reference: the ICE XII defeat that took Noodle's six modules and a deck's files was worth well over 5,000 creds, and one Flatline is about 500.
The 90% property tier is the one to tune against real losses.

## Where it is sold

An **insurer's office** as a City location type, one per district at first, built with the same location machinery as clinics and shops.
Open: whether it sits in a district, is run by an entity (a standing gate), or both.

## Data (proposal)

`insurance_policies`: `character_id`, `kind` (`property`|`medical`), `share` (50|70|90), `starts_at`, `ends_at`, `reminded_at`, `lapsed_at`.
A policy is active when `now()` is in `[starts_at, ends_at)`. Every table and column gets a comment (`db:comments`).
Reminders and lapse messages come from a minute-scheduled sweep, in the style of `RegenerateSynapses` (stateless, a missed run is made up
by the next, `reminded_at` and `lapsed_at` keep each message to once).

## Open questions

1. Term length and the prices above.
2. Whether files/data and imprints are paid at catalogue base or excluded.
3. Where policies are sold, and any standing gate.
4. Whether medical should cover the **mending cost** of drug tolerance and addiction (not now: only max HP and max SI, as asked).
5. Which of the causes need new plumbing: encounters and combat may not yet destroy items at all.

## Built (phase 1)

- **Tables**: `insurance_offers` (what a location sells: kind, share, price) and `insurance_policies` (one row per term; a renewal is a row
  starting when the last ends). Written only by `App\Insurance\Insurance`. A location sells cover by having offer rows, like a clinic, and
  shows the **Insurance** feature on the map.
- **Vectra Indemnity Corp** (Eastside, `vectra_indemnity_corp`) is inserted by the migration with the proposed prices for **both** kinds.
  Its description only mentions property: delete its `medical` offer rows, or move them to a clinic, if medical should sell elsewhere.
  No admin editor for offers yet: prices change in the table.
- **Renewal**: signing while cover runs queues the next term; one term ahead only. Reminders (`insurance:remind`, hourly) go two days before
  a term ends and when it has, once each, never for a renewed term.
- **Property claims** are wired to ICE Surge and Erase (`DefeatResolver`), at the catalogue `base_cost` times quantity. **Medical claims**
  are wired to a Flatline (`DefeatResolver`), a fight's maiming (`FightEnding`) and an encounter's injury outcome that deepens one
  (`EncounterOutcomes`). Surgery's own injury and a drug overdose are **not covered**, being the player's choice.
- Player text is placeholder (`[key]`) in `config/strings.php` until written.

## Not built (phase 2)

Nothing destroys items today in a burnout, a Bloodhound hunt, an encounter or a fight, so there is nothing to claim there yet. When one
does, it calls `Insurance::claimLoss($character, $worth, $lost)` after deleting. Which items go, how often, and in which fight ending is
undesigned.
