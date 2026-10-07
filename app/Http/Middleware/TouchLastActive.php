<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remember when a signed-in account last did something, for the front page's count of active players.
 *
 * At most one write every few minutes per account, so it costs nothing on a busy session, and never for the inbox poll,
 * which every open page sends every five seconds whether anyone is there or not. A raw update, not the model's: nothing
 * else about the user changed, so `updated_at` is left alone.
 */
class TouchLastActive
{
    /** How stale the stored moment may get before it is written again. */
    private const MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->route()?->getName() !== 'notifications.unread'
            && ($user->last_active_at === null || $user->last_active_at->lt(now()->subMinutes(self::MINUTES)))) {
            DB::table('users')->where('id', $user->getKey())->update(['last_active_at' => now()]);
            $user->last_active_at = now()->toImmutable();
        }

        return $next($request);
    }
}
