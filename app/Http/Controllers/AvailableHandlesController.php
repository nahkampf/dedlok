<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SuggestedHandle;
use Illuminate\Http\JsonResponse;

class AvailableHandlesController extends Controller
{
    /**
     * Return every handle still up for grabs, for the client to pick from.
     *
     * The client fetches this once and randomises locally, so pressing the
     * suggest button repeatedly costs nothing. That is the whole point: the
     * previous design issued one query per press.
     *
     * Nothing here is secret — these are pre-authored suggestions, and the
     * unused ones are exactly what the button would hand out anyway. The route
     * stays throttled so the list cannot be scraped in a tight loop.
     */
    public function __invoke(): JsonResponse
    {
        $handles = SuggestedHandle::availableForSuggestion();

        return response()->json([
            'handles' => $handles,
            'count' => count($handles),
        ])->setPublic()->setMaxAge(300);
    }
}
