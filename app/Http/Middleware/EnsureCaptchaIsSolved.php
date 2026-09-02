<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Captcha\Cap;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a protected form unless its Cap token checks out.
 *
 * This is middleware rather than a validation rule because the routes it
 * guards belong to Fortify: login, registration and the reset link are its
 * controllers, in vendor/, and only registration has an action of ours to
 * hook. Guarding them by route name keeps one mechanism for all three, and
 * config/cap.php's 'protect' list is the only place that knows which.
 *
 * It runs on the web group and returns immediately for everything else, so
 * the cost on an unprotected request is one array lookup.
 */
class EnsureCaptchaIsSolved
{
    public function __invoke(Request $request, Closure $next): Response
    {
        return $this->handle($request, $next);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Cap::enabled() || ! $this->isProtected($request)) {
            return $next($request);
        }

        $field = (string) config('cap.field', 'cap-token');

        // usable() is false when the switch is on but the credentials are
        // missing. Fail closed: a deployment that cannot verify must not
        // behave like one with no captcha at all.
        if (! Cap::usable()) {
            throw ValidationException::withMessages([
                $field => __('captcha.unavailable'),
            ]);
        }

        if (! Cap::verify($request->input($field))) {
            throw ValidationException::withMessages([
                $field => __('captcha.failed'),
            ]);
        }

        return $next($request);
    }

    private function isProtected(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null
            && in_array($name, (array) config('cap.protect', []), true);
    }
}
