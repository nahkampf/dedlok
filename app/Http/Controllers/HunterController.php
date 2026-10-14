<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\HunterCombat;
use App\Models\Character;
use App\Models\Hunt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cyberspace combat: one screen, one action.
 *
 * DELIBERATELY THE SMALLEST SCREEN IN THE GAME. A run has a purpose, a
 * briefing, four actions and an egress roll; a hunt has neither a choice of
 * purpose nor a door out — `App\Http\Middleware\EnsureHuntIsResolved` already
 * says so — so there is nothing here to choose beyond pressing Fight.
 */
class HunterController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $character = $this->character($request);
        $hunt = $this->liveHunt($character);

        if ($hunt === null) {
            return redirect()->route('cyberspace.index');
        }

        return view('hunt.show', [
            'character' => $character,
            'hunt' => $hunt,
        ]);
    }

    public function act(Request $request, HunterCombat $combat): RedirectResponse
    {
        $character = $this->character($request);
        $hunt = $this->liveHunt($character);

        if ($hunt === null) {
            return redirect()->route('cyberspace.index');
        }

        $combat->fight($character, $hunt, $request->input('action') === 'egress');

        if ($character->fresh()->current_hunt_id !== null) {
            return redirect()->route('hunt.show');
        }

        // Resolved this exchange — won or lost, the hunt's own log already
        // says which, and the map is where either ending puts you.
        return redirect()->route('cyberspace.index')
            ->with($hunt->fresh()->won || ! $character->fresh()->isJackedIn() ? 'status' : 'error', $this->outcomeMessage($hunt->fresh()));
    }

    private function outcomeMessage(Hunt $hunt): string
    {
        $last = collect($hunt->log ?? [])->last();

        return $last['text'] ?? '';
    }

    private function liveHunt(Character $character): ?Hunt
    {
        if ($character->current_hunt_id === null) {
            return null;
        }

        $hunt = Hunt::query()->with('ice')->find($character->current_hunt_id);

        return $hunt?->isLive() === true ? $hunt : null;
    }

    private function character(Request $request): Character
    {
        return $request->user()->character;
    }
}
