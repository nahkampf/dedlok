<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Run;
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
            // Worked out here rather than in the template: it is the number the
            // egress decision is made on, and a player should not have to do
            // the division while something is hitting them.
            'turnsLeft' => $run->turnsUntilTraced(),
        ]);
    }

    /**
     * Spend a turn.
     *
     * ONE DOOR FOR EVERY ACTION, so a turn always costs the same thing whatever
     * it was spent doing — which is the economy the whole run rests on.
     */
    public function act(Request $request, Simulator $simulator): RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(RunAction::values())],
        ]);

        $result = $simulator->take($character, $run, RunAction::from($data['action']));

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        if (! $result->ended()) {
            return redirect()->route('run.show');
        }

        // A defeat ejects and puts them on the floor; an egress just ends. Both
        // go through the one place that knows the order.
        if ($result->ending !== RunEnding::Egress) {
            $simulator->resolveEnding($character, $run, $result->ending);
        }

        return redirect()->route('cyberspace.index')
            ->with($result->ending->isBad() ? 'error' : 'status', $result->ending->message());
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
