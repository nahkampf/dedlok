<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Text;
use App\Supporters\Supporters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Who is a supporter, and the button that makes somebody one. `App\Supporters\Supporters` is the only writer. */
class SupporterController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('character:id,user_id,handle')
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('email', 'like', "%{$q}%")
                ->orWhere('credit_name', 'like', "%{$q}%")
                ->orWhereHas('character', fn ($c) => $c->where('handle', 'like', "%{$q}%"))))
            ->orderByDesc('is_supporter')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $unmatched = DB::table('kofi_payments')->whereNull('user_id')
            ->selectRaw('email, max(tier_name) as tier_name, count(*) as payments, max(received_at) as last_at')
            ->groupBy('email')->orderByDesc('last_at')->get();

        return view('admin.supporters.index', ['users' => $users, 'q' => $q, 'unmatched' => $unmatched]);
    }

    /** Connects the Ko-fi payments made from one email to the account the admin names. */
    public function connect(Request $request, Supporters $supporters): RedirectResponse
    {
        $data = $request->validate(['kofi_email' => ['required', 'string'], 'account' => ['required', 'string']]);
        $user = User::whereRaw('lower(email) = ?', [strtolower(trim($data['account']))])->first();

        if ($user === null) {
            return back()->withErrors(['account' => Text::get('admin.supporters.no_such_account', ['who' => $data['account']])]);
        }

        $count = $supporters->connectKofi($data['kofi_email'], $user);

        return back()->with('status', Text::get('admin.supporters.connected', ['count' => $count, 'who' => $user->email]));
    }

    public function update(Request $request, User $user, Supporters $supporters): RedirectResponse
    {
        $on = $request->boolean('supporter');
        $on ? $supporters->grant($user) : $supporters->revoke($user);

        return back()->with('status', Text::get($on ? 'admin.supporters.granted' : 'admin.supporters.revoked', ['who' => $user->email]));
    }
}
