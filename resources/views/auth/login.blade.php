@use('App\Support\Text')
@extends('layouts.guest')
@section('title', Text::get('auth.login'))

@section('content')
    <x-auth-errors />

    <form method="POST" action="{{ route('login') }}" class="stack" style="--gap:var(--s-4)">
        @csrf

        <div class="field">
            <label class="field__label" for="email">{{ Text::get('auth.email') }}</label>
            <input class="input" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>

        <div class="field">
            <label class="field__label" for="password">{{ Text::get('auth.password') }}</label>
            <input class="input" id="password" name="password" type="password"
                   required autocomplete="current-password">
        </div>

        <label class="check">
            <input type="checkbox" name="remember" value="1">
            <span class="check__box"></span>
            <span>{{ Text::get('auth.remember') }}</span>
        </label>

        <x-captcha />

        <button type="submit" class="btn btn--primary btn--block cut">{{ Text::get('auth.login') }}</button>

        <a href="{{ route('password.request') }}" class="micro" style="text-align:center">
            {{ Text::get('auth.forgot') }}
        </a>
    </form>

    {{-- Registration is a separate decision from signing in, so it sits below
         the divider rather than competing with the submit button. --}}
    <div class="divider"></div>

    <a href="{{ route('register') }}" class="btn btn--go btn--block btn--ok cut">
        {{ Text::get('auth.start_playing') }}
    </a>
@endsection
