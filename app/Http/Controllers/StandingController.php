<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EntityBook;
use App\Models\EntityLedgerEntry;
use App\Standing\LedgerKind;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What every entity thinks of you, and the ledger that says why.
 *
 * Only entities with a non-zero standing are listed: zero is neutral and
 * indistinguishable from never having met. The log is `entity_ledger`'s
 * standing entries — loyalty is spent at the store and has its own story.
 */
class StandingController extends Controller
{
    // ponytail: newest N and no pager, the inbox's call.
    private const SHOWN = 100;

    public function index(Request $request): View
    {
        $character = $request->user()->character()->firstOrFail();

        return view('standing.index', [
            'books' => EntityBook::query()
                ->with('entity')
                ->where('character_id', $character->getKey())
                ->where('standing', '!=', 0)
                ->get()
                ->sortByDesc(fn (EntityBook $book): float => abs($book->standing))
                ->values(),
            'entries' => EntityLedgerEntry::query()
                ->with('entity')
                ->where('character_id', $character->getKey())
                ->ofKind(LedgerKind::Standing)
                ->where('delta', '!=', 0)
                ->orderByDesc('id')
                ->limit(self::SHOWN)
                ->get(),
        ]);
    }
}
