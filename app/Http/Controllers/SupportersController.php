<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BetaKey;
use App\Models\User;
use Illuminate\View\View;

/**
 * The Supporters page: everyone who supports the game, by the name they gave for the credits (`users.credit_name`) or, failing that,
 * their character's handle, and below it the testers (accounts that spent a beta key), named the same way. Public, like the front page. An account with neither is not listed: there is no name to thank.
 */
class SupportersController extends Controller
{
    public function index(): View
    {
        $names = User::query()
            ->where('is_supporter', true)
            ->where(fn ($q) => $q->whereNull('supporter_until')->orWhere('supporter_until', '>', now()))
            ->with('character:id,user_id,handle')
            ->orderBy('supporter_since')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): ?string => ($user->credit_name !== null && trim($user->credit_name) !== '') ? trim($user->credit_name) : $user->character?->handle)
            ->filter()
            ->values();

        // Testers: whoever spent a beta key (`user_id` is nulled when the account goes), named the same way.
        $testers = BetaKey::query()
            ->whereNotNull('user_id')
            ->with('user.character:id,user_id,handle')
            ->orderBy('used_at')
            ->orderBy('id')
            ->get()
            ->map(fn (BetaKey $key): ?string => ($name = trim((string) $key->user?->credit_name)) !== '' ? $name : $key->user?->character?->handle)
            ->filter()
            ->values();

        return view('supporters.index', ['names' => $names, 'testers' => $testers]);
    }
}
