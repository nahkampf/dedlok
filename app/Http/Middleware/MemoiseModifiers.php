<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Stats\ModifierMemo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches `Character::modifiers()` memoisation on for one web request and off again after it (see `ModifierMemo`).
 */
final class MemoiseModifiers
{
    public function handle(Request $request, Closure $next): Response
    {
        ModifierMemo::enable();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        ModifierMemo::disable();
    }
}
