<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Journal\Journal;
use App\Missions\MissionLedger;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tasks & Missions: what you have taken on, in one place.
 *
 * Missions are the exchange jobs you have ACCEPTED and not yet finished (failed, completed and voided ones are history, and
 * an offer you have not taken is the desk's to show). Handing a job in happens at the desk it came from; giving one up (Abandon) can be done from here. The
 * journal is what encounters wrote (`App\Journal\Journal`); the quests (tasks) are a placeholder until they are designed.
 */
class TasksController extends Controller
{
    public function __invoke(Request $request, MissionLedger $ledger): View
    {
        $character = $request->user()->character()->firstOrFail();

        // Opening the screen is reading it all: the rail's badge clears with this request, before the layout counts.
        app(Journal::class)->markSeen($character);

        Mission::query()->where('character_id', $character->getKey())->accepted()->get()
            ->each(fn (Mission $m) => $ledger->retarget($m));

        return view('tasks.index', [
            // Soonest to run out first: that is the order somebody works in.
            'missions' => Mission::query()
                ->where('character_id', $character->getKey())
                ->accepted()
                ->with('targets.system', 'entity', 'exchange.system')
                ->orderBy('expires_at')
                ->get(),
            // The desks worked for in the last week: one per exchange, however many jobs, and whatever became of them.
            // `accepted_at` is NULL for an offer nobody took, so an unaccepted one is never counted.
            'recent' => Mission::query()
                ->where('character_id', $character->getKey())
                ->where('accepted_at', '>=', now()->subDays(7))
                ->with('exchange.system')
                ->get()
                ->pluck('exchange')
                ->filter()
                ->unique('id')
                ->sortBy(fn ($exchange) => $exchange->system?->name)
                ->values(),
            // Notes not yet finished, newest first, with their lines.
            'journal' => app(Journal::class)->visible($character),
            // For a survey's per-system tags and a supply job's meter: what is in the stash right now.
            'files' => $ledger->suppliable($character),
            'heldAddresses' => $ledger->heldAddresses($character),
        ]);
    }
}
