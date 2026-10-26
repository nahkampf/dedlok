# The Laravel application

_Split out of CLAUDE.md. Heading levels are as they were there._

## The Laravel application

Laravel 13, `laravel/fortify` for auth, `laravel/tinker`, **no Vite and no npm** — the kit
is build-free. Do not add a bundler.

### Laravel 13 specifics

- Models declare `#[Fillable([...])]` and `#[Hidden([...])]` as **PHP attributes**. The
  old properties do nothing.
- No `app/Http/Kernel.php`. Middleware goes in `bootstrap/app.php` via
  `->withMiddleware()`, providers in `bootstrap/providers.php`.
- `laravel/agent-detector` probes paths like `/opt/.devin` at autoload-dump time — which
  is why `open_basedir` had to move off the CLI.

### Player-visible strings

**The game speaks English only.** No `lang/`, no locale to resolve, and `__()` /
`trans_choice()` are not used. Do not reintroduce them.

Every player-visible string lives in exactly one of two places:

- **On the row, if the thing has a row** (an item's `name` and `flavor`, a skill's
  `title`). A definition half in the database and half in a file is two things to keep in
  step.
- **In `config/strings.php` otherwise** — UI chrome, auth screens, refusals, captcha
  labels, the tier vocabulary. Read through `App\Support\Text` (config lookup plus
  `:placeholder` substitution).

- **Keys name the role, not the text** — `ui.action.jack_in`.
- **Counted strings** are `{zero, one, many}` read by `Text::plural()`.
- **`Text::get()` returns the key** when missing.
- **An imprint has no name of its own** — it composes via `items.imprint.name`.
- **Archetypes carry their strings on their rows.** Perk labels live on `archetype_perks`,
  except where a perk borrows an attribute's name — those are null and read
  `character.names.<key>`.
- **Framework strings that reach a player** are swapped by the exception renderers in
  `bootstrap/app.php` from `strings.errors.*` (Laravel throws a raw `'Too Many Attempts.'`
  and vendor code cannot be edited). **Carry the original headers through** — `Retry-After`
  and `X-RateLimit-*` are the machine-readable half of a 429.
- **`config/app.php` still sets `locale` and `fallback_locale`, and they stay** — vendor
  validation and auth messages are keyed by them.

### Auth

Fortify, with **all views written by us** in `resources/views/auth/`. Two-factor and
passkeys are **disabled** in `config/fortify.php` because their screens do not exist; the
tables are already migrated.

**One character per user, enforced by two middlewares:**

- **`RequireCharacter`** on every web request: a signed-in player without a character goes
  to the creation screen, except the routes it names (creation form, logout, handle pool)
  and anything under `admin.`.
- **`ForbidSecondCharacter`** on those two creation routes: a player who has a character
  is sent to the run screen. **It guards the POST as well as the form** —
  `characters.user_id` is unique, so an unguarded second submission is a 500. A safe
  request is redirected silently; a write says why.
- **An admin may still *look* at the creation screen**, with or without a character — it
  is a screen they maintain. The POST is refused for everyone who already has one. The
  middleware marks the request (`ForbidSecondCharacter::PREVIEW`) and the view renders a
  notice and **no submit button** (a disabled one would be re-enabled by the pool updater).
- Both answer a JSON caller with **409** and a URL, not a redirect a fetch would follow
  into HTML.

### Captcha

**Cap** ([trycap.dev](https://trycap.dev)), self-hosted in the `cap` service. Guards login,
registration and password-reset request.

- **Adding it to a form is one tag: `<x-captcha />`.** `ui-kit.js` upgrades every
  `.captcha` into `<cap-widget>`, reading endpoint and labels from the two globals
  `<x-captcha-runtime />` emits. Inside a `<form>` the widget injects its own hidden
  `cap-token`.
- **Protecting a route is one line in `config/cap.php`** — the `protect` list holds route
  names, read by `App\Http\Middleware\EnsureCaptchaIsSolved`. Middleware rather than a
  validation rule because two of the three routes are Fortify's.
- **The widget is a challenge, not a gate.** Nothing is protected until `App\Captcha\Cap`
  has POSTed the token to `/siteverify`.
- **Every failure mode fails closed** — unreachable, error status, forged token, and
  *enabled but missing credentials*.
- **Off by default** (`CAP_ENABLED=false`); the component renders nothing while off.
- The widget lives in an **open shadow root** — the `--cap-*` custom properties are the
  whole styling surface, mapped onto tokens in `ui-kit.css`. Cap's 14px radius is
  overridden.
- **The widget and its WASM are vendored** in `public/assets/js/cap-widget.js` and
  `public/assets/wasm/`, because Cap Standalone serves neither and the widget would fetch
  WASM from jsDelivr. The file header says how to update. One third-party call remains: a
  `pako` fallback on browsers without `DecompressionStream`.
- **Two endpoints.** `CAP_ENDPOINT` is the browser's (`localhost:3010`),
  `CAP_VERIFY_ENDPOINT` the container's (`cap:3000`), defaulting to the first.
  `CAP_SECRET` is the site key's secret, never `CAP_ADMIN_KEY`.
