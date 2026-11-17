@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('exchange.title'))

@section('content')
    <h1>{{ Text::get('exchange.title') }}</h1>

    {{-- Kronos Capital's desk: who it is and the counter. No work and no store. --}}
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__hd">
            <h2>{{ $entity->name }}</h2>
            <div class="spacer"></div>
            <span class="tag">{{ $entity->category->label() }}</span>
            <span class="micro"><x-address :address="$system->address" /></span>
        </div>
        <div class="panel__bd stack" style="--gap:var(--s-3)">
            @if ($entity->description)
                <p style="margin:0;max-width:70ch">{{ $entity->description }}</p>
            @endif
            <span class="chip-stat">
                <span class="micro">{{ Text::get('entities.standing') }}</span>
                <strong style="color:{{ $band->inkToken() }}">{{ $band->label() }}</strong>
                <span class="num micro">{{ number_format($standing, 2) }}</span>
            </span>
        </div>
    </div>

    @include('bank._panel')
@endsection
