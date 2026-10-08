<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Text;
use App\Supporters\Supporters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('admin.supporters.index', ['users' => $users, 'q' => $q]);
    }

    public function update(Request $request, User $user, Supporters $supporters): RedirectResponse
    {
        $on = $request->boolean('supporter');
        $on ? $supporters->grant($user) : $supporters->revoke($user);

        return back()->with('status', Text::get($on ? 'admin.supporters.granted' : 'admin.supporters.revoked', ['who' => $user->email]));
    }
}
