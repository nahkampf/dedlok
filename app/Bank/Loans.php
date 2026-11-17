<?php

declare(strict_types=1);

namespace App\Bank;

use App\Models\BankAccount;
use App\Models\Character;
use App\Models\Entity;
use App\Models\EntityLedgerEntry;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Safehouses\Renter;
use App\Safehouses\SafehouseStorage;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Kronos Capital's loans. docs/BANK.md.
 *
 * THE ONLY WRITER of `loans` and `loan_payments`. All sums are whole creds, and every rounding is in the bank's favour:
 * the instalment and the nightly interest are rounded UP. Each payment pays accrued interest first, then principal, and
 * the interest part (the payoff fee counts) is what earns standing with Kronos.
 */
final class Loans
{
    /** @var \Closure(int, int): int */
    private \Closure $roll;

    /** @param  \Closure(int, int): int|null  $roll  the die, injected so a test can pin it */
    public function __construct(?\Closure $roll = null)
    {
        $this->roll = $roll ?? static fn (int $min, int $max): int => random_int($min, $max);
    }

    public function kronos(): ?Entity
    {
        return Entity::query()->where('name', (string) Config::get('bank.entity'))->first();
    }

    /** The loan they still owe on, active or in default. */
    public function open(Character $character): ?Loan
    {
        return Loan::query()->open()->where('character_id', $character->getKey())->first();
    }

    /** What standing with Kronos lets them borrow. */
    public function limit(Character $character): int
    {
        $entity = $this->kronos();
        $standing = $entity === null ? 0.0 : (new StandingLedger)->standingFor($character, $entity);

        if ($standing < 0) {
            return 0;
        }

        /** @var list<array{int, int}> $table */
        $table = Config::get('bank.limit');

        for ($i = 1, $n = count($table); $i < $n; $i++) {
            [$x0, $y0] = $table[$i - 1];
            [$x1, $y1] = $table[$i];

            if ($standing <= $x1) {
                return (int) floor($y0 + ($standing - $x0) / ($x1 - $x0) * ($y1 - $y0));
            }
        }

        return (int) end($table)[1];
    }

    /** Standing with Kronos, below which there is no loan. */
    private function inGoodStanding(Character $character): bool
    {
        return $this->limit($character) > 0;
    }

    /** The fixed instalment on a loan of this size, rounded up. */
    public function instalment(int $borrowed): int
    {
        return (int) ceil($borrowed * (float) Config::get('bank.payment_percent') / 100);
    }

    /** One night's interest on this principal, rounded up. */
    public function nightlyInterest(int $principal): int
    {
        return (int) ceil($principal * (float) Config::get('bank.interest_daily'));
    }

    /** The early-payoff fee on a loan of this size, rounded up. */
    public function payoffFee(int $borrowed): int
    {
        return (int) ceil($borrowed * (float) Config::get('bank.payoff_fee_percent') / 100);
    }

    /** @return Loan|LoanRefusal the loan, paid out into the wallet */
    public function take(Character $character, int $amount): Loan|LoanRefusal
    {
        if ($amount < (int) Config::get('bank.min_loan')) {
            return LoanRefusal::TooSmall;
        }

        return DB::transaction(function () use ($character, $amount): Loan|LoanRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();

            if ($this->open($locked) !== null) {
                return LoanRefusal::HaveLoan;
            }

            if (! $this->inGoodStanding($locked)) {
                return LoanRefusal::BadStanding;
            }

            if ($amount > $this->limit($locked)) {
                return LoanRefusal::OverLimit;
            }

            $loan = Loan::query()->create([
                'character_id' => $locked->getKey(),
                'borrowed' => $amount,
                'principal' => $amount,
                'interest' => 0,
                'payment' => $this->instalment($amount),
                'state' => LoanState::Active,
                'next_due_at' => now()->addHours((int) Config::get('bank.payment_hours')),
            ]);

            Wallet::move($locked, $amount, Reason::LoanPayout, Text::get('bank.notes.loan_payout'));
            $character->forceFill(['creds' => $locked->creds]);

            return $loan;
        });
    }

    /** The instalment, by hand: only when one is due or missed. Ahead of schedule is the early payoff. */
    public function payInstalment(Character $character, PaymentSource $source): Loan|LoanRefusal
    {
        return DB::transaction(function () use ($character, $source): Loan|LoanRefusal {
            [$locked, $loan] = $this->lock($character);

            if ($loan === null) {
                return LoanRefusal::NoLoan;
            }

            if ($loan->state !== LoanState::Active || $loan->settlement !== null || ($loan->missed_at === null && ($loan->next_due_at === null || $loan->next_due_at->isFuture()))) {
                return LoanRefusal::NotDue;
            }

            if (! $this->pay($locked, $loan, $loan->due(), 0, $source)) {
                return LoanRefusal::CannotPay;
            }

            $character->forceFill(['creds' => $locked->creds]);
            $this->advance($loan);

            return $loan->fresh();
        });
    }

    /**
     * Clear the whole loan: the principal, the interest so far and, unless it is in default, the early fee.
     */
    public function payoff(Character $character, PaymentSource $source): Loan|LoanRefusal
    {
        return DB::transaction(function () use ($character, $source): Loan|LoanRefusal {
            [$locked, $loan] = $this->lock($character);

            if ($loan === null) {
                return LoanRefusal::NoLoan;
            }

            $fee = $loan->state === LoanState::Active && $loan->settlement === null ? $this->payoffFee($loan->borrowed) : 0;

            if (! $this->pay($locked, $loan, $loan->owed() + $fee, $fee, $source)) {
                return LoanRefusal::CannotPay;
            }

            $character->forceFill(['creds' => $locked->creds]);

            return $loan->fresh();
        });
    }

    /**
     * Every night: interest on every open loan, and standing for the balance held. Whether or not anyone logs in.
     *
     * @return int loans charged
     */
    public function nightly(): int
    {
        $charged = 0;

        Loan::query()->open()->orderBy('id')->each(function (Loan $loan) use (&$charged): void {
            DB::transaction(function () use ($loan, &$charged): void {
                $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->state === LoanState::Repaid) {
                    return;
                }

                $locked->forceFill(['interest' => $locked->interest + $this->nightlyInterest($locked->principal)])->save();
                $charged++;
            });
        });

        $this->rewardBalances();
        $this->repinDefaults();
        $this->rollSeizures();

        return $charged;
    }

    /**
     * The 15-minute sweep: collect instalments that fell due (all or nothing, from the wallet), start the grace
     * clock on one that cannot be, and default the loan when the grace has run out.
     *
     * @return int loans touched
     */
    public function sweep(): int
    {
        $touched = 0;

        Loan::query()->where('state', LoanState::Active->value)->whereNull('settlement')->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', now())->orderBy('id')->each(function (Loan $loan) use (&$touched): void {
                DB::transaction(function () use ($loan, &$touched): void {
                    $character = Character::query()->whereKey($loan->character_id)->lockForUpdate()->first();
                    $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->first();

                    if ($character === null || $locked === null || $locked->state !== LoanState::Active || $locked->settlement !== null) {
                        return;
                    }

                    while ($locked->state === LoanState::Active && $locked->next_due_at !== null && $locked->next_due_at->lte(now())) {
                        if (! $this->pay($character, $locked, $locked->due(), 0, PaymentSource::Wallet)) {
                            break;
                        }

                        $this->advance($locked);
                    }

                    if ($locked->state === LoanState::Active && $locked->next_due_at !== null && $locked->next_due_at->lte(now())) {
                        if ($locked->missed_at === null) {
                            $locked->forceFill(['missed_at' => $locked->next_due_at])->save();
                            (new Notifier)->send($character, Category::Wallet, 'loan_missed', ['due' => number_format($locked->due())]);
                        }

                        if ($locked->missed_at->copy()->addHours((int) Config::get('bank.grace_hours'))->lte(now())) {
                            $this->default($character, $locked);
                        }
                    }

                    $touched++;
                });
            });

        return $touched;
    }

    /** The default call, if one is waiting to be answered: in default, no settlement and no refusal. */
    public function pendingCall(Character $character): ?Loan
    {
        return Loan::query()->where('character_id', $character->getKey())->where('state', LoanState::Defaulted->value)
            ->whereNull('settlement')->whereNull('refused_at')->first();
    }

    /** Agree to a settlement: from now a share of every wallet inflow pays the loan, and the freeze lifts. */
    public function settle(Character $character): Loan|LoanRefusal
    {
        return DB::transaction(function () use ($character): Loan|LoanRefusal {
            [, $loan] = $this->lock($character);

            if ($loan === null || $loan->state !== LoanState::Defaulted || $loan->settlement !== null) {
                return LoanRefusal::NoLoan;
            }

            $loan->forceFill(['settlement' => (int) Config::get('bank.settlement_share')])->save();

            return $loan;
        });
    }

    /** Refuse: the account stays frozen, a bounty is put on them, and the seizure nights begin. */
    public function refuse(Character $character): Loan|LoanRefusal
    {
        return DB::transaction(function () use ($character): Loan|LoanRefusal {
            [$locked, $loan] = $this->lock($character);

            if ($loan === null || $loan->state !== LoanState::Defaulted || $loan->refused_at !== null) {
                return LoanRefusal::NoLoan;
            }

            $loan->forceFill(['refused_at' => now()])->save();
            // `characters.bounty` is a plain sum of creds that nothing collects yet (docs/COMBAT.md, PvP).
            $locked->forceFill(['bounty' => (int) $locked->bounty + (int) ceil($loan->owed() * (float) Config::get('bank.bounty_percent') / 100)])->save();
            $character->forceFill(['bounty' => $locked->bounty]);

            return $loan;
        });
    }

    private static bool $skimming = false;

    /**
     * Called from `Wallet::move()` after a credit: while a settlement runs, take its share of the credit back out and
     * pay the loan with it. Never more than is owed, and never more than the wallet holds.
     */
    public function skim(Character $character, int $credited): void
    {
        if (self::$skimming) {
            return;
        }

        $loan = Loan::query()->open()->where('character_id', $character->getKey())->whereNotNull('settlement')->first();

        if ($loan === null) {
            return;
        }

        $take = min((int) floor($credited * $loan->settlement / 100), $loan->owed(), (int) $character->creds);

        if ($take < 1) {
            return;
        }

        self::$skimming = true;

        try {
            Wallet::move($character, -$take, Reason::LoanSkim, Text::get('bank.notes.loan_skim'));
            $this->pay($character, $loan, $take, 0, PaymentSource::Skim);
        } finally {
            self::$skimming = false;
        }
    }

    /** The grace ran out: the loan defaults, standing with Kronos is pinned, and the account freezes. */
    public function default(Character $character, Loan $loan): void
    {
        $loan->forceFill(['state' => LoanState::Defaulted, 'defaulted_at' => now(), 'missed_at' => null, 'next_due_at' => null])->save();
        $this->setStanding($character, StandingReason::LoanDefault, (float) Config::get('bank.standing.default'), onlyLower: true);
        (new Notifier)->send($character, Category::Wallet, 'loan_defaulted', ['owed' => number_format($loan->owed())]);
    }

    /** Standing is a book of changes: set it by writing the difference. Only lowering, when asked. */
    public function setStanding(Character $character, StandingReason $reason, float $target, bool $onlyLower = false): void
    {
        $entity = $this->kronos();

        if ($entity === null) {
            return;
        }

        $ledger = new StandingLedger;
        $current = $ledger->standingFor($character, $entity);

        if ($onlyLower && $current <= $target) {
            return;
        }

        $ledger->record($character, $entity, $reason, round($target - $current, 3));
    }

    /** Each night a refused loan rolls for seizure: 10% the first night, five points more each night after. */
    private function rollSeizures(): void
    {
        Loan::query()->where('state', LoanState::Defaulted->value)->whereNotNull('refused_at')->whereNull('settlement')->orderBy('id')->each(function (Loan $loan): void {
            DB::transaction(function () use ($loan): void {
                $character = Character::query()->whereKey($loan->character_id)->lockForUpdate()->first();
                $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->first();

                if ($character === null || $locked === null || $locked->state !== LoanState::Defaulted || $locked->settlement !== null) {
                    return;
                }

                $chance = min(100, (int) Config::get('bank.seizure.start') + (int) Config::get('bank.seizure.step') * $locked->seizure_nights);
                $locked->forceFill(['seizure_nights' => $locked->seizure_nights + 1])->save();

                if (($this->roll)(1, 100) <= $chance) {
                    $this->seize($character, $locked);
                }
            });
        });
    }

    /**
     * Take what the debt is worth, in this order: the bank account, the safehouse (8 days of its rent; the box is
     * exempt), then the items stored there (75% of base cost, only as many units as the debt needs). Stops once it
     * is covered; what overshoots goes into the account. Insurance is deliberately not asked: this is not a loss.
     */
    public function seize(Character $character, Loan $loan): void
    {
        $bank = new Bank;
        $storage = app(SafehouseStorage::class);
        $renter = app(Renter::class);
        $took = ['account' => 0, 'home' => null, 'items' => 0];
        $surplus = 0;

        $balance = $bank->balance($character);
        $fromAccount = min($balance, $loan->owed());

        if ($fromAccount > 0 && $bank->spend($character, $fromAccount, EntryKind::Seizure)) {
            $this->pay($character, $loan, $fromAccount, 0, PaymentSource::Seizure);
            $took['account'] = $fromAccount;
        }

        $home = $character->home();
        $homeRows = fn () => $storage->rows($character, $home)->whereNull('storage_expires_at')->with('item')->orderBy('id');

        if ($loan->owed() > 0 && ! $renter->isFallback($home)) {
            $value = (int) floor($home->rent * (int) Config::get('bank.seizure.rent_days') * 24 / max(1, (int) Config::get('safehouse.rent_hours')));
            $applied = min($value, $loan->owed());

            $renter->seize($character, $home);
            $took['home'] = $home->title;

            if ($applied > 0) {
                $this->pay($character, $loan, $applied, 0, PaymentSource::Seizure);
            }

            $surplus += $value - $applied;
        }

        if ($loan->owed() > 0) {
            foreach ($homeRows()->lockForUpdate()->get() as $row) {
                $unit = (int) floor(((int) ($row->item->base_cost ?? 0)) * (float) Config::get('bank.seizure.items_percent') / 100);

                if ($unit < 1) {
                    continue;
                }

                $units = min($row->quantity, (int) ceil($loan->owed() / $unit));
                $worth = $units * $unit;
                $applied = min($worth, $loan->owed());

                $storage->seizeUnits($row, $units);
                $this->pay($character, $loan, $applied, 0, PaymentSource::Seizure);
                $surplus += $worth - $applied;
                $took['items'] += $units;

                if ($loan->state === LoanState::Repaid) {
                    break;
                }
            }
        }

        if ($took['home'] !== null) {
            $storage->putAway($character, $home);
        }

        if ($surplus > 0) {
            $bank->credit($character, $surplus, EntryKind::Surplus);
        }

        (new Notifier)->send($character, Category::Wallet, 'loan_seized', [
            'account' => number_format($took['account']), 'home' => $took['home'] ?? '', 'items' => $took['items'], 'owed' => number_format($loan->owed()),
        ]);
    }

    /** Nothing in the account is lost to a pin that drifted: every defaulted loan is put back to -5 or lower. */
    private function repinDefaults(): void
    {
        Loan::query()->where('state', LoanState::Defaulted->value)->orderBy('id')->each(function (Loan $loan): void {
            $character = Character::query()->find($loan->character_id);

            if ($character !== null) {
                $this->setStanding($character, StandingReason::LoanDefault, (float) Config::get('bank.standing.default'), onlyLower: true);
            }
        });
    }

    /** Standing for what is still in the account at the nightly pass, so deposit-and-withdraw earns nothing. */
    private function rewardBalances(): void
    {
        $entity = $this->kronos();

        if ($entity === null) {
            return;
        }

        $rate = (float) Config::get('bank.standing.balance.per_thousand');

        BankAccount::query()->where('balance', '>', 0)->orderBy('id')->each(function ($account) use ($entity, $rate): void {
            $character = Character::query()->find($account->character_id);

            if ($character !== null) {
                $this->grant($character, $entity, StandingReason::BankBalance, $account->balance / 1000 * $rate, (float) Config::get('bank.standing.balance.cap'));
            }
        });
    }

    /** Standing, but no more than the cap for one reason in one day. */
    private function grant(Character $character, Entity $entity, StandingReason $reason, float $wanted, float $cap): void
    {
        $today = EntityLedgerEntry::query()->where('character_id', $character->getKey())->where('entity_id', $entity->getKey())
            ->where('reason', $reason->value)->where('happened_at', '>=', CarbonImmutable::now()->startOfDay())->sum('delta');

        $amount = round(min($wanted, $cap - (float) $today), 3);

        if ($amount > 0) {
            (new StandingLedger)->record($character, $entity, $reason, $amount);
        }
    }

    /** @return array{0: Character, 1: Loan|null} */
    private function lock(Character $character): array
    {
        $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
        $loan = Loan::query()->open()->where('character_id', $locked->getKey())->lockForUpdate()->first();

        return [$locked, $loan];
    }

    /** After an instalment: the next one falls due a period after the one just paid, and no instalment is missed. */
    private function advance(Loan $loan): void
    {
        if ($loan->state === LoanState::Repaid) {
            return;
        }

        $loan->forceFill(['missed_at' => null, 'next_due_at' => $loan->next_due_at->copy()->addHours((int) Config::get('bank.payment_hours'))])->save();
    }

    /**
     * Pay `$amount` of a loan from the account or the wallet: interest first, then principal. False when the source
     * cannot cover it. Writes the payment, the standing for the interest part, and clears the loan when it is whole.
     * The caller owns the transaction and holds the locks.
     */
    public function pay(Character $character, Loan $loan, int $amount, int $fee, PaymentSource $source): bool
    {
        $amount = min($amount, $loan->owed() + $fee);

        if ($source === PaymentSource::Account) {
            if (! (new Bank)->spend($character, $amount, EntryKind::LoanPayment)) {
                return false;
            }
        } elseif ($source === PaymentSource::Wallet) {
            if ((int) $character->creds < $amount) {
                return false;
            }

            Wallet::move($character, -$amount, Reason::LoanPayment, Text::get('bank.notes.loan_payment'));
        }

        $toLoan = $amount - $fee;
        $interest = min($toLoan, $loan->interest);
        $principal = $toLoan - $interest;

        LoanPayment::query()->create(['loan_id' => $loan->getKey(), 'interest' => $interest, 'principal' => $principal, 'fee' => $fee, 'source' => $source]);
        $loan->forceFill(['interest' => $loan->interest - $interest, 'principal' => $loan->principal - $principal])->save();

        $entity = $this->kronos();

        if ($entity !== null && $interest + $fee > 0) {
            $this->grant($character, $entity, StandingReason::LoanInterest, ($interest + $fee) * (float) Config::get('bank.standing.interest.per_credit'), (float) Config::get('bank.standing.interest.cap'));
        }

        if ($loan->owed() === 0) {
            $this->clear($character, $loan);
        }

        return true;
    }

    private function clear(Character $character, Loan $loan): void
    {
        $wasDefault = $loan->state === LoanState::Defaulted;

        $loan->forceFill(['state' => LoanState::Repaid, 'repaid_at' => now(), 'next_due_at' => null, 'missed_at' => null, 'settlement' => null])->save();

        if ($wasDefault) {
            $this->setStanding($character, StandingReason::LoanCleared, 0.0);
        }
    }
}
