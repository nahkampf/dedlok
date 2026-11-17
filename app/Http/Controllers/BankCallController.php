<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Bank\LoanRefusal;
use App\Bank\Loans;
use App\Bank\PaymentSource;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The incoming vidcall from Kronos Capital when a loan has defaulted: pay in full, agree a settlement, or refuse. */
class BankCallController extends Controller
{
    public function show(Request $request, Loans $loans): View|RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        $loan = $loans->pendingCall($character);

        return $loan === null
            ? redirect()->route('city.index')
            : view('bank.call', [...$this->shopContext($character), 'character' => $character, 'loan' => $loan, 'share' => (int) config('bank.settlement_share')]);
    }

    public function answer(Request $request, string $choice, Loans $loans): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();

        if ($loans->pendingCall($character) === null) {
            return redirect()->route('city.index');
        }

        $result = match ($choice) {
            'pay' => $loans->payoff($character, PaymentSource::Wallet),
            'settle' => $loans->settle($character),
            'refuse' => $loans->refuse($character),
            default => LoanRefusal::NoLoan,
        };

        return $result instanceof LoanRefusal
            ? back()->with('error', $result->message())
            : redirect()->route('city.index')->with('status', Text::get("bank.call.{$choice}_done"));
    }
}
