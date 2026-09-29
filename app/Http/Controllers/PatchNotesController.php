<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PatchNotes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The patch notes modal was closed: remember the newest release it showed.
 *
 * Its own POST, sent as the dialog closes, for the reason the inbox marks read
 * with one. Never moves backwards, so an old tab closing late cannot bring a
 * read note back.
 */
class PatchNotesController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $latest = PatchNotes::all()[0]['version'] ?? null;
        $user = $request->user();
        $seen = $user->last_seen_changelog;

        if ($latest !== null && ($seen === null || version_compare($latest, $seen, '>'))) {
            $user->forceFill(['last_seen_changelog' => $latest])->save();
        }

        return response()->noContent();
    }
}
