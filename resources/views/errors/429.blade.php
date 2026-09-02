@use('App\Support\Text')
@extends('layouts.guest')
@section('title', Text::get('errors.throttle.title'))

@section('content')
    <div class="stack" style="--gap:var(--s-4)">
        <div class="callout callout--warn">{{ $message }}</div>

        <a href="{{ url()->previous() }}" class="btn btn--block cut">{{ Text::get('ui.action.cancel') }}</a>
    </div>
@endsection
