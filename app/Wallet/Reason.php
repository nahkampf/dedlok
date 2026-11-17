<?php

declare(strict_types=1);

namespace App\Wallet;

use App\Support\Text;

/** What kind of movement a wallet entry is. A new way for creds to move is a case and a label. */
enum Reason: string
{
    case Starting = 'starting';
    case Market = 'market';
    case PlayerMarket = 'player_market';
    case Mission = 'mission';
    case Siphon = 'siphon';
    case Extraction = 'extraction';
    case Hunt = 'hunt';
    case Skim = 'skim';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Pod = 'pod';
    case Shop = 'shop';
    case Sale = 'sale';
    case Clinic = 'clinic';
    case Insurance = 'insurance';
    case Surgery = 'surgery';
    case Encounter = 'encounter';
    case Fight = 'fight';
    case Mugged = 'mugged';
    case Rent = 'rent';
    case Meal = 'meal';
    case Inquiry = 'inquiry';
    case Granted = 'granted';
    case AlphaGift = 'alpha_gift';
    case FortressMarket = 'fortress_market';
    case FortressSale = 'fortress_sale';
    case FortressWallet = 'fortress_wallet';
    case BankDeposit = 'bank_deposit';
    case BankWithdrawal = 'bank_withdrawal';
    case LoanPayout = 'loan_payout';
    case LoanPayment = 'loan_payment';
    case LoanSkim = 'loan_skim';

    /**
     * Whether a settlement takes its share of an inflow of this kind. A `match` with no default on purpose: a new
     * Reason is a decision, and an unhandled one throws in the test that walks every case.
     */
    public function settlesLoans(): bool
    {
        return match ($this) {
            self::BankWithdrawal, self::LoanPayout, self::LoanPayment, self::LoanSkim, self::Starting => false,
            self::Market, self::PlayerMarket, self::Mission, self::Siphon, self::Extraction, self::Hunt, self::Skim,
            self::TransferOut, self::TransferIn, self::Pod, self::Shop, self::Sale, self::Clinic, self::Insurance,
            self::Surgery, self::Encounter, self::Fight, self::Mugged, self::Rent, self::Meal, self::Inquiry,
            self::Granted, self::AlphaGift, self::FortressMarket, self::FortressSale, self::FortressWallet,
            self::BankDeposit => true,
        };
    }

    public function label(): string
    {
        return Text::get("wallet.reasons.{$this->value}");
    }
}
