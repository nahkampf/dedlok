@extends('layouts.guest')
@section('title', __('auth.forgot'))

@section('content')
    <x-auth-errors />

    <form method="POST" action="{{ route('password.email') }}" class="stack" style="--gap:var(--s-4)">
        @csrf
        <div class="field">
            <label class="field__label" for="email">{{ __('auth.email') }}</label>
            <input class="input" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <x-captcha />
        <button type="submit" class="btn btn--primary btn--block cut">{{ __('auth.forgot') }}</button>
        <a href="{{ route('login') }}" class="micro" style="text-align:center">{{ __('auth.have_account') }}</a>
    </form>
@endsection
