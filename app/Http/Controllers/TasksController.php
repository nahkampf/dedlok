<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Missions\MissionLedger;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tasks & Missions: what you have taken on, in one place.
 *
 * Missions are the exchange jobs you have ACCEPTED and not yet finished (failed, completed and voided ones are history, and
 * an offer you have not taken is the desk's to show). Handing a job in happens at the desk it came from; giving one up (Abandon) can be done from here. The
 * journal and the quests (tasks) are placeholders until they are designed.
 */
class TasksController extends Controller
{
    public function __invoke(Request $request, MissionLedger $ledger): View
    {
        $character = $request->user()->character()->firstOrFail();

        return view('tasks.index', [
            // Soonest to run out first: that is the order somebody works in.
            'missions' => Mission::query()
                ->where('character_id', $character->getKey())
                ->accepted()
                ->with('targets.system', 'entity', 'exchange.system')
                ->orderBy('expires_at')
                ->get(),
            // For a survey's per-system tags and a supply job's meter: what is in the stash right now.
            'files' => $ledger->heldFiles($character)->count(),
            'heldAddresses' => $ledger->heldAddresses($character),
        ]);
    }
}
