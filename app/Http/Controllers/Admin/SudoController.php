<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\User;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Play as somebody else, for debugging. An admin starts it (`can:admin`) and the session remembers who they were; Exit sudo
 * is outside the admin gate on purpose, because the account being played is not an admin, and it only works while that
 * memory is there. Nothing is written to the account being played by starting or ending this.
 */
class SudoController extends Controller
{
    public const SESSION_KEY = 'sudo_from';

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['handle' => ['required', 'string', 'max:50']]);
        $target = Character::query()->where('handle', trim($data['handle']))->with('user')->first();
        $admin = $request->user();

        if ($target?->user === null || $target->user->is($admin)) {
            return back()->withInput()->with('error', Text::get('admin.sudo.no_such'));
        }

        $this->switchTo($request, $target->user, $request->session()->get(self::SESSION_KEY, $admin->getKey()));

        return redirect('/');
    }

    public function exit(Request $request): RedirectResponse
    {
        $admin = User::query()->find($request->session()->get(self::SESSION_KEY));

        // Only while a sudo is in progress, and only back to an account that is still an admin.
        abort_unless($admin?->isAdmin() === true, 403);

        $this->switchTo($request, $admin, null);

        return redirect()->route('admin.index');
    }

    private function switchTo(Request $request, User $user, ?int $from): void
    {
        Auth::login($user);
        $request->session()->regenerate();

        if ($from === null) {
            $request->session()->forget(self::SESSION_KEY);
        } else {
            $request->session()->put(self::SESSION_KEY, $from);
        }
    }
}
