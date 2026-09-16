<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\Run;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The run, which is the only screen a player inside one can reach.
 *
 * `App\Http\Middleware\EnsureRunIsFinished` is what makes that true, and its
 * allow-list names exactly these routes — so a change here that adds a route
 * has to add it there too, or the new route redirects to itself.
 *
 * THIS PHASE IS THE SPINE AND NOT THE FIGHT. A run can be begun, looked at and
 * left. What happens in between — detection, the ICE, extraction, the endings —
 * is what the turns will be, and `act()` is deliberately the one place waiting
 * for them rather than several.
 */
class RunController extends Controller
{
    public function __construct(private readonly RunStarter $runs) {}

    /** Go in. The purpose is chosen here and never changed. */
    public function start(Request $request): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'purpose' => ['required', Rule::in(RunPurpose::values())],
        ]);

        $result = $this->runs->start($character, RunPurpose::from($data['purpose']));

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return redirect()->route('run.show');
    }

    /**
     * The run, as it stands.
     *
     * A player with no live run is sent back to the map rather than shown an
     * empty screen: the lock only holds somebody who is IN one, so arriving
     * here without one means it ended while they were looking elsewhere.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        return view('run.show', [
            'character' => $character,
            'run' => $run,
            'system' => $run->system(),
        ]);
    }

    /**
     * Spend a turn.
     *
     * NOT BUILT. The simulator is what goes here, and it is deliberately one
     * door rather than several: every action a turn can be spent on resolves
     * through the same place, so a turn always costs the same thing whatever
     * it was spent doing.
     */
    public function act(Request $request): RedirectResponse
    {
        return back()->with('error', Text::get('cyberspace.run.not_built'));
    }

    /**
     * Leave.
     *
     * ALWAYS POSSIBLE, and for now always successful — the roll against the
     * machine's grade and anything fighting you is the simulator's, and until
     * that exists a player who can get into a run has to be able to get out of
     * one. A run with no exit is a character deleted by a door they could not
     * see, which is the one outcome this must never produce.
     */
    public function egress(Request $request): RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        $this->runs->end($character, $run, RunEnding::Egress);

        return redirect()->route('cyberspace.index')
            ->with('status', RunEnding::Egress->message());
    }

    private function liveRun(Character $character): ?Run
    {
        $run = $character->currentRun()->first();

        return $run?->isLive() === true ? $run : null;
    }

    private function character(Request $request): Character
    {
        return $request->user()->character;
    }
}
