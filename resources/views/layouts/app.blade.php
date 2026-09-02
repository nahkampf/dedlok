<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ config('i18n.locales.'.app()->getLocale().'.dir', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>

    {{-- Overpass is self-hosted and declared inside ui-kit.css; only Plex is remote. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
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
            <a class="nav__item" href="{{ route('home') }}" @current(request()->routeIs('home'))>
                {{ __('ui.nav.run') }}
            </a>
            <a class="nav__item" href="{{ route('home') }}">{{ __('ui.nav.stash') }}</a>
            <a class="nav__item" href="{{ route('home') }}">{{ __('ui.nav.contracts') }}</a>
        </nav>
    @endauth

    <div class="spacer"></div>

    <x-locale-switcher />

    @auth
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn--sm btn--ghost">{{ __('auth.logout') }}</button>
        </form>
    @else
        <a class="btn btn--sm" href="{{ route('login') }}">{{ __('auth.login') }}</a>
    @endauth
</header>

@if (session('status'))
    <div style="max-width:1100px;margin:var(--s-4) auto 0;padding-inline:var(--s-4)">
        <div class="callout callout--ok">{{ session('status') }}</div>
    </div>
@endif

<main style="max-width:1100px;margin:0 auto;padding:var(--s-5) var(--s-4) var(--s-8)">
    @yield('content')
</main>

<script src="{{ asset('assets/js/ui-kit.js') }}" defer></script>
@stack('scripts')
</body>
</html>
