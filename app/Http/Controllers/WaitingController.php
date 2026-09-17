<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Characters\DownedCause;
use App\Characters\WaitingRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The waiting screen, and the only one a character on the floor can reach.
 *
 * `App\Http\Middleware\EnsureCharacterIsStanding` is what makes that true, and
 * it releases on the way past — so by the time this renders, the character is
 * genuinely still down. A player who arrives here standing is sent home.
 */
class WaitingController extends Controller
{
    public function __construct(private readonly WaitingRoom $room) {}

    public function show(Request $request): View|RedirectResponse
    {
        $character = $request->user()->character;

        if (! $this->room->isDown($character)) {
            return redirect()->route('home');
        }

        return view('character.waiting', [
            'character' => $character,
            'until' => $character->waiting_until,
            'cause' => DownedCause::fromArray($character->waiting_cause),
        ]);
    }
}
