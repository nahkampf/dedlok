<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\EntityBook;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A character as another player sees them: handle, picture, bio, the two tags and where they stand with whom.
 *
 * Public to every signed-in character and nothing but what is shown here — no attributes, no creds, no whereabouts.
 * The standing list is the same one the owner's own tab draws, minus the ledger that says why.
 */
class ProfileController extends Controller
{
    public function show(Request $request, Character $character): View
    {
        $character->loadMissing('user');

        return view('profile.show', [
            'profile' => $character,
            'isMe' => $request->user()->character?->is($character) ?? false,
            'books' => EntityBook::query()
                ->with('entity')
                ->where('character_id', $character->getKey())
                ->where('standing', '!=', 0)
                ->get()
                ->filter(fn (EntityBook $book): bool => $book->entity !== null && $book->entity->interactable)
                ->sortByDesc(fn (EntityBook $book): float => abs($book->standing))
                ->values(),
        ]);
    }
}
