<?php

use App\Http\Middleware\EnsureCaptchaIsSolved;
use App\Http\Middleware\EnsureCharacterIsStanding;
use App\Http\Middleware\EnsureDeckStillRuns;
use App\Http\Middleware\EnsureEncounterIsResolved;
use App\Http\Middleware\EnsureFightIsResolved;
use App\Http\Middleware\EnsureHuntIsResolved;
use App\Http\Middleware\EnsureRunIsFinished;
use App\Http\Middleware\RequireCharacter;
use App\Support\Text;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The character bio keeps its leading spaces: it is where ASCII art is drawn, and the framework's blanket
        // trim would shift the first line of an indented drawing left. The controller does its own, gentler trim.
        $middleware->trimStrings(except: ['bio']);

        $middleware->web(append: [
            // Runs after the session is available, so $request->user() is set.
            RequireCharacter::class,
            // Guards the routes named in config/cap.php; a no-op elsewhere.
            EnsureCaptchaIsSolved::class,
            // The net under ReadinessWatch: throws a player out of cyberspace
            // when their deck has stopped running. After RequireCharacter,
            // because it needs the character that guarantees. It stands down
            // inside a run — a burnout is supposed to break the deck.
            EnsureDeckStillRuns::class,
            // On the floor beats everything below it, including a live run:
            // a defeat ejects BEFORE the waiting screen is shown, so the two
            // should never overlap — and if they do, somebody unconscious is
            // in no position to take a turn. It also releases on the way past,
            // which is what makes the wait need no scheduler.
            EnsureCharacterIsStanding::class,
            // A live hunter owns the screen the same way a live run does —
            // checked alongside it, since a character is never in both at
            // once and the order between the two never matters.
            EnsureHuntIsResolved::class,
            // A live fight owns the screen the same way. Before the encounter lock, because a
            // fight opens INSIDE an encounter and settling it is what lets the encounter resume.
            EnsureFightIsResolved::class,
            // An open encounter owns the screen the same way, and waits for the player.
            EnsureEncounterIsResolved::class,
            // A live run owns the screen. Last, because it is the narrowest
            // gate: everything before it decides whether the player may be
            // here at all, and this decides where "here" is.
            EnsureRunIsFinished::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Translate the rate-limit message.
         *
         * Illuminate\Routing\Middleware\ThrottleRequests throws a raw English
         * literal ('Too Many Attempts.') rather than a translation key, and it
         * lives in vendor/, so it cannot be edited — composer install would
         * revert it. Re-rendering the exception here is the supported seam, and
         * it covers every throttled route at once rather than one limiter.
         *
         * The original headers must be carried through: Retry-After and the
         * X-RateLimit-* pair are the machine-readable half of a 429, and
         * dropping them would break any client that backs off politely.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $headers = $e->getHeaders();
            $seconds = (int) ($headers['Retry-After'] ?? 60);

            $message = Text::get('errors.throttle.body', ['seconds' => $seconds]);

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 429, $headers);
            }

            return response()->view('errors.429', [
                'message' => $message,
                'seconds' => $seconds,
            ], 429, $headers);
        });
    })->create();
