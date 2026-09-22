@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('achievements.title'))

@section('content')
    <h1>{{ Text::get('character.title') }}</h1>

    <x-character-tabs current="achievements" />

    <div class="tabpanel">
        <p class="lede" style="margin:0 0 var(--s-4);max-width:70ch">{{ Text::get('achievements.lede') }}</p>

    {{-- EARNED FIRST, because it is the half that is about this player. The
         controller has already sorted both lists alphabetically and filtered
         the secret ones out of the second — a secret achievement is not on
         this page at all until it is won, and then it is in the first list
         like any other. --}}
    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('achievements.gained') }}</span>
        </div>

        <div class="panel__bd">
            @if ($gained === [])
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('achievements.none') }}</p>
            @else
                <x-achievement-grid :rows="$gained" />
            @endif
        </div>
    </div>

    <div class="panel" style="margin-top:var(--s-4)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('achievements.remaining') }}</span>
        </div>

        <div class="panel__bd">
            @if ($remaining === [])
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('achievements.complete') }}</p>
            @else
                <x-achievement-grid :rows="$remaining" />
            @endif
        </div>
    </div>
    </div>
@endsection
