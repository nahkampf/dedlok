<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The topbar speaker: remember whether this account wants the interface's sounds. Idempotent, 204. */
class SoundController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $request->user()->forceFill(['sound_enabled' => (bool) $validated['enabled']])->save();

        return response()->noContent();
    }
}
