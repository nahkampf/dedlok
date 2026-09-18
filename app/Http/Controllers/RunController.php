<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\TurnResult;
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
    public function show(Request $request, Simulator $simulator): View|RedirectResponse
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
            // THE SAME ASSEMBLY THE ROLL USES, and it carries its own working
            // — the button quotes `percent()`, the card beside it explains
            // where that came from, and the attempt spends the same number.
            // An unexplained collapse in the odds is indistinguishable from a
            // bug, which is the whole reason the explanation is on the screen
            // rather than in a log line.
            'egress' => $simulator->egressReport($character, $run),
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

        return $this->resolve(
            $character,
            $run,
            $simulator->take($character, $run, RunAction::from($data['action'])),
            $simulator,
        );
    }

    /**
     * What a turn's outcome means for the response.
     *
     * SHARED BY BOTH DOORS TO LEAVING, so the standalone egress route and the
     * Egress button cannot end a run differently — which is the same reason
     * they now share the roll.
     */
    private function resolve(
        Character $character,
        Run $run,
        TurnResult $result,
        Simulator $simulator,
    ): RedirectResponse {
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
     * THROUGH THE SIMULATOR, exactly as the turn-based action is. It used to
     * end the run outright, which was correct while there was no roll to make
     * — and became a free exit the moment there was one: a player who found
     * this route could walk out of a fight an Egress button was busy failing
     * at. One door, one roll, one turn spent.
     *
     * The route stays rather than being deleted, because it is what existing
     * forms and links point at, and because "leave" wanting its own URL is
     * reasonable even when it resolves like any other action.
     */
    public function egress(Request $request, Simulator $simulator): RedirectResponse
    {
        $character = $this->character($request);
        $run = $this->liveRun($character);

        if ($run === null) {
            return redirect()->route('cyberspace.index');
        }

        return $this->resolve($character, $run, $simulator->take($character, $run, RunAction::Egress), $simulator);
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
