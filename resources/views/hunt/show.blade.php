@use('App\Support\Text')

@extends('layouts.app')
@section('title', Text::get('cyberspace.hunt.title'))

@section('content')

{{-- THE ONLY SCREEN A HUNTED PLAYER CAN REACH.

     EnsureHuntIsResolved sends every other request here. Unlike a run there is
     no jacking out — only an egress roll, which can fail and costs the exchange.

     The stripe says what the rest of the screen has no room to: this is a
     hunter attacking YOU, not a run against a system — no trace, no IDS. --}}
<div class="hazard-stripe" aria-hidden="true"></div>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.hunt.heading') }}</h2>
        <div class="spacer"></div>
        <span class="tag tag--crit">{{ Text::get('cyberspace.hunt.grade', ['grade' => $hunt->grade]) }}</span>
    </div>

    <div style="padding:var(--s-3) 0">
        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
            <span class="tag tag--crit">{{ $hunt->ice->name }}</span>
            <span class="micro num">{{ $hunt->ice_integrity }} / {{ $hunt->ice->integrity }}</span>
        </div>

        {{-- The kit's own meter, driven by --v the way every other one is. The
             hunter's health, not the player's own — that lives in the topbar's
             dial on every screen already. --}}
        <div class="meter" style="--v:{{ round($hunt->ice_integrity / max(1, $hunt->ice->integrity), 3) }};--fill:var(--crit);margin-top:var(--s-2)">
            <div class="meter__fill"></div>
        </div>
    </div>

    {{-- FIGHT, OR TRY TO EGRESS: a roll that can fail, and a failure costs the exchange (the hunter swings). --}}
    <form method="POST" action="{{ route('hunt.act') }}" style="padding-bottom:var(--s-3)">
        @csrf
        <div class="row row--wrap" style="--gap:var(--s-3)">
            <button type="submit" class="btn btn--danger cut">
                {{ Text::get('cyberspace.hunt.action.fight') }}
            </button>
            <button type="submit" name="action" value="egress" class="btn cut">
                {{ Text::get('cyberspace.hunt.action.egress') }}
            </button>
        </div>
    </form>
</div>

{{-- THE ACCOUNT. Newest first, like the run's: the page is read from the top after every exchange (stored oldest first, reversed here). --}}
@if ($hunt->log)
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__hd"><h2>{{ Text::get('cyberspace.hunt.log_heading') }}</h2></div>

        <div style="padding:var(--s-3) 0">
            @foreach (array_reverse($hunt->log) as $line)
                <p class="micro" style="margin:0 0 var(--s-1);
                    @if ($line['tone'] === 'crit') color:var(--crit)
                    @elseif ($line['tone'] === 'ok') color:var(--ok)
                    @elseif ($line['tone'] === 'warn') color:var(--warn)
                    @else color:var(--ink-2) @endif">
                    {{ $line['text'] }}
                </p>
            @endforeach
        </div>
    </div>
@endif

@endsection
