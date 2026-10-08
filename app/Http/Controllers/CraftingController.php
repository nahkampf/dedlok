<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crafting\Crafting;
use App\Crafting\CraftingRefusal;
use App\Models\Craft;
use App\Models\InventoryItem;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The HTTP-shaped half of `App\Crafting\Crafting`: start a build from a design in the stash, or give one up.
 *
 * Someone else's row is a row that does not exist (404), the call every inventory door makes; the service checks ownership again.
 */
class CraftingController extends Controller
{
    public function store(Request $request, InventoryItem $entry, Crafting $crafting): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        abort_unless($entry->character_id === $character->id, 404);

        $result = $crafting->start($character, $entry, $request->boolean('confirm'));

        if ($result instanceof CraftingRefusal) {
            $back = back()->with('error', $result->message());

            // The one refusal that is a question: the panel offers the same press again, this time confirmed.
            return $result === CraftingRefusal::WastesGrade ? $back->with('confirm_craft', $entry->id) : $back;
        }

        return back()->with('status', Text::get('crafting.started', ['item' => $result->name]));
    }

    public function destroy(Request $request, Craft $craft, Crafting $crafting): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        abort_unless($craft->character_id === $character->id, 404);

        $crafting->cancel($character, $craft);

        return back()->with('status', Text::get('crafting.cancelled'));
    }
}
