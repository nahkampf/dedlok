@use('App\Models\Character')
@use('App\Support\Asset')
@use('App\Support\Text')
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>

    {{-- Every face is self-hosted and declared inside ui-kit.css. No font request
         leaves this origin, so there is nothing to preconnect to. --}}
    <link rel="stylesheet" href="{{ Asset::url('assets/css/ui-kit.css') }}">
    @stack('head')
</head>
<body>

{{-- The character behind the HUD. Named apart from the $character a view may
     already have been given, so the layout can never shadow it. An admin who
     has never played has none, and the bar simply has nothing to show. --}}
@php($hud = auth()->user()?->character)

<div class="shell @auth shell--docked @endauth">

    @auth
        {{-- The rail. Icons at rest; hovering opens it on a pointer device and
             the toggle opens it everywhere else, which is the only way in on a
             phone. Every glyph below is from the set the gallery proves renders
             in the kit's font stack — see App\Models\Item::glyph(). --}}
        <nav class="dock" aria-label="{{ Text::get('ui.nav.sections') }}">
            <button type="button" class="dock__item" data-dock-toggle aria-expanded="false">
                <span class="dock__icon" aria-hidden="true">&#9776;</span>
                <span class="dock__label">{{ Text::get('ui.nav.menu') }}</span>
            </button>

            <a class="dock__item" href="{{ route('inventory.index') }}"
               @if (request()->routeIs('inventory.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#9636;</span>
                <span class="dock__label">{{ Text::get('ui.nav.stash') }}</span>
            </a>

            <a class="dock__item" href="{{ route('skills.index') }}"
               @if (request()->routeIs('skills.*')) aria-current="page" @endif>
                <span class="dock__icon" aria-hidden="true">&#10022;</span>
                <span class="dock__label">{{ Text::get('ui.nav.skills') }}</span>
            </a>

            {{-- Only ever rendered for an admin. The gate is the guard; this is
                 just the door being visible to the people who have one. --}}
            @can('admin')
                <a class="dock__item" href="{{ route('admin.index') }}"
                   @if (request()->routeIs('admin.*')) aria-current="page" @endif>
                    <span class="dock__icon" aria-hidden="true">&#9881;&#65038;</span>
                    <span class="dock__label">{{ Text::get('admin.nav') }}</span>
                </a>
            @endcan

            <div class="spacer"></div>
            <div class="dock__sep"></div>

            {{-- Leaving is a POST, so it is a button. It sits in the rail
                 rather than the bar because the bar is numbers only — which is
                 what makes all three of them fit on a 320px phone. --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dock__item">
                    <span class="dock__icon" aria-hidden="true">&#9211;&#65038;</span>
                    <span class="dock__label">{{ Text::get('auth.logout') }}</span>
                </button>
            </form>
        </nav>
    @endauth

    <div class="shell__body">
        <header class="topbar topbar--stuck">
            {{-- The mark stays in the bar whoever is looking. The wordmark
                 beside it is what goes when there is no room for it — see
                 .brand__name, which is hidden below 560px so the two resources
                 and the balance keep their row on a phone. --}}
            <a href="{{ route('home') }}" class="brand" style="text-decoration:none">
                <span class="brand__mark"></span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>

            @if ($hud)
                {{-- Always on screen, and only what you need at a glance. The
                     maxima are derived, never stored, so they are resolved per
                     request like every other stat. --}}
                @foreach (Character::RESOURCES as $resource => $statId)
                    @php($current = (int) $hud->{$resource})
                    @php($max = $hud->maximumFor($resource))
                    @php($label = Text::get("character.abbreviations.{$statId}"))
                    {{-- Once, not once per attribute that needs it: every
                         maximum is derived, so each read runs the resolver. --}}
                    @php($ratio = $hud->resourceRatio($resource))

                    <div class="gauge">
                        <span class="gauge__label" aria-hidden="true">{{ $label }}</span>
                        <span class="gauge__value num" aria-hidden="true">
                            {{ Text::get('ui.hud.ratio', ['current' => $current, 'max' => $max]) }}
                        </span>
                        {{-- data-hot-low, not a class decided here: ui-kit.js
                             owns what "low" is, so the threshold is one number
                             in one place. --}}
                        <div class="meter" role="meter" data-hot-low aria-valuenow="{{ (int) round($ratio * 100) }}" aria-valuemin="0" aria-valuemax="100"
                             style="--v:{{ $ratio }};--fill:var(--ok)"
                             aria-label="{{ Text::get('ui.hud.aria', [
                                 'label' => $label,
                                 'current' => $current,
                                 'max' => $max,
                             ]) }}">
                            <div class="meter__fill"></div>
                        </div>
                    </div>
                @endforeach

                {{-- Money has no maximum, so it has no bar — see
                     Character::RESOURCES, which is why creds is not in it. --}}
                <div class="gauge">
                    <span class="gauge__label">{{ Text::get('creds.name') }}</span>
                    <span class="gauge__value num">{{ number_format($hud->creds, 0, '.', ' ') }}</span>
                </div>
            @endif

            <div class="spacer"></div>

            @guest
                <a class="btn btn--sm" href="{{ route('login') }}">{{ Text::get('auth.login') }}</a>
            @endguest
        </header>

        @if (session('status'))
            <div style="max-width:1100px;margin:var(--s-4) auto 0;padding-inline:var(--s-4)">
                <div class="callout callout--ok">{{ session('status') }}</div>
            </div>
        @endif

        @if (session('error'))
            <div style="max-width:1100px;margin:var(--s-4) auto 0;padding-inline:var(--s-4)">
                <div class="callout callout--crit">{{ session('error') }}</div>
            </div>
        @endif

        <main style="max-width:1100px;margin:0 auto;padding:var(--s-5) var(--s-4) var(--s-8)">
            @yield('content')
        </main>
    </div>
</div>

<script src="{{ Asset::url('assets/js/ui-kit.js') }}" defer></script>
@stack('scripts')
</body>
</html>
