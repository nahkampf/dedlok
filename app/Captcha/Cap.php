<?php

declare(strict_types=1);

namespace App\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The server half of the CAPTCHA: turning a widget token into a yes or no.
 *
 * A token proves nothing until Cap has confirmed it. Anything that accepts a
 * form protected by the widget must call verify() — the widget is a challenge,
 * not a gate, and a client that skips it simply posts without a token.
 *
 * Every failure mode here fails CLOSED. A misconfigured instance, an
 * unreachable server and a forged token all return false, because the one
 * outcome worse than turning users away is quietly letting everyone through
 * while the login form still shows a checkbox that says "verified".
 */
final class Cap
{
    public static function enabled(): bool
    {
        return (bool) config('cap.enabled');
    }

    /** The endpoint the BROWSER talks to, with its trailing slash. */
    public static function endpoint(): ?string
    {
        return self::normalise(config('cap.endpoint'));
    }

    /**
     * The endpoint THIS APPLICATION talks to, with its trailing slash.
     *
     * Usually the same URL, but it must be allowed to differ: under Docker the
     * widget reaches Cap on a published port while the web container reaches it
     * by service name, and neither host resolves for the other. Falls back to
     * the browser's endpoint, which is right whenever one URL serves both.
     */
    public static function verifyEndpoint(): ?string
    {
        return self::normalise(config('cap.verify_endpoint')) ?? self::endpoint();
    }

    private static function normalise(mixed $url): ?string
    {
        return is_string($url) && trim($url) !== ''
            ? rtrim(trim($url), '/').'/'
            : null;
    }

    /**
     * Where the browser loads the widget from.
     *
     * Our own copy by default. Cap Standalone does not serve the widget — its
     * /assets is the dashboard's — so the choice is between vendoring the file
     * and calling jsDelivr on every page load. See public/assets/js/.
     */
    public static function widgetScript(): string
    {
        return self::configuredUrl('cap.widget_script')
            ?? asset('assets/js/cap-widget.js');
    }

    /**
     * Where the widget loads its WASM from.
     *
     * Without this the widget fetches it from jsDelivr, which would undo most
     * of the point of self-hosting: the CDN would see every solve.
     */
    public static function wasmUrl(): string
    {
        return self::configuredUrl('cap.wasm_url')
            ?? asset('assets/wasm/cap_wasm_bg.wasm');
    }

    private static function configuredUrl(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Is a solved challenge required, and can we actually check one?
     *
     * Separate from enabled() because "switched on but not configured" is a
     * deployment error, not a state to run in. It is reported here rather
     * than thrown so a bad .env costs a login attempt and a log line instead
     * of a stack trace on the front page.
     */
    public static function usable(): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if (self::verifyEndpoint() === null || ! is_string(config('cap.secret')) || config('cap.secret') === '') {
            Log::error('Cap is enabled but CAP_ENDPOINT or CAP_SECRET is missing; captcha-protected routes will refuse every submission.');

            return false;
        }

        return true;
    }

    /**
     * Ask the Cap server whether this token is genuine and unspent.
     *
     * Tokens are single-use, so a replayed one is rejected by the server
     * rather than by us.
     */
    public static function verify(?string $token): bool
    {
        if (! is_string($token) || trim($token) === '') {
            return false;
        }

        $endpoint = self::verifyEndpoint();

        if ($endpoint === null) {
            return false;
        }

        try {
            // Http:: goes through cURL. file_get_contents() cannot be used
            // here at all: allow_url_fopen is Off in docker/php/php.ini.
            $response = Http::timeout((int) config('cap.timeout', 5))
                ->acceptJson()
                ->post($endpoint.'siteverify', [
                    'secret' => config('cap.secret'),
                    'response' => $token,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Cap verification could not reach the server.', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Cap verification returned an error status.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return $response->json('success') === true;
    }
}
