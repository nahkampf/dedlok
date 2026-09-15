<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cyberspace\ReadinessWatch;
use App\Implants\ImplantFitter;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What is installed in a body, and what room is left.
 *
 * The writer is App\Implants\ImplantFitter; what is left here is what a
 * controller is for — reading the request, choosing the message, redirecting
 * back.
 */
class ImplantController extends Controller
{
    public function index(Request $request, ImplantFitter $fitter): View
    {
        $character = $this->character($request);

        return view('implants.index', [
            'character' => $character,
            'body' => $fitter->body($character),
            // What is to hand and would go somewhere: loose implants only, so
            // one inside a memory shard or fitted into a deck is not offered
            // as though it could be installed from here.
            'available' => InventoryItem::query()
                ->where('character_id', $character->getKey())
                ->loose()
                ->whereHas('item', static fn ($item) => $item->whereNotNull('implant_region'))
                ->with('item')
                ->get()
                ->groupBy(static fn (InventoryItem $row): string => $row->item->implant_region->value),
        ]);
    }

    public function install(Request $request, InventoryItem $entry, ImplantFitter $fitter): RedirectResponse
    {
        $character = $this->character($request);
        $result = $fitter->install($character, $entry);

        return $result->succeeded()
            ? $this->watched($character, back()->with('status', Text::get('implants.installed', [
                'implant' => $result->implant->item?->displayName() ?? '',
            ])))
            : back()->with('error', $result->refusal->message());
    }

    public function remove(Request $request, InventoryItem $entry, ImplantFitter $fitter): RedirectResponse
    {
        $character = $this->character($request);
        $result = $fitter->remove($character, $entry);

        return $result->succeeded()
            ? $this->watched($character, back()->with('status', Text::get('implants.removed', [
                'implant' => $result->implant->item?->displayName() ?? '',
            ])))
            : back()->with('error', $result->refusal->message());
    }

    /**
     * Surgery is not refused while jacked in — the deck lock is about the
     * deck — and an implant's modifiers can reach a deck measure. So pulling
     * one can be the thing that stops the machine somebody is standing in, and
     * they are told here rather than at their next click: two flashes, because
     * the implant did come out and the deck did stop.
     */
    private function watched(Character $character, RedirectResponse $response): RedirectResponse
    {
        $ejection = ReadinessWatch::fromConfig()->check($character);

        return $ejection === null ? $response : $response->with('error', $ejection->message());
    }

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group.
        return $request->user()->character()->firstOrFail();
    }
}
