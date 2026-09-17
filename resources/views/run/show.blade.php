@use('App\Cyberspace\RunPurpose')
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

{{-- THE TRACE, AND THE ONE QUESTION IT ANSWERS: is it time to leave.

     Drawn as the chain it actually is — the machine at one end, you at the
     other, and every backdoored relay routed through in between. Routing is not
     built, so today that chain is two nodes; the widget draws a chain rather
     than a pair precisely so it will not have to change when hops arrive. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.trace') }}</h2>
        <div class="spacer"></div>
        @if ($run->trace >= 100)
            <span class="tag tag--crit">{{ Text::get('cyberspace.run.trace_done') }}</span>
        @elseif ($turnsLeft !== null)
            <span class="micro num">{{ Text::plural('cyberspace.run.trace_turns', $turnsLeft, ['count' => $turnsLeft]) }}</span>
        @endif
    </div>

    <div style="padding:0 var(--s-2)">
        @if ($run->traceRate() > 0 || $run->trace > 0)
            @php($chain = $run->traceChain())
            @php($reached = $run->trace / 100 * (count($chain) - 1))

            <div class="trace" style="--v:{{ round($run->trace / 100, 3) }}">
                @foreach ($chain as $index => $node)
                    <span class="trace__node trace__node--{{ $node['kind'] }} @if ($index <= $reached) trace__node--passed @endif"
                          @if ($node['label']) title="{{ $node['label'] }}" @endif>
                        {{ $node['kind'] === 'you' ? '◉' : '◇' }}
                    </span>
                @endforeach
            </div>

            <p class="micro" style="color:var(--ink-2);margin:0 0 var(--s-3)">
                <span class="num">{{ $run->trace }}%</span>
                @if ($run->traceRate() > 0) · +{{ $run->traceRate() }}% a turn @endif
            </p>
        @else
            <p class="micro" style="color:var(--ink-2);padding:var(--s-3) 0;margin:0">
                {{ Text::get('cyberspace.run.trace_none') }}
            </p>
        @endif
    </div>
</div>

{{-- WHAT IS FIGHTING, drawn from the RUN and not from the machine: integrity
     is being ground down, and the catalogue says what an ICE is rather than
     what this fight has left of it. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.ice') }}</h2>
        <div class="spacer"></div>
        <span class="micro num">{{ Text::get('cyberspace.run.turn', ['turn' => $run->turn + 1]) }}</span>
    </div>

    <div style="padding:var(--s-3) 0">
        @if ($run->inAFight())
            @php($ice = $run->ice)
            <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                <span class="tag tag--crit">{{ $ice->name }}</span>
                <span class="micro num">{{ $run->ice_integrity }} / {{ $ice->integrity }}</span>
            </div>

            {{-- The kit's own meter, driven by --v the way every other one is.
                 It is the ICE's health and not the player's, so it empties as
                 things go WELL — `--fill` says which way to read it. --}}
            <div class="meter" style="--v:{{ round($run->ice_integrity / max(1, $ice->integrity), 3) }};--fill:var(--crit);margin-top:var(--s-2)">
                <span class="meter__fill"></span>
            </div>
        @else
            <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.run.ice_none') }}</p>
        @endif
    </div>

    {{-- A PULL IN PROGRESS. Plain text rather than a bar — the total it
         started against is not stored anywhere the bar could read, only what
         is still owed, and inventing a total to draw one would be a number
         nothing else in the run needs. --}}
    @if ($run->extracting_qb !== null)
        <p class="micro" style="color:var(--ink-2);padding:0 var(--s-2);margin:0 0 var(--s-2)">
            {{ Text::get('cyberspace.run.transferring', ['qb' => $run->extracting_qb]) }}
        </p>
    @endif

    {{-- ONE FORM AND ONE DOOR. Every action costs the same turn, which is the
         economy the whole run rests on — so they are one button each on one
         form rather than a route apiece.

         LEAVING IS ALWAYS ON THE LIST and never disabled, whatever else a turn
         may or may not offer: a run with no exit is a character deleted by a
         door they could not see. --}}
    <form method="POST" action="{{ route('run.act') }}" style="padding-bottom:var(--s-3)">
        @csrf
        <div class="row row--wrap" style="--gap:var(--s-2)">
            <button type="submit" name="action" value="attack" class="btn cut" @unless ($run->inAFight()) disabled @endunless>
                {{ Text::get('cyberspace.run.action.attack') }}
            </button>
            {{-- ONLY ON AN EXTRACTION RUN. Purpose decides what a core touch
                 IS — a backdoor and a burn are their own unbuilt purposes, and
                 offering this button on either would promise something
                 nothing yet does. --}}
            @if ($run->purpose === RunPurpose::Extraction)
                <button type="submit" name="action" value="extract" class="btn cut">
                    {{ Text::get($run->extracting_qb !== null ? 'cyberspace.run.action.extract_continue' : 'cyberspace.run.action.extract') }}
                </button>
            @endif
            <button type="submit" name="action" value="hold" class="btn btn--ghost cut">
                {{ Text::get('cyberspace.run.action.hold') }}
            </button>
            <div class="spacer"></div>
            <button type="submit" name="action" value="egress" class="btn btn--warn cut">
                {{ Text::get('cyberspace.run.action.egress') }}
            </button>
        </div>
        <p class="micro" style="color:var(--ink-2);margin:var(--s-2) 0 0">
            {{ Text::get('cyberspace.run.egress_hint') }}
        </p>
    </form>
</div>

{{-- THE ACCOUNT. A run is something a player watches happen, so the log says
     what each turn did in the order it did it — newest last, the way a
     transcript reads. --}}
@if ($run->log)
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__hd"><h2>{{ Text::get('cyberspace.run.log_heading') }}</h2></div>

        <div style="padding:var(--s-3) 0">
            @foreach ($run->log as $line)
                <p class="micro" style="margin:0 0 var(--s-1);@if ($line['tone'] === 'crit') color:var(--crit) @elseif ($line['tone'] === 'ok') color:var(--ok) @else color:var(--ink-2) @endif">
                    <span class="num" style="color:var(--ink-2)">{{ $line['turn'] ?? '' }}</span>
                    {{ $line['text'] }}
                </p>
            @endforeach
        </div>
    </div>
@endif

@endsection
