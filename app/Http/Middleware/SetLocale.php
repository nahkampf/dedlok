<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active locale for every web request.
 *
 * The URL carries no locale segment, so this is the only thing that decides
 * what language a player sees. Order is configurable in config/i18n.php;
 * by default an explicit account preference beats a session choice, which
 * beats Accept-Language negotiation, which beats the app fallback.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request) ?? config('app.fallback_locale');

        App::setLocale($locale);

        // intl needs the full ICU locale, not the short Laravel code, or dates
        // and numbers quietly format against the root locale instead.
        $icu = config("i18n.locales.{$locale}.icu", $locale);
        setlocale(LC_TIME, $icu);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): ?string
    {
        foreach ((array) config('i18n.resolution_order', []) as $source) {
            $candidate = match ($source) {
                'user' => $request->user()?->locale,
                'session' => $request->session()->get(config('i18n.session_key', 'locale')),
                'browser' => $this->negotiate($request),
                default => null,
            };

            if ($candidate !== null && $this->supported($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pick the best supported locale from Accept-Language.
     *
     * getLanguages() returns the header's preferences already sorted by q-value.
     * Entries can be region-tagged ("sv_SE"), so fall back to the base language
     * before giving up — a player sending only "sv-SE" should still get Swedish.
     */
    private function negotiate(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $language = str_replace('-', '_', $language);

            if ($this->supported($language)) {
                return $language;
            }

            $base = strtok($language, '_');

            if ($base !== false && $this->supported($base)) {
                return $base;
            }
        }

        return null;
    }

    private function supported(string $locale): bool
    {
        return array_key_exists($locale, (array) config('i18n.locales', []));
    }
}
