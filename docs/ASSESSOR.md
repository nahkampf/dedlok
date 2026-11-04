# The Assessor (planned and built 2026-10-27)

A **utility program** that tells the player roughly how a run will go. On `/run/setup` it shows one overall chance to finish the job and get out alive; during the run it
shows a chance beside each action that is a real roll. It only knows what the player knows: the scan. Unknown facts give `[???]`, never a guess.

## Decided

- **"Win" = finish the job AND get out alive.** The purpose is reached (extract, burn, install...) and the run ends in `Completed`, not a defeat tag. One number.
- **Per-action chances: only actions that are a real roll.** Actions with no roll show nothing.
- **`[???]` is per number**, by the scan facts that number needs. The overall is `[???]` if any fact it needs is unknown.
- **Accuracy: the true chance, blurred by the program.** It uses the real calculation, then rounds to the program's `assess_step`. Rounded to nearest, never floor. The best program rounds to 5%.
- **It must already be running** on the equipped deck. Picking it in the setup loadout is not enough; no running Assessor, no figures (no `[???]` either, the lines are simply absent).
- **Attack shows turns to break**: "about N turns" for the ICE, from its integrity and the running icebreaker's damage per turn (a critical is ignored). It is not a chance, so it is not blurred by the step.
- **No ICE and no IDS: report the number**, which will be high.
- **Fortress and Denial runs are left out.** Ordinary runs only.

## The one rule that already binds

A screen that quotes a chance and the attempt that follows must be **the same calculation** (`CheckRoller` header; `Simulator::egressChance()` and `coreChance()` are public
for this). The Assessor never owns a formula. It calls `CheckRoller::chance()` with the same inputs the roll uses, and every input comes from a function the roll also calls.

## Rolls in a run today (from `Simulator`)

| Roll | Inputs | Scan facts needed |
| --- | --- | --- |
| Crack the core (`coreChance()`) | OS value (skill level table + modifiers) vs `os.difficulty` 24 | OS |
| Detection by the IDS (`idsWatchesThePull`, `noticedAtTheCore`) | `stealth` vs system `ids_detection`, kind `detection` | IDS |
| Trace gets through each hop (`traceGetsThrough`) | route grades vs your routing; `tracer_rate` per turn once detected | tracer, grade (route is the player's own chain) |
| ICE strikes each turn (`iceStrikes`) | d100 ≤ ICE `skill`; damage `attack`; eject/virus tags | ICE (level gives skill, attack, tags) |
| Egress (`egressChance()`) | `egress` pool vs grade difficulty, + term per awake ICE, x Ensnare | grade, ICE |
| Infiltrate | `stealth` vs 70 | none (constant) |

Not rolls: Attack (damage is fixed per turn, only a critical is random), Burn, Install, Extract. **Step 1 of the build is to confirm that by reading
`attack/extract/install/burn` and listing every `roll`/`chance` call**, since "only real rolls" depends on it. If Extract or Install turn out to roll, they get a figure.
Attack shows nothing as a chance; open question 3 asks whether it should show turns-to-break.

## Design

**The program.** `ProgramKind::Utility` with a new ability column `assess_step` (below), alongside the flags `locates`, `walks`, `jumps`. Authored in the item editor like the others; runs in the background
on the deck and pays its qFLOPS and memory like any program. The UI shows figures only while one is running on the equipped deck (the way `Jumper::available()` gates the Jump button).

**The accuracy is the program's own.** No skill. A program carries `assess_step`, a nullable whole number authored in the item editor: NULL means it does not assess, 5 is the best a program can
be, 10 or 20 a cheap one. This replaces a boolean `assesses` flag, so one column says both "is an Assessor" and "how blurry". Figures are rounded to the nearest multiple of the step, then clamped to 5–95 so it
never says 0% or 100% (the game's own floor is 0.85%). **Only one Assessor can run at a time**: starting a second is refused by `ProgramRunner::start()`, like one icebreaker at a time, so there is nothing to combine. The beginner's program has step 20. Better Assessors are therefore items to find, buy or craft, with the usual rarity and price.

**The service.** `App\Cyberspace\Assessor`, pure of the screen. Inputs: the character, a `SystemScan` (what the player knows, not the `System` row), the planned or live run. Output: `?float` per figure,
`null` meaning `[???]`. Reads facts through `ScanFact::isKnownOn($scan)`, so what counts as known is already defined in one place.

- **Per-action figures**: one method per roll above, each: `null` unless its facts are known, else `CheckRoller::chance(...)` rounded by the program's `assess_step`.
- **Overall**: a small **pure model of a run** (not `take()`, which writes to the database), run many times with a seeded die: crack the core, take IDS detection, advance trace, ICE strikes and the
  player's attack turns, extract, egress; count runs that end `Completed`. The model calls the same chance functions as the roll. **Seeded from the character, address and scan**, so reloading the page
  gives the same number instead of flickering. ~500 runs.
- **Assumed policy**: the player does the obvious thing (break ICE if present, then the purpose, then egress). It does not model clever play or the player's escape thresholds, and the doc on screen says "rough".
- **Loadout counts.** The setup screen's chosen programs (an Evasion program raising stealth, an icebreaker's damage) go into the character's modifiers for the estimate, so the number moves as the player picks.

**Where it shows.**
- `/run/setup`: one line with the overall, placed with the other pre-run facts (near "What you know" / loadout). `[???]` plus the reason ("no IDS reading") when unknown.
- The run screen: a small figure on each action button that is a roll (egress, crack, plus any found in step 1). Same call as the button already uses for egress.

## `[???]` rules

| Figure | Needs | Shows `[???]` when |
| --- | --- | --- |
| Core crack | OS | OS not read |
| Detection | IDS | IDS not read (IDS read as none = 100% unnoticed, shown as such) |
| ICE strike | ICE | ICE not read |
| Egress | grade, ICE | grade unknown, or ICE not read on a system that has some |
| Trace | tracer, grade | tracer not read |
| Overall | all of the above that the system has | any of them is `[???]` |

A fact the scan reads as "none here" is known, not unknown.

## Build order

1. Read `Simulator` and list every roll; settle which actions show a figure.
2. `Assessor` with the per-action figures and the turns-to-break line, unit-tested against the same functions the roll uses (a test that the shown chance equals `CheckRoller::chance()` for the roll's own inputs).
3. The pure run model and the overall, with a fixed-seed test and a test that it is stable across calls.
4. The `assess_step` column on `programs` (migration, comment, item editor field).
5. UI: setup line, then action figures. Strings are the user's (`config/strings.php`).
6. Docs: CLAUDE.md pointer, `docs/encounters-drugs-cyberspace.md`, `docs/MECHANICS.md` row.

## Overall chance without an icebreaker running

With no icebreaker running, an awake ICE can never be broken, so the run only completes if nothing wakes it. Wakes happen when a watcher notices the player: `touchTheCore` (touching the core)
and `idsWatchesThePull` (pulling the file) each roll `stealth` against every watcher in `spottedBy` (IDS, tracer, the ICE's own detection). Let **p** be the chance of passing all of them.

- **p ≥ X: assume nothing wakes.** Analyse the run as if the system had no ICE (crack the core, extract, egress). The ICE's strike and attack facts are then not used, but its detection is one of the watchers in p, so an unread ICE still gives `[???]`.
- **p < X: the player will probably wake it and cannot beat it.** Overall = p x the no-ICE chance, since the run only completes on the runs where nothing noticed it.
- **X = 90%.** Reasoning: each watcher passes at 90% when the player's stealth is about 17.6 above its detection (spread 8), a clear lead and a level of safety players can plan around. Treating p = 90% as 100%
  overstates the overall by at most 10 points, which is one step at best and invisible at step 20. Lower (80%) overstates by up to 20 points, a full step-20 bucket. Higher (95%) rarely triggers
  for new characters. A starting character (stealth about 9) against a grade 1 IDS (10) has p near 47%, so the early game is almost always the multiplied case. `X` lives in `config/assessor.php`.
- Needs: IDS, tracer and ICE detection facts for p. Any of them unread (when that watcher exists) is `[???]`; a watcher read as absent is known.

With an icebreaker running the full model applies (ICE strikes, attack turns, the lot) and X is not used.

## Built

- `programs.assess_step` (nullable, migration `2026_10_29_100000_assess_step`), authored in the item editor (Utility only, multiples of 5 up to 50). No item is seeded: its name, text, rarity and price are the author's.
- `ProgramRunner` refuses a second Assessor (`ProcessRefusal::OneAssessor`). `Assessor::step()` is the running one's step, or null.
- `App\Cyberspace\Assessor` and `config/assessor.php` (X = 0.90, 400 seeded runs, 40 turns, 1 assumed pull). It calls new public `Simulator` helpers (`osChanceFor`, `egressChanceFor`, `egressDifficultyFor`, `unnoticedChance`); the roll's own code goes through the same ones.
- **Setup**: the overall for the loadout as ticked. On every tick the page asks `GET /run/assess` and shows the answer; the server counts the ticked stash rows as running (in memory only, `Character::$assumedRunning`, read by `RunningPrograms`), so stacking, the pools and every modifier go through the real sources. Nothing is started or saved. `ui-kit.js` does no arithmetic.
- **Run screen**: one line under the buttons. A purpose action shows its chance this turn (unnoticed x core opens); while an ICE fights it shows the ICE's strike chance and the turns to break it. Egress and the core already show their exact figures and are left alone.
- Player text is drafted in `config/strings.php` (`assessor.*`, `programs.spec.assesses`, `programs.spec.one_assessor`, `programs.refused.one_assessor`, `admin.items.fields.assess_step`); `[???]` and `:chance%` are final.

## Limits of the estimate

- The ICE's damage is its raw attack: resistances are not applied, so the estimate is a little pessimistic.
- The trace advances every turn once detected (the hop-by-hop roll is not modelled), and the run's own escape thresholds are not played.
- A file's size is not in a scan, so pulls are a flat assumption.
- Ordinary runs only: Fortress and Denial runs, and fortress ICE overrides, are not modelled.
- ICE is identified by level (`Ice::where('level')`); a level with no row reads as unknown.

## Open questions

None left.
