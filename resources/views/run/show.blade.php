@use('App\Support\Text')

@extends('layouts.app')
@section('title', Text::get('cyberspace.run.title'))

@section('content')

{{-- THE ONLY SCREEN A PLAYER IN A RUN CAN REACH.

     EnsureRunIsFinished sends every other request here, which is what replaces
     atomicity: signing out does not end a run, it postpones a turn. So this
     page has to be able to stand alone — there is no rail out of it, and the
     only way off it is to finish what was started. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.heading') }}</h2>
        <div class="spacer"></div>
        <span class="tag">{{ $run->purpose->label() }}</span>
        <span class="micro num">{{ Text::get('cyberspace.run.turn', ['turn' => $run->turn]) }}</span>
    </div>

    <div class="row row--wrap" style="--gap:var(--s-4);align-items:center;padding:var(--s-3) 0">
        {{-- The target, drawn from the RUN and not from the machine: the row
             carries the address and the position it went in at, so a machine
             that has since moved does not change what this run was against. --}}
        <span class="micro"><span aria-hidden="true">&#x2295;</span> {{ $run->position() }}</span>
        <span class="micro"><x-address :address="$run->address" /></span>

        @if ($system)
            <span class="tag">{{ $system->displayName() }}</span>
        @else
            {{-- Burned or relocated out from under them. Nothing here cancels
                 the run yet — that is the simulator's, and it is why the row
                 carries an address rather than a foreign key. --}}
            <span class="tag tag--warn">{{ Text::get('cyberspace.run.ended.cancelled') }}</span>
        @endif
    </div>
</div>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd"><h2>{{ Text::get('cyberspace.run.turn', ['turn' => $run->turn + 1]) }}</h2></div>

    <p class="micro" style="color:var(--ink-2);padding:var(--s-3) 0">
        {{ Text::get('cyberspace.run.not_built') }}
    </p>

    {{-- LEAVING IS ALWAYS ON THE LIST. A run with no exit is a character
         deleted by a door they could not see, so this button is never absent
         and never disabled — whatever else a turn may or may not offer. --}}
    <form method="POST" action="{{ route('run.egress') }}" style="padding-bottom:var(--s-3)">
        @csrf
        <button type="submit" class="btn btn--warn cut">{{ Text::get('cyberspace.run.egress') }}</button>
        <p class="micro" style="color:var(--ink-2);margin:var(--s-2) 0 0">
            {{ Text::get('cyberspace.run.egress_hint') }}
        </p>
    </form>
</div>

@endsection
