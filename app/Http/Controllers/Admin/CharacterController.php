<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Grants: creds, and a full refill of hit points or Synaptic Integrity. Each goes through the thing that already owns the number
 * (`Wallet::move()`, `Character::recover()`), so the ledger has the line and a resource never passes its resolved maximum.
 */
class CharacterController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $characters = Character::query()
            ->with('user:id,email')
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('handle', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%"))))
            ->orderBy('handle')
            ->paginate(50)
            ->withQueryString();

        return view('admin.characters.index', ['characters' => $characters, 'q' => $q]);
    }

    public function creds(Request $request, Character $character): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:1000000']]);

        DB::transaction(function () use ($character, $data): void {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            Wallet::move($locked, (int) $data['amount'], Reason::Granted, 'Granted by the game');
        });

        return back()->with('status', Text::get('admin.characters.granted_creds', ['amount' => number_format((int) $data['amount']), 'who' => $character->handle]));
    }

    public function refill(Character $character, string $resource): RedirectResponse
    {
        $column = ['hitpoints' => 'current_hitpoints', 'si' => 'current_synaptic_integrity'][$resource] ?? abort(404);

        DB::transaction(function () use ($character, $column): void {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $locked->recover($column, PHP_INT_MAX);
        });

        return back()->with('status', Text::get("admin.characters.refilled_{$resource}", ['who' => $character->handle]));
    }
}
