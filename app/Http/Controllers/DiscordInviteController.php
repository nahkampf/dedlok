<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Discord invitation was closed: remember it, so it is shown once. Its own POST, sent as the dialog closes (the
 * patch notes' shape), and idempotent: a second close does not move the moment.
 */
class DiscordInviteController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->seen_discord_at === null) {
            $user->forceFill(['seen_discord_at' => now()])->save();
        }

        return response()->noContent();
    }
}
