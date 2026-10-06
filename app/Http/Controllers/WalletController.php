<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WalletEntry;
use App\Support\Text;
use App\Wallet\TransferRefusal;
use App\Wallet\Transfers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The wallet: every movement of creds, and sending some to another character. */
class WalletController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $character = $request->user()->character()->firstOrFail();

        return view('wallet.index', [
            'character' => $character,
            'entries' => WalletEntry::query()
                ->where('character_id', $character->getKey())
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE),
        ]);
    }

    public function transfer(Request $request, Transfers $transfers): RedirectResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $result = $transfers->send(
            $request->user()->character()->firstOrFail(),
            $data['handle'],
            (int) $data['amount'],
        );

        if ($result instanceof TransferRefusal) {
            return back()->withInput()->withErrors(['transfer' => $result->message()]);
        }

        return back()->with('status', Text::get('wallet.sent', [
            'amount' => number_format((int) $data['amount']),
            'handle' => $result->handle,
        ]));
    }
}
