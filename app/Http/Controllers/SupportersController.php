<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/**
 * The Supporters page: everyone who supports the game, by the name they gave for the credits (`users.credit_name`) or, failing that,
 * their character's handle. Public, like the front page. An account with neither is not listed: there is no name to thank.
 */
class SupportersController extends Controller
{
    public function index(): View
    {
        $names = User::query()
            ->where('is_supporter', true)
            ->with('character:id,user_id,handle')
            ->orderBy('supporter_since')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): ?string => ($user->credit_name !== null && trim($user->credit_name) !== '') ? trim($user->credit_name) : $user->character?->handle)
            ->filter()
            ->values();

        return view('supporters.index', ['names' => $names]);
    }
}
