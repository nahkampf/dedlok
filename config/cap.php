<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cap — self-hosted CAPTCHA
|--------------------------------------------------------------------------
|
| Cap (https://trycap.dev) runs on our own infrastructure: no Google, no
| third-party script in the render path, no data leaving our servers. The
| widget solves proof-of-work plus a browser-instrumentation challenge and
| hands the form a token; the token is worthless until this application has
| POSTed it back to the Cap server for verification.
|
| The client half is App\Http\Middleware\EnsureCaptchaIsSolved, which guards
| the routes listed under 'protect' below. See docker-compose.yml for the
| `cap` service that serves all of this.
|
*/

return [

    /*
    | The operator's switch. Off by default so a fresh clone boots without a
    | Cap server: nothing renders and nothing is verified. Anything that is
    | not explicitly true is false.
    */
    'enabled' => filter_var(env('CAP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | The site-key endpoint, WITH its trailing slash: the widget appends
    | nothing and the server appends 'siteverify'.
    |
    |     https://cap.example.com/d9256640cb53/
    */
    'endpoint' => env('CAP_ENDPOINT'),

    /*
    | Where THIS APPLICATION reaches Cap to verify a token, when that is not
    | the same URL the browser uses. Defaults to 'endpoint'.
    |
    | The two differ whenever Cap is reachable internally by a name the player
    | cannot resolve — which is the normal case under Docker, and the usual
    | shape in production too (a private service address for the server hop, a
    | public hostname for the widget). Locally:
    |
    |     CAP_ENDPOINT        http://localhost:3010/<site-key>/   (browser)
    |     CAP_VERIFY_ENDPOINT http://cap:3000/<site-key>/         (web container)
    |
    | Getting this wrong fails closed rather than open: verification cannot
    | reach the server, so every submission is refused.
    */
    'verify_endpoint' => env('CAP_VERIFY_ENDPOINT'),

    /*
    | The site key's SECRET from the Cap dashboard — not the dashboard's own
    | admin key. It never reaches the browser.
    */
    'secret' => env('CAP_SECRET'),

    /*
    | The widget script and its WASM. Both default to our own vendored copies
    | in public/assets — Cap Standalone does not serve the widget itself, and
    | jsDelivr would put a third party back in the render path that
    | self-hosting Cap exists to remove (it is also unreachable from parts of
    | China). Set these only to point somewhere else deliberately.
    |
    | Left null here rather than defaulted: asset() needs a booted URL
    | generator, so the fallback lives in App\Captcha\Cap.
    */
    'widget_script' => env('CAP_WIDGET_SCRIPT'),

    'wasm_url' => env('CAP_WASM_URL'),

    /*
    | Seconds to wait for /siteverify. Short on purpose: this sits on the
    | login path, and a Cap server that is not answering should fail closed
    | quickly rather than hold the request open.
    */
    'timeout' => (int) env('CAP_TIMEOUT', 5),

    /*
    | The hidden input the widget injects into the surrounding <form>. Cap's
    | own default; changing it means also setting data-cap-hidden-field-name
    | on every widget.
    */
    'field' => 'cap-token',

    /*
    | Route NAMES that require a solved challenge. These are the POST halves
    | of the Fortify auth routes — the GET routes render the form and have
    | nothing to verify. Adding a name here is the whole job: the middleware
    | reads this list and the Blade views render <x-captcha /> themselves.
    */
    'protect' => [
        'login.store',
        'register.store',
        'password.email',
    ],

];
