<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The two inventories, and the one thing you can currently do from them.
 *
 * There is no "use item" op set here on purpose: injecting is the only use
 * that exists, and inventing a generic dispatcher for a single case would be
 * the kind of framework the item ops are meant to avoid. When a second op
 * arrives, this grows a match on $item->use_op and nothing else changes.
 */
class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $character = $this->character($request);

        return view('inventory.index', [
            'character' => $character,
            'physical' => $character->inventoryIn(Item::CONTAINER_PHYSICAL),
            'data' => $character->inventoryIn(Item::CONTAINER_DATA),
        ]);
    }

    public function inject(Request $request, InventoryItem $entry, SkillInjector $injector): RedirectResponse
    {
        $character = $this->character($request);

        // Someone else's row is not a refusal to explain, it is a row that
        // does not exist as far as this player is concerned. The injector
        // checks ownership again; this is the HTTP-shaped half.
        abort_unless($entry->character_id === $character->id, 404);

        $skillTitle = $entry->item?->useSkill?->title;

        $result = $injector->inject($character, $entry);

        if (! $result->succeeded()) {
            return back()->with('error', $result->refusal->message());
        }

        return back()->with('status', __('inventory.injected', ['skill' => $skillTitle]));
    }


    private function character(Request $request): Character
    {
        // RequireCharacter guarantees one on every route in this group, so a
        // missing character here is a routing mistake, not a player state.
        return $request->user()->character()->firstOrFail();
    }
}
