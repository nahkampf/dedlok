<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Combat\Fight\Action;
use App\Combat\Fight\ActionKind;
use App\Combat\Fight\Result;
use App\Combat\FightRefusal;
use App\Combat\Fights;
use App\Models\Character;
use App\Models\Fight;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A fight: one screen, and one press per round.
 *
 * `App\Http\Middleware\EnsureFightIsResolved` sends every other request here while one is live, so
 * this is the only thing a fighter can reach. What a press DOES is `App\Combat\Fights`; this reads the
 * screen's numbers from it (every chance quoted is the one the next roll uses) and sends the player
 * on when it is over.
 */
class FightController extends Controller
{
    public function show(Request $request, Fights $fights): View|RedirectResponse
    {
        $character = $this->character($request);
        $fight = $this->live($character);

        if ($fight === null) {
            return redirect()->route('city.index');
        }

        return view('fight.show', [
            'character' => $character,
            'fight' => $fight,
        ] + $fights->options($fight));
    }

    public function act(Request $request, Fights $fights): RedirectResponse
    {
        $character = $this->character($request);

        $data = $request->validate([
            'action' => ['required', Rule::enum(ActionKind::class)],
            'round' => ['nullable', 'string', 'max:20'],
        ]);

        $result = $fights->act($character, new Action(ActionKind::from($data['action']), $data['round'] ?? null));

        // A press on a fight that is not there (a second click, a stale tab) changes nothing.
        if ($result instanceof FightRefusal) {
            return redirect()->route('city.index')->with('error', $result->message());
        }

        if ($result->isLive()) {
            return redirect()->route('fight.show');
        }

        $won = $result->result === Result::BDown || $result->result?->escaped() !== null;

        // Over, and an encounter started it and is still going: back to it, at whichever screen the ending
        // chose. Straight there rather than through the City, whose redirect would drop the flash. Somebody
        // who is down goes to the City so the waiting room can have them; they will find it where it was.
        if ($result->result !== Result::ADown && $character->fresh()->current_encounter_id !== null) {
            return redirect()->route('encounter.show')->with('status', $this->ending($result));
        }

        // Over. A player who went down is sent to the waiting room by its own middleware; the rest go
        // back to the City with a line saying how it ended.
        return redirect()->route('city.index')->with($won ? 'status' : 'error', $this->ending($result));
    }

    private function ending(Fight $fight): string
    {
        return Text::get('combat.fight.ending.'.($fight->result?->value ?? 'unknown'), ['name' => $fight->opponent_name]);
    }

    private function live(Character $character): ?Fight
    {
        if ($character->current_fight_id === null) {
            return null;
        }

        $fight = Fight::query()->find($character->current_fight_id);

        return $fight?->isLive() === true ? $fight : null;
    }

    private function character(Request $request): Character
    {
        return $request->user()->character;
    }
}
