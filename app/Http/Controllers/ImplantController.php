<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Implants\ImplantFitter;
use App\Models\Character;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What is installed in a body, and what room is left.
 *
 * READ ONLY. The writer is App\Implants\ImplantFitter, and the only door to it
 * a player can reach is surgery at a clinic (`App\City\Surgeon`).
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

    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group.
        return $request->user()->character()->firstOrFail();
    }
}
