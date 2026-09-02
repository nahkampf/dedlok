<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('assets/css/ui-kit.css') }}">
</head>
<body class="bg-grid" style="min-height:100vh;display:grid;place-items:center;padding:var(--s-4)">

<div style="width:min(400px,100%)">
    <div class="row" style="justify-content:space-between;margin-bottom:var(--s-4)">
        <span class="brand"><span class="brand__mark"></span> {{ config('app.name') }}</span>
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
