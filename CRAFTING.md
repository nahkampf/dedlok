# CRAFTING.md — Code Crafting

Status: **designed 2026-10-08, nothing built except where a step below says so.** Decided with the author in conversation; do not re-ask.

Players turn **Components** (data files that come out of decrypted stolen data) and a **Design** (a recipe) into **programs**, over time, on a working deck.
Some products are programs that already exist (an icebreaker, a decryptor). Others are **Scripts**: one-use programs that act like a timed modifier.

## Settled

- **A Script is a consumable program that runs like any other, then expires** (Option X). It is a `programs` row with a lifetime. *Run* takes one
  copy, it holds RAM and qFLOPS on the deck while it lives, and it vanishes at the end. Stopping it early destroys it. It reuses `ProgramRunner`,
  the `RunningPrograms` modifier source, the Running modal, deck readiness, kind diminishing. **One of each unique Script at a time**
  (a new `ProcessRefusal` case). Scripts stay off training stats until `RunningPrograms` can look back.
- **A Design names one product item and a recipe**, and has no "kind" of its own: whether the product is permanent or a Script lives on the product.
  A recipe line is a component item, a quantity, and optional filters on its origin (grade min/max, OS, owner, system type, ICE and IDS minimums).
  A design needs a skill and level beyond the general gate. **The editor refuses a product whose program kind has no crafting skill.**
- **Two Design types.** A **Design Copy** is deleted when the craft starts; a **Design Original** is multi-use. Both are loose data items, sellable,
  and can be destroyed by ICE (a later step: a new `AttackTag` case). A craft needs the Design in the stash.
- **Components are spent when the craft starts** ("locked in"), and the recipe is snapshotted on the craft. Cancelling forfeits them.
- **No queue: crafting slots.** `crafting_slots` is a stat, base 1; skills raise it. A craft starts only when a slot is free. One copy per craft.
  **Crafting cannot fail.** A finished craft whose product does not fit (`Storage::fits()`) waits, finished, holding its slot.
- **Crafting needs a functional equipped deck and no program.** Not ready (or no deck) pauses it. Crafting continues during a run.
  The clock is `crafting_progress_seconds` on the craft, credited by a `crafting:settle` sweep every minute (60 s if the deck is ready *then*),
  by a lazy settle when somebody looks, and by a settle before anything that changes the deck (equip, fit, strip, stop a program). Accurate to
  about a minute; a drug wearing off between sweeps is noticed at the next one.
- **Component choice:** a filter with a minimum grade is satisfied by the LOWEST grade that qualifies first. Starting a craft that would spend higher
  grades than the recipe needs warns first: *"You are using components of a higher grade than necessary, are you sure?"*
- **Files keep where they came from.** Every extracted file records, at extraction and copied rather than referenced: system type, grade, owner
  (entity slug and name), OS (skill slug and title), IDS level, ICE level (and the encryption it was sealed with). Decryption converts the row in
  place into a component and the record travels with it. **Files taken before this have a grade at most, so a recipe filter on anything else never
  matches them.** Components stay UNSTACKABLE rows (each has its own rolled value); the stash and the crafting screen GROUP them by item and
  headline origin, e.g. *Kernel Log (AuraOS)*.
- **Skills** (category *Code Crafting*, all on the market, five levels, cost is `base_cost`):

  | Skill | Rank | Attributes | Cost | Requires | Effect |
  | --- | --- | --- | --- | --- | --- |
  | Code Crafting | 16 | Intellect, Memory | 700 | Cyberdecks 3 | −4% crafting time per level, every product |
  | Script Crafting | 8 | Memory, Intellect | 1100 | Code Crafting 1 | −3% crafting time per level, Scripts |
  | Icebreaker Crafting | 16 | Intellect, Memory | 2000 | Code Crafting 3 | −3% per level, icebreakers |
  | Analyser Crafting | 16 | Intellect, Memory | 2000 | Code Crafting 3 | −3% per level, analysers |
  | Decryptor Crafting | 16 | Intellect, Memory | 2000 | Code Crafting 3 | −3% per level, decryptors |

  Each type skill also gates its kind at level 1 (config, like `cyberspace.decryption.gate`). Time is a generated stat `crafting_seconds.<kind>`,
  all tagged `crafting_time`: Code Crafting targets the tag, a type skill its own id. Percentages sum, applied once (−35% at both maxed). Skills
  for fewer components (`crafting_cost`, percent, rounded up, never below 1) and for more slots are to be authored later.
- **Components can come from several places later; to start they come out of decrypting Encrypted Data.** `DecryptionQueue::outcomeFor()` becomes
  a weighted roll by grade (an injected die), configured like the encryption bands.

## Components and where decryption yields them

A component has a window of grades where it can appear, and inside it is **never below 5%**. Every column sums to 100 and no grade has more than 20
eligible components (a config test holds both). A recipe can be satisfied from one grade 4-6 system.

| Component | Shown as | G1 | G2 | G3 | G4 | G5 | G6 | G7 | G8 | G9 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Memory Dump | grade | 20 | 15 | 12 | 8 | 7 | 6 | 5 | 5 | 5 |
| Kernel Log | OS | 15 | 12 | 10 | 8 | 8 | 7 | 7 | 5 | 5 |
| Cipher Fragment | encryption | — | 5 | 6 | 6 | 7 | 7 | 8 | 8 | 10 |
| Temporary File | system type | 15 | 12 | 8 | — | — | — | — | — | — |
| Cache File | system type | 10 | 9 | 7 | 6 | — | — | — | — | — |
| Error Log | OS | 10 | 8 | 7 | 6 | 5 | 5 | 5 | 5 | — |
| Stack Trace | OS | 10 | 8 | 7 | 6 | 5 | 5 | 5 | 5 | — |
| Garbage Collection | OS | 10 | 8 | 7 | 6 | 5 | 5 | — | — | — |
| Config File | OS | 10 | 7 | 6 | 5 | 5 | 5 | — | — | — |
| Backup | owner | — | 5 | 6 | 6 | 6 | 5 | 5 | 5 | — |
| Customer Record | owner | — | 6 | 7 | 6 | 6 | 5 | 5 | 5 | — |
| Software Manifest | owner | — | 5 | 6 | 6 | 6 | 5 | 5 | 5 | — |
| Project File | owner | — | — | 6 | 7 | 7 | 6 | 6 | 6 | 7 |
| Security Profile | IDS/ICE | — | — | 5 | 7 | 7 | 7 | 8 | 7 | 10 |
| Research Data | owner | — | — | — | 6 | 7 | 7 | 8 | 8 | 12 |
| Design Document | owner | — | — | — | 6 | 6 | 6 | 6 | 6 | 8 |
| Experimental Data | grade | — | — | — | 5 | 6 | 7 | 8 | 8 | 12 |
| Field Test Data | grade | — | — | — | — | 7 | 7 | 8 | 8 | 11 |
| Parameterized Dataset | grade | — | — | — | — | — | 5 | 6 | 7 | 10 |
| Vectorized Dataset | grade | — | — | — | — | — | — | 5 | 7 | 10 |

(A first proposal; the author may change it. Access Token and Watchdog Signature were dropped.)

## The first Script

**DUMMY_TEMPORARY_PROGRAM** (placeholder name), kind Script, lifetime 10 minutes, **+10% `stealth`** (a pool with its own base, so it needs no other
program running). Recipe: **3× Memory Dump, grade 1 or higher; 1× Kernel Log (AuraOS); 2× Temporary File (any system type); 1× Cache File (any
system type).** Running cost suggested at 0.4 QB RAM (needs decimals); the rest is the author's to set in the editor.

## Build order

1. **Decimal QB and qFLOPS** (own release; see below).
2. Lifetime programs and the Script kind.
3. Components: origin on files, the weighted decryption outcomes, the component items, grouped display.
4. Designs, `crafting_slots`, the crafting clock, the skills, the screens.
5. ICE shredding (an attack tag).
6. Balance.

### Step 1: decimal QB and qFLOPS

Every storage, RAM and CPU quantity gets **precision one**: program costs (`programs.storage_size`, `memory_cost`, `qflops_cost`), item `size`,
hardware measures (`deck_modules.qflops`, `quantabits`, storage capacity, `onboard_storage`), **file sizes** (`size_qb` on contents and in extracted
and scan file blobs), and the resolved stats `deck_qflops`, `deck_quantabits`, `deck_storage` (rounding `tenth`). Columns become `DECIMAL(…,1)`;
existing values convert exactly. **Float drift is the risk**: sums are rounded to one decimal at the totals, and every "does it fit / is it ready /
can it start" comparison is made on whole tenths (`Storage::fits`, `DeckRequirement`, `ProgramRunner::refusalFor`). Display trims `.0` (*2 QB*,
*0.4 QB*); editors take `step="0.1"`.
