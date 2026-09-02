@extends('layouts.guest')
@section('title', __('errors.throttle.title'))

@section('content')
    <div class="stack" style="--gap:var(--s-4)">
        <div class="callout callout--warn">{{ $message }}</div>

        <a href="{{ url()->previous() }}" class="btn btn--block cut">{{ __('ui.action.cancel') }}</a>
    </div>
@endsection
