<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Missions\MissionLedger;
use App\Missions\MissionRefusal;
use App\Models\Character;
use App\Models\Mission;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Taking work, turning it down, giving it up and handing it in.
 *
 * FOUR DOORS AND ONE WRITER. Everything here is the HTTP-shaped half of
 * `App\Missions\MissionLedger`, which is where the rules are. Each action
 * checks ownership for the 404 and then lets the ledger check it again — the
 * same division every other owned row on the site keeps.
 *
 * There is no index: a board belongs to a DESK, and a desk is reached by
 * standing on it. `ExchangeController` is the only screen that lists work.
 */
class MissionController extends Controller
{
    public function accept(Request $request, Mission $mission, MissionLedger $ledger): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($mission->character_id === $character->id, 404);

        $result = $ledger->accept($character, $mission);

        if ($result instanceof MissionRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('missions.accepted', [
            'time' => $result->expires_at?->diffForHumans(['syntax' => 2]) ?? '',
        ]));
    }

    /**
     * Turn it down.
     *
     * The job is gone until the board turns over, which is what makes the hour
     * a decision rather than a formality. Nothing confirms it: what it costs
     * is an offer, and the board says when the next one comes.
     */
    public function decline(Request $request, Mission $mission, MissionLedger $ledger): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($mission->character_id === $character->id, 404);

        $result = $ledger->decline($character, $mission);

        if ($result instanceof MissionRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('missions.declined'));
    }

    /**
     * Give up work already taken.
     *
     * SAID AS AN ERROR even though it did what was asked: it costs standing,
     * and a cost is not something to find out about by noticing a number moved
     * on another screen.
     */
    public function abandon(Request $request, Mission $mission, MissionLedger $ledger): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($mission->character_id === $character->id, 404);

        $result = $ledger->abandon($character, $mission);

        if ($result instanceof MissionRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('error', Text::get('missions.abandoned'));
    }

    public function complete(Request $request, Mission $mission, MissionLedger $ledger): RedirectResponse
    {
        $character = $this->character($request);

        abort_unless($mission->character_id === $character->id, 404);

        $result = $ledger->complete($character, $mission);

        if ($result instanceof MissionRefusal) {
            return back()->with('error', $result->message());
        }

        return back()->with('status', Text::get('missions.completed', [
            'creds' => number_format((int) $result->creds_reward),
        ]));
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
