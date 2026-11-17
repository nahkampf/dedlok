# Kronos Capital (designed 2026-11-17; accounts and loans built)

A bank: somewhere to keep creds that ICE, hunters and going down cannot take, and somewhere to borrow against
your standing. Stocks are **parked**, see the end.

## Decided

- **Kronos Capital is an entity** (`entities`, finance category, `interactable`), so standing with it is the credit
  rating. It gets a line in `docs/WORLDBUILDING.md`.
- **The cyberspace node is Kronos' Exchange**: one desk, placed by `ExchangePlacer` from the config roster, permanent,
  never raided or moved. One desk, at a grade anyone can reach, because a bank that nobody can find is not a bank.
- **The City location carries a `Bank` feature** (`Feature::Bank`; a place is a feature by having rows).
- **Where each service lives:** deposit and withdraw at both; **loans in the City only** (a face at a desk).
- **Defaulting:** standing -5 and a frozen account, then a choice (pay, settle or refuse). A refusal adds a bounty and
  starts a nightly seizure roll. No collectors.

## Money

- **`bank_accounts`** (one per character, balance as `Points`-style fixed decimals like the wallet) and **`bank_entries`**,
  an immutable ledger carrying the balance after, no `updated_at`, notes composed at write time (the wallet's rules).
- **`App\Bank\Bank` is the only writer of either**, and moves cash through `Wallet::move()` with new `Reason` cases
  (deposit, withdrawal, loan paid out, loan repaid), so `characters.creds` still has exactly one writer.
- **`bank:verify`** asks whether every balance equals its ledger's sum, like `standing:verify`.
- **Locking** follows `Transfers`: lock the character and the account, in that order, inside one transaction.
- **No interest on deposits** (inflation). The sinks are loan interest and, later, trading fees.
- **The account is safe from** ICE skim, hunter losses and a Crash Jackout. Check every place that reads
  `characters.creds` to take from it; none of them may touch the account.

## Loans

- **One loan at a time.** `loans`: the amount borrowed, the principal still owed, the interest accrued and unpaid, the
  payment, next payment due, state (active, repaid, defaulted).
- **Limit from standing with Kronos**, read through the standing ledger; a new character borrows little. Numbers go in
  `config/bank.php`, all placeholders until played.
- **Interest accrues every night in housekeeping, whether or not the player ever logs in** (a scheduled job over every
  open loan, never a lazy catch-up on login). **It keeps accruing through a settlement, a grace period and a default**:
  an idle player's loan does not wait for them. Simple, on the principal still owed, at a daily rate in config.
- **A payment every 48 hours, of about 4% of the amount borrowed** (the fixed sum, not the shrinking principal), so the
  payment never changes. Taken from the **bank account** when due; **all or nothing**: if the account cannot cover the
  whole payment, that is a **missed payment** and the grace clock starts. The player may also pay by hand at any
  time, from account or wallet, and a payment made inside the grace period stops the clock.
- **Each payment pays accrued interest first, then principal.** The interest part is what earns standing (below). The
  daily rate in config must stay under the payment's daily share (2% of the amount borrowed a day), or the loan could
  never fall; a test asserts it.
- **Paying the whole loan early costs the principal still owed, the interest accrued so far, and 2% of the amount
  borrowed**. No future interest, so settling early is a real option; the fee counts as interest paid for standing.
  (Decided.)
- **Grace period: 24 hours** (`config/bank.php`, `grace_hours`), from the missed payment to the default. A payment
  missed starts the clock and sends an inbox warning (`Category::Wallet`); paying what is owed inside the 24 hours
  stops it, and nothing else changes. Only when it runs out does the loan default.
- **Default** (24 hours after a missed payment) sets the loan to defaulted and **sets standing with Kronos
  to -5, only ever lowering it**: `min(current, -5)`, never a raise. Paying the loan back after that sets it to 0. The
  account freezes (no withdrawals) until the debt is cleared or a settlement is agreed. **There are no collectors.**
- **The default notice is forced, and framed as an incoming vidcall.** It interrupts wherever the player is, and the
  screen is locked until one is picked, the way `EnsureHuntIsResolved` and `EnsureEncounterIsResolved` lock it: a
  middleware that redirects every request to the call. **It is never forced in the middle of a run or a fight**: while
  one is live the call waits, and lands on the first request after it ends (the way the hunter lock is handled, read
  `EnsureHuntIsResolved` and `EnsureFightIsResolved` for the exemptions). There is no collector and no fight; the player picks one of three:
  1. **Pay in full from the wallet**, if able; the loan clears.
  2. **Agree to a settlement:** 50% of every cred that goes into the wallet is moved to the bank to pay the loan until it
     is paid in full. Lifts the freeze. See *Settlement*.
  3. **Refuse.** The account is frozen, and **a bounty of 10% of the outstanding loan is added to `characters.bounty`**
     (the existing bounty sum; nothing collects it yet, `docs/COMBAT.md` PvP). The seizure clock then runs.
- **Seizure, by a nightly roll after a refusal.** Each night, once, the loan rolls for **asset and property seizure**:
  **10% the first night, 5 points more every night after** (15, 20, ...), a counter on the loan, capped at certain. A hit
  takes, **in this order**, the **bank account's money** (the frozen balance, at face value), then the **safehouse** (counted at **8x its daily rent**; **the cardboard box itself is exempt**) then the **items stored
  there** (counted at **75% of market value**), against the debt, stopping once it is covered. Whatever overshoots the
  debt is **deposited into the player's bank account**. Settling or paying in full ends the roll.
- **Seizing a safehouse** reuses the loss path in `docs/FATIGUE.md`: back to the fallback box (`safehouse.fallback`);
  what Kronos does not need to cover the debt goes into the usual 48-hour storage rather than being deleted.
- **Known gap:** multi-account funnelling, the same as `Transfers`. A loan taken and abandoned on a throwaway account
  costs Kronos nothing; the standing limit keeps it small, and a fresh account has no standing to borrow against.

### Settlement

- **While a settlement runs the 48-hour payments stop**, so none can be missed; the skim pays the loan. (Decided.)
- **A flag on the loan, not a new state:** `loans.settlement` (the share, from `config/bank.php`, 50). It lives until the
  balance owed is 0.
- **The skim happens in `Wallet::move()`'s caller path, not by a second writer.** `Wallet::move()` stays the only
  writer of `characters.creds`; a credit above 0 for an eligible Reason is followed by `Bank::settle()`, which takes the
  share back out through `move()` as one more ledger line (a Reason of its own) and pays the loan.
- **Which inflows count** is a list in config by `Reason`. A withdrawal from the bank, a loan payout and the skim itself
  must never count (a loop); transfers, sales, mission rewards, loot and skims count. A refund of a purchase is the
  doubtful one.
- **Never takes more than is owed**, and never takes cash the wallet does not hold.
- **Standing** stays at -5 while the loan is unpaid, the same as any other default, and goes to 0 when it is cleared.

### Standing with Kronos

There are no missions for Kronos, so standing comes from using the bank. All numbers are `config/bank.php`, placeholders.

- **From deposits: balance held overnight.** Housekeeping, once a night, pays standing as a fraction of the account's
  balance. Cycling deposit and withdraw earns nothing, because only what is still there at the nightly pass counts.
- **From repayment: interest paid.** A fraction of the interest portion of what is repaid (principal pays nothing),
  so a loan taken and cleared at once earns nothing worth having.
- **Both are capped per day** (separately, in config) and written through `StandingLedger` as ordinary entries (a `StandingReason` each).
- **Drift** takes 0.25% of standing a night toward 0 (`standing:drift`), so the gain has to outrun it or the account
  never rises. A defaulted standing of -5 drifts toward 0 on its own; it has to be pinned while the loan is open
  (open: skip Kronos in the drift while a default is live).
- **Setting standing** is a delta, because the ledger records changes and its books must equal the sum. A default
  writes `min(0, -5 - current)` (never positive); clearing the loan writes `-current` back to 0.

## Phase 1, as built

- **Tables** (`2026_11_17_100000_kronos_capital_accounts`): `bank_branches` (a location is a branch by having a row, like
  the other features), `bank_accounts` (one per character, made on the first deposit) and `bank_entries` (immutable).
  The same migration adds the Kronos Capital entity (interactable, so it keeps standing books and gets a desk) and the
  City location `kronos-capital` in Downtown. **The `bank.*` strings, the two wallet reasons and the City feature label in `config/strings.php` are still `TODO` placeholders.**
- **`App\Bank\Bank`** is the only writer: `deposit()`, `withdraw()` (each returns the new balance or a `BankRefusal`),
  `balance()`, `statement()`. Cash moves through `Wallet::move()` with `Reason::BankDeposit` / `BankWithdrawal`.
- **City:** `Feature::Bank`; `/city/place/{slug}` shows the counter (`bank._panel`) and `city.bank` acts, through
  `Presence::refusal()` like every other City writer. **Cyberspace:** Kronos' desk (`cyberspace.exchanges.grades`,
  grade 1, gate 0) renders `exchange.bank` instead of the missions page, and `exchange.bank` acts only for somebody
  standing on it. After deploying: `php artisan cyberspace:exchanges` places the desk.
- **`php artisan bank:verify`** checks each balance against its ledger.
- Not built yet: the admin editor has no bank checkbox (the one branch is authored by the migration), and nothing
  earns standing with Kronos until phase 2.

## Phase 2, as built

- **Tables** (`2026_11_17_110000_kronos_capital_loans`): `loans` (borrowed, principal, accrued interest, the fixed
  instalment, next due, missed, defaulted, settlement share, refused, seizure nights) and `loan_payments` (what each
  payment was made of: interest, principal, fee, source). **`App\Bank\Loans` is the only writer of both.**
- **Jobs:** `bank:sweep` every 15 minutes (collect due instalments from the wallet, all or nothing; start the 24-hour
  grace; default) and `bank:nightly` at 05:30, after `standing:drift` (interest, standing for held balances, the
  default pinned back to -5, and the seizure roll). `bank:verify` also checks each loan's principal against its payments.
- **Standing:** `StandingReason::BankBalance`, `LoanInterest`, `LoanDefault`, `LoanCleared`, each daily-capped
  through the ledger itself (no new table). A default writes `min(current, -5)`; clearing a defaulted loan writes back to 0.
- **The call:** `EnsureDefaultIsFaced` redirects every request to `/bank/call` while a loan is in default and unanswered, and
  stands down by itself for a run, hunt, fight, encounter, being jacked in or down. Answers: pay in full (no fee, from
  the wallet), settle (`loans.settlement`), refuse (`refused_at`, a bounty of 10% added to `characters.bounty`).
- **The skim** is inside `Wallet::move()`: every positive movement whose `Reason::settlesLoans()` is true gives the
  settlement its share (a `match` with no default, so a new Reason cannot go unclassified). The skim's own movement
  is negative, and a static guard stops any recursion.
- **Seizure** (`Loans::seize`): the account, then the safehouse (8 days of its rent; the box is exempt), then the stored
  items (75% of base cost, only as many units as the debt needs); surplus into the account; what stays stored goes to
  the 48-hour storage. It does not ask insurance. After deploying: run the migration; nothing needs placing.
- **Handled by default** (my reading; change in config or code): hand payments only when an instalment is due or missed
  (anything earlier is the early payoff, so the fee cannot be skipped); a loan's payout goes to the wallet; the
  schedule stops at default; "pay in full" on the call has no fee; loans are City-only.
- **Not built:** the admin has no loan view, and no achievements hook the bank yet.

## Build order

Each phase has migrations, so each is a minor release.

1. **Accounts:** the entity, tables, `App\Bank\Bank`, the City location and page, the Exchange desk and page, a Reason
   per movement, `bank:verify`, docs. Achievement hooks wait.
2. **Loans:** `loans`, the 48-hour payments, repay (and the early payoff), default, the default notice, the nightly seizure roll, notifications (Category::Wallet).

## Open

- The names and numbers (limit curve, rate, term, grace period).
- **The seizure roll starts after a refusal only.** It never runs while a call sits unanswered, because the screen is
  locked until it is answered.
- **What "asset" means** is settled: the bank balance first, then the safehouse, then the items stored in it.
- **A character in the cardboard box** cannot lose the box, but the bank balance and the items stored in it can be
  seized, so a refusal is not harmless for them. (Decided.)
- All player-facing text is the user's to write; the build adds the keys and leaves them empty.
- The default notice is one screen with three buttons, not an authored encounter (no collector to author).

## Parked: stocks

Companies in cyberspace as listed securities; prices from a bounded random walk plus player actions (raiding or burning
a corporation's nodes) and clues a player earns (data extracted from corporate nodes, inbox news, what a scan shows);
a fee, spread, position caps and a cooldown so it cannot print money. Largest balance risk of the three: design it
properly once the bank has been played.
