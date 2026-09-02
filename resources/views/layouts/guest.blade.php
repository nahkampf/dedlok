<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ config('i18n.locales.'.app()->getLocale().'.dir', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/css/ui-kit.css') }}">
</head>
<body class="bg-grid" style="min-height:100vh;display:grid;place-items:center;padding:var(--s-4)">

<div style="width:min(400px,100%)">
    <div class="row" style="justify-content:space-between;margin-bottom:var(--s-4)">
        <span class="brand"><span class="brand__mark"></span> {{ config('app.name') }}</span>
        <x-locale-switcher />
    </div>

    <div class="panel cut" style="--cut:14px">
        <div class="panel__hd"><span class="micro">@yield('title')</span></div>
        <div class="panel__bd">
            @yield('content')
        </div>
    </div>
</div>

<x-captcha-runtime />
<script src="{{ asset('assets/js/ui-kit.js') }}" defer></script>
@stack('scripts')
</body>
</html>
