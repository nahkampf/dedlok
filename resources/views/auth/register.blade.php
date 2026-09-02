@use('App\Support\Text')
@extends('layouts.guest')
@section('title', Text::get('auth.register'))

@section('content')
    <x-auth-errors />

    <form method="POST" action="{{ route('register') }}" class="stack" style="--gap:var(--s-4)">
        @csrf

        <div class="field">
            <label class="field__label" for="email">{{ Text::get('auth.email') }}</label>
            <input class="input" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autocomplete="username">
        </div>

        <div class="field">
            <label class="field__label" for="password">{{ Text::get('auth.password') }}</label>
            <input class="input" id="password" name="password" type="password"
                   required autocomplete="new-password">
        </div>

        <div class="field">
            <label class="field__label" for="password_confirmation">{{ Text::get('auth.confirm_password') }}</label>
            <input class="input" id="password_confirmation" name="password_confirmation"
                   type="password" required autocomplete="new-password">
        </div>

        <x-captcha />

        <button type="submit" class="btn btn--primary btn--block cut">{{ Text::get('auth.register') }}</button>

        <a href="{{ route('login') }}" class="micro" style="text-align:center">{{ Text::get('auth.have_account') }}</a>
    </form>
@endsection
