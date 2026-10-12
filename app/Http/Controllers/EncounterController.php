<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\City\CityRefusal;
use App\City\EncounterBand;
use App\City\EncounterRunner;
use App\Models\Character;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An encounter: one screen, answered a choice at a time.
 *
 * The only routes an `EnsureEncounterIsResolved` player may reach. Every rule —
 * the current node, the flag, the cost, the roll — is `EncounterRunner`'s; this
 * only reads the row and renders it, so a hand-built POST gets the same answer as
 * a button.
 */
class EncounterController extends Controller
{
    public function show(Request $request, EncounterRunner $runner): View|RedirectResponse
    {
        $character = $this->character($request);
        $live = $character->currentEncounter()->with('encounter')->first();

        if ($live === null) {
            return redirect()->route($this->home($character));
        }

        $node = $runner->nodeOf($live);

        // A node an editor removed under somebody closes the encounter, quietly.
        if ($node === null) {
            $runner->leave($character);

            return redirect()->route($this->home($character));
        }

        $choices = $node->ends
            ? collect()
            : $node->choices()->with('outcomes')->get()
                ->filter(fn ($choice): bool => $runner->offered($character, $choice))
                ->map(function ($choice) use ($runner, $character): array {
                    $chance = $runner->chanceOf($character, $choice);

                    return [
                        'choice' => $choice,
                        'band' => $chance === null ? null : EncounterBand::for($chance),
                        'percent' => $chance === null ? null : (int) round($chance * 100),
                        'affordable' => $character->creds >= $choice->cost,
                    ];
                });

        return view('encounter.show', [
            'character' => $character,
            'live' => $live,
            'encounter' => $live->encounter,
            'node' => $node,
            'choices' => $choices,
        ]);
    }

    public function choose(Request $request, EncounterRunner $runner): RedirectResponse
    {
        $choice = (int) $request->validate(['choice' => ['required', 'integer']])['choice'];

        $result = $runner->choose($this->character($request), $choice);

        if ($result instanceof CityRefusal) {
            return redirect()->route('encounter.show')->with('error', $result->message());
        }

        // A fight opened: straight to it. Bouncing off the encounter screen would go through the fight
        // lock's redirect and lose the flash.
        if ($result->fight) {
            return redirect()->route('fight.show')->with('status', implode(' ', $result->lines));
        }

        // Closed: back to where they are (the City, or the map if they are jacked in), with what that choice did.
        // Otherwise the next node.
        return $result->closed
            ? redirect()->route($this->home($this->character($request)))->with('status', implode(' ', $result->lines))
            : redirect()->route('encounter.show')->with('status', implode(' ', $result->lines));
    }

    public function leave(Request $request, EncounterRunner $runner): RedirectResponse
    {
        $result = $runner->leave($this->character($request));

        return $result instanceof CityRefusal
            ? redirect()->route('encounter.show')->with('error', $result->message())
            : redirect()->route($this->home($this->character($request)));
    }

    /** Where an encounter ends: on the map for somebody who is jacked in, in the City otherwise. */
    private function home(Character $character): string
    {
        return $character->isJackedIn() ? 'cyberspace.index' : 'city.index';
    }

    private function character(Request $request): Character
    {
        return $request->user()->character()->firstOrFail();
    }
}
