<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Decryption\DecryptionQueue;
use App\Decryption\DecryptionRefusal;
use App\Models\DecryptionEntry;
use App\Models\InventoryItem;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The HTTP-shaped half of `App\Decryption\DecryptionQueue`: put a file in the queue, take one out.
 *
 * Someone else's row is a row that does not exist as far as this player is concerned (404), the call every
 * inventory door makes; the queue checks ownership again.
 */
class DecryptionController extends Controller
{
    public function store(Request $request, InventoryItem $entry, DecryptionQueue $queue): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        abort_unless($entry->character_id === $character->id, 404);

        $label = $entry->extractedFile()?->label ?? '';
        $result = $queue->enqueue($character, $entry);

        return $result instanceof DecryptionRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('decryption.queued', ['label' => $label]));
    }

    /** The queue in a new order, as the ids of its entries. JSON, because the page's drag handle posts it and reloads. */
    public function reorder(Request $request, DecryptionQueue $queue): JsonResponse
    {
        $character = $request->user()->character()->firstOrFail();

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:'.(int) config('cyberspace.decryption.queue_max', 10)],
            'order.*' => ['integer'],
        ]);

        $result = $queue->reorder($character, $validated['order']);

        if ($result instanceof DecryptionRefusal) {
            return response()->json(['message' => $result->message()], 422);
        }

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, DecryptionEntry $entry, DecryptionQueue $queue): RedirectResponse
    {
        $character = $request->user()->character()->firstOrFail();
        abort_unless($entry->character_id === $character->id, 404);

        $result = $queue->remove($character, $entry);

        return $result instanceof DecryptionRefusal
            ? back()->with('error', $result->message())
            : back()->with('status', Text::get('decryption.removed'));
    }
}
