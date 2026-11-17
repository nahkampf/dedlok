<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Leaderboard\Board;
use App\Leaderboard\Snapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The leaderboard: the first five of each board, and where you stand (docs/LEADERBOARD.md). Reads the snapshot only. */
class LeaderboardController extends Controller
{
    public function __invoke(Request $request, Snapshot $snapshot): View
    {
        $character = $request->user()->character()->firstOrFail();

        return view('leaderboard.index', [
            'boards' => array_map(fn (Board $board): array => [
                'board' => $board,
                'rows' => $snapshot->top($board),
                'mine' => $snapshot->rankOf($board, $character),
            ], Board::cases()),
            'writtenAt' => $snapshot->writtenAt(),
            'me' => $character,
        ]);
    }
}
