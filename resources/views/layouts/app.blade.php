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
    <link rel="stylesheet" href="{{ asset('assets/css/ui-kit.css') }}">
    @stack('head')
</head>
<body>

<header class="topbar" style="position:sticky;top:0;z-index:var(--z-nav)">
    <a href="{{ route('home') }}" class="brand" style="text-decoration:none">
        <span class="brand__mark"></span> {{ config('app.name') }}
    </a>

    @auth
        <nav class="nav" style="margin-left:var(--s-4)">
            {{-- @current is not a Blade directive; it rendered as literal text
                 in the markup. The kit marks the active item with aria-current,
                 which .nav__item[aria-current] already styles. --}}
            <a class="nav__item" href="{{ route('home') }}"
               @if (request()->routeIs('home')) aria-current="page" @endif>
                {{ Text::get('ui.nav.run') }}
            </a>
            <a class="nav__item" href="{{ route('inventory.index') }}"
               @if (request()->routeIs('inventory.*')) aria-current="page" @endif>
                {{ Text::get('ui.nav.stash') }}
            </a>
            <a class="nav__item" href="{{ route('skills.index') }}"
               @if (request()->routeIs('skills.*')) aria-current="page" @endif>
                {{ Text::get('ui.nav.skills') }}
            </a>
            <a class="nav__item" href="{{ route('home') }}">{{ Text::get('ui.nav.contracts') }}</a>
        </nav>
    @endauth

    <div class="spacer"></div>

    @auth
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn--sm btn--ghost">{{ Text::get('auth.logout') }}</button>
        </form>
    @else
        <a class="btn btn--sm" href="{{ route('login') }}">{{ Text::get('auth.login') }}</a>
    @endauth
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

<script src="{{ asset('assets/js/ui-kit.js') }}" defer></script>
@stack('scripts')
</body>
</html>
