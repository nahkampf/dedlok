@use('App\Support\Asset')
@use('App\Support\Text')
@php
    $code = $exception->getStatusCode();
    $key = "errors.http.{$code}";
    $message = Text::get($key) === $key ? null : Text::get($key);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} - {{ config('app.name') }}</title>
    @include('layouts.social-meta')
    <link rel="stylesheet" href="{{ Asset::url('assets/css/ui-kit.css') }}">
</head>
<body class="bg-grid" style="min-height:100vh;display:grid;place-items:center;padding:var(--s-4)">

<div style="width:min(560px,100%)">
    <a href="{{ url('/') }}">
        <img src="{{ Asset::url('images/logo-560.png') }}" alt="{{ config('app.name') }}"
             srcset="{{ Asset::url('images/logo-320.png') }} 320w,
                     {{ Asset::url('images/logo-560.png') }} 560w,
                     {{ Asset::url('images/logo-840.png') }} 840w,
                     {{ Asset::url('images/logo-1120.png') }} 1120w"
             sizes="(max-width: 640px) 100vw, 560px"
             width="1120" height="448"
             style="display:block;width:min(560px,100%);height:auto;margin:0 auto var(--s-5)">
    </a>

    <div class="panel cut" style="--cut:14px">
        <div class="panel__bd stack" style="--gap:var(--s-4);text-align:center;padding:var(--s-6)">
            <h1 style="font-size:var(--fs-3xl)">{{ $code }}</h1>
            @if ($message)
                <p>{{ $message }}</p>
            @endif
            <div class="row row--wrap" style="--gap:var(--s-3);justify-content:center">
                @if (in_array($code, [401, 419], true))
                    <a href="{{ route('login') }}" class="btn cut">{{ Text::get('auth.login') }}</a>
                @endif
                <a href="{{ url('/') }}" class="btn cut">{{ Text::get('errors.back') }}</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
