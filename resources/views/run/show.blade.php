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
        {{-- Where the run has got to, and the one number to watch: large, and green because it is
             progress rather than danger (the trace below is the danger). --}}
        <span class="num" style="font-size:var(--fs-xl);line-height:1;color:var(--ok)">{{ Text::get('cyberspace.run.turn', ['turn' => $run->turn]) }}</span>
    </div>

    {{-- The target, drawn from the RUN and not from the system: the row carries the address and the
         position it went in at, so a system that has since moved does not change what this run was
         against. --}}
    <div style="padding:var(--s-3) 0">
        <div class="card__stats" style="padding:0;border:0">
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.coordinates') }}</span>
                <b class="num">{{ $run->position() }}</b>
            </div>
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.address') }}</span>
                <b><x-address :address="$run->address" /></b>
            </div>
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.type') }}</span>
                <b>{{ $system?->type->label() ?? '—' }}</b>
            </div>
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.name') }}</span>
                @if ($system)
                    <b>{{ $system->displayName() }}</b>
                @else
                    {{-- Burned or relocated out from under them. Nothing here cancels the run — that is
                         the simulator's, and it is why the row carries an address rather than a foreign key. --}}
                    <span class="tag tag--warn">{{ Text::get('cyberspace.run.ended.cancelled') }}</span>
                @endif
            </div>
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.run_type') }}</span>
                <b>{{ $run->purpose->label() }}@if ($run->backdoorKind()) · {{ $run->backdoorKind()->label() }}@endif</b>
            </div>
            {{-- THIS CHARACTER'S STANDING INSTRUCTIONS, set once from the map. Zero is off. --}}
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.threshold_trace') }}</span>
                <b class="num">{{ $character->egress_trace_at > 0 ? $character->egress_trace_at.'%' : Text::get('cyberspace.run.overview.threshold_off') }}</b>
            </div>
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.overview.threshold_si') }}</span>
                <b class="num">{{ $character->egress_si_at > 0 ? $character->egress_si_at.'%' : Text::get('cyberspace.run.overview.threshold_off') }}</b>
            </div>
        </div>
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

{{-- WHAT IS FIGHTING, drawn from the RUN and not from the system: integrity is being ground down, and
     the catalogue says what an ICE is rather than what this fight has left of it. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.ice') }}</h2>
    </div>

    <div style="padding:var(--s-3) 0">
        @if ($run->inAFight())
            @php($ice = $run->ice)
            <div class="card__stats" style="padding:0;border:0">
                <div class="card__stat">
                    <span>{{ Text::get('cyberspace.run.ice_grade') }}</span>
                    <span class="tag tag--crit">{{ $ice->name }}</span>
                </div>
                <div class="card__stat">
                    <span>{{ Text::get('cyberspace.run.ice_integrity') }}</span>
                    <b class="num">{{ $run->ice_integrity }} / {{ $ice->integrity }}</b>
                </div>
            </div>

            {{-- The kit's own meter, driven by --v the way every other one is. It is the ICE's health and
                 not the player's, so it empties as things go WELL — `--fill` says which way to read it.
                 A <div>, like every other fill: an inline <span> has no height and draws nothing. --}}
            <div class="meter meter--lg" style="--v:{{ round($run->ice_integrity / max(1, $ice->integrity), 3) }};--fill:var(--crit);margin-top:var(--s-2)">
                <div class="meter__fill"></div>
            </div>
        @else
            <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.run.ice_none') }}</p>
        @endif
    </div>
</div>

{{-- WRITTEN ONCE, SHOWN TWO WAYS. The kit clones this into the floating
     inspector on hover and into a modal on click. --}}
<template id="egress-working">
    <div class="card" style="max-width:min(420px, 100%)">
        <div class="card__hd">
            <span class="card__title">{{ Text::get('cyberspace.run.evasion') }}</span>
            <div class="spacer"></div>
            <span class="card__meta num">{{ rtrim(rtrim(number_format($egress->value, 1), '0'), '.') }}</span>
        </div>

        <div class="card__bd" style="padding-bottom:0">
            <div class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.run.evasion_pool') }}</div>
        </div>
        <div class="card__stats">
            @foreach ($egress->terms as $attribute => $value)
                <div class="card__stat">
                    <span>{{ Text::get('character.names.'.$attribute) }}</span>
                    <b class="num">{{ rtrim(rtrim(number_format($value, 1), '0'), '.') }}</b>
                </div>
            @endforeach
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.evasion_pool_sum', [
                    'sum' => rtrim(rtrim(number_format(array_sum($egress->terms), 1), '0'), '.'),
                    'divisor' => rtrim(rtrim(number_format($egress->divisor, 1), '0'), '.'),
                ]) }}</span>
                <b class="num">{{ rtrim(rtrim(number_format($egress->base, 1), '0'), '.') }}</b>
            </div>
        </div>

        <div class="card__bd" style="padding-bottom:0">
            <div class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.run.evasion_modifiers') }}</div>
        </div>
        @if ($egress->steps === [])
            <div class="card__bd" style="padding-top:var(--s-2)">
                <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.run.evasion_unmodified') }}</p>
            </div>
        @else
            {{-- ONE LINE PER LAYER, NOT PER MODIFIER, because percentages are
                 summed and applied once — a line each would imply a
                 compounding that does not happen. The sources are named
                 instead, which is how a skill's own title appears here
                 without this card knowing skills exist. --}}
            <div class="card__stats">
                @foreach ($egress->steps as $step)
                    <div class="card__stat">
                        <span>{{ $step->sources === [] ? $step->op : implode(', ', $step->sources) }}</span>
                        <b class="num">{{ $step->delta() >= 0 ? '+' : '−' }}{{ rtrim(rtrim(number_format(abs($step->delta()), 1), '0'), '.') }}</b>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="card__bd" style="padding-bottom:0">
            <div class="micro" style="color:var(--ink-2)">{{ Text::get('cyberspace.run.evasion_against') }}</div>
        </div>
        <div class="card__stats">
            <div class="card__stat">
                <span>{{ $egress->grade === null ? Text::get('cyberspace.run.evasion_ice_none') : Text::get('cyberspace.run.evasion_grade', ['grade' => $egress->grade]) }}</span>
                <b class="num">{{ $egress->gradeDifficulty }}</b>
            </div>
            <div class="card__stat">
                <span>{{ $egress->iceLevel === null ? Text::get('cyberspace.run.evasion_ice_none') : Text::get('cyberspace.run.evasion_ice', ['level' => $egress->iceLevel]) }}</span>
                <b class="num">{{ $egress->iceLevel === null ? '—' : '+'.($egress->difficulty - $egress->gradeDifficulty) }}</b>
            </div>
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.evasion_difficulty') }}</span>
                <b class="num">{{ $egress->difficulty }}</b>
            </div>
            @if ($egress->isSnared())
                <div class="card__stat">
                    <span style="color:var(--warn)">{{ Text::get('cyberspace.run.evasion_snare', ['level' => $egress->snareLevel]) }}</span>
                    <b class="num" style="color:var(--warn)">−{{ Text::get('cyberspace.run.evasion_snare_cost', ['points' => $egress->snareCost()]) }}</b>
                </div>
            @endif
            <div class="card__stat">
                <span>{{ Text::get('cyberspace.run.evasion_result') }}</span>
                <b class="num">{{ $egress->percent() }}%</b>
            </div>
        </div>

    </div>
</template>

{{-- ACTIONS: the evasion that leaving rests on, and the buttons, in one panel.

     EVASION, AND THE WORKING BEHIND IT. The score is the capability; the chance on the Egress button
     is what it comes to against THIS system. Both are here because neither is the whole answer on its
     own — a good pool against a grade 9 is still a poor night. Pointing at the Egress button floats the
     explanation (the template above); clicking it is the egress, so there is no modal on top of that.

     ONE FORM AND ONE DOOR. Every action costs the same turn, which is the economy the whole run rests
     on — so they are one button each on one form rather than a route apiece. LEAVING IS ALWAYS ON THE
     LIST and never disabled: a run with no exit is a character deleted by a door they could not see. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    {{-- ON OR PAST A THRESHOLD: the next turn pulls them out whatever they press. The loud thing on the
         panel, because it is the one fact that changes what the buttons below will do. --}}
    @if ($thresholdBreached)
        <div class="hazard-stripe hazard-stripe--thin" role="img" aria-label="{{ Text::get('cyberspace.run.overview.threshold_hit') }}" title="{{ Text::get('cyberspace.run.overview.threshold_hit') }}"></div>
    @endif
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.actions') }}</h2>
        <div class="spacer"></div>
        @if ($egress->isSnared())
            <span class="tag tag--warn">{{ Text::get('cyberspace.run.evasion_snare', ['level' => $egress->snareLevel]) }}</span>
        @endif
    </div>

    <div style="padding:var(--s-3) 0 var(--s-2)">
        <div>
            <span class="micro" style="display:block;color:var(--ink-2);margin-bottom:var(--s-2)">
                {{ Text::get('cyberspace.run.evasion_result') }}: <span class="num" style="color:var(--ink-0)">{{ $egress->percent() }}%</span>
            </span>
            {{-- The meter is the CHANCE and not the score: a pool has no maximum to fill, and the number
                 a decision is made on is the one that gets the bar. Full width, on its own line. --}}
            <div class="meter meter--lg" style="--v:{{ round($egress->chance, 3) }};--fill:{{ $egress->isSnared() ? 'var(--warn)' : 'var(--jade)' }}">
                <div class="meter__fill"></div>
            </div>
        </div>

        <p class="micro" style="color:var(--ink-2);margin:var(--s-2) 0 0">
            <span class="num">{{ rtrim(rtrim(number_format($egress->value, 1), '0'), '.') }}</span>
            {{ Text::get('cyberspace.run.evasion') }}
            ·
            {{ Text::get('cyberspace.run.evasion_difficulty') }} <span class="num">{{ $egress->difficulty }}</span>
        </p>
    </div>

    {{-- A PULL IN PROGRESS. Plain text rather than a bar — the total it started against is not stored
         anywhere the bar could read, only what is still owed, and inventing a total to draw one would be
         a number nothing else in the run needs. --}}
    @if ($run->extracting_qb !== null)
        <p class="micro" style="color:var(--ink-2);margin:0 0 var(--s-2)">
            {{ Text::get('cyberspace.run.transferring', ['qb' => $run->extracting_qb]) }}
        </p>
    @endif

    <form method="POST" action="{{ route('run.act') }}" style="padding-bottom:var(--s-3)">
        @csrf
        <div class="row row--wrap" style="--gap:var(--s-2)">
            <button type="submit" name="action" value="attack" class="btn cut" @unless ($run->inAFight()) disabled @endunless>
                {{ Text::get('cyberspace.run.action.attack') }}
            </button>
            {{-- ONLY ON AN EXTRACTION RUN. Purpose decides what a core touch IS, and each of the three has
                 its own button: offering one on the wrong purpose would promise something the turn would
                 silently waste. --}}
            @if ($run->purpose === RunPurpose::Extraction)
                <button type="submit" name="action" value="extract" class="btn cut" @if ($run->inAFight()) disabled @endif>
                    {{ Text::get($run->extracting_qb !== null ? 'cyberspace.run.action.extract_continue' : 'cyberspace.run.action.extract') }}
                </button>
            @endif
            {{-- THE SAME RULE ON THE OTHER PURPOSE. The kind was chosen at the door and is permanent, so
                 the button says which one it is about to leave rather than asking again mid-fight. --}}
            @if ($run->purpose === RunPurpose::Backdoor)
                <button type="submit" name="action" value="install" class="btn cut"
                        @if ($run->backdoored || $run->inAFight()) disabled @endif>
                    {{ Text::get($run->backdoored ? 'cyberspace.run.action.install_done' : 'cyberspace.run.action.install', [
                        'kind' => $run->backdoorKind()?->label() ?? '',
                    ]) }}
                </button>
            @endif
            {{-- THE ONLY BUTTON THAT ENDS THE RUN BY WORKING, so it is the only one that wears the accent:
                 ember means destructive, and there is nothing in the game this word fits better. It cannot
                 be pressed twice — the system is gone and the run is over the moment it lands. --}}
            @if ($run->purpose === RunPurpose::Burn)
                <button type="submit" name="action" value="burn" class="btn btn--danger cut" @if ($run->inAFight()) disabled @endif>
                    {{ Text::get('cyberspace.run.action.burn') }}
                </button>
            @endif
            <button type="submit" name="action" value="hold" class="btn btn--ghost cut">
                {{ Text::get('cyberspace.run.action.hold') }}
            </button>
            <div class="spacer"></div>
            {{-- THE ODDS ARE ON THE BUTTON. `Simulator::egressChance()` is what answers here and what the
                 roll uses, so the number a player decides against is the number the game believes. --}}
            {{-- The working behind that number floats from here (hover, or keyboard focus) — it is the
                 button the number is on. Not on click: that is the egress itself. --}}
            <button type="submit" name="action" value="egress" class="btn btn--warn cut"
                    data-inspect="#egress-working" data-inspect-hover>
                {{ Text::get('cyberspace.run.egress_chance', ['chance' => $egress->percent()]) }}
            </button>
        </div>
        @if ($run->inAFight())
            <p class="micro" style="color:var(--warn);margin:var(--s-2) 0 0">
                {{ Text::get('cyberspace.run.core_guarded') }}
            </p>
        @elseif ($core !== null)
            <p class="micro" style="color:var(--ink-2);margin:var(--s-2) 0 0">
                {{ Text::get('cyberspace.run.core_chance', ['chance' => $core]) }}
                {{ Text::get('cyberspace.run.core_hint') }}
            </p>
        @endif
        @if ($egress->isSnared())
            <p class="micro" style="color:var(--warn);margin:var(--s-2) 0 0">{{ Text::get('cyberspace.run.egress_snared') }}</p>
        @endif
    </form>
</div>

{{-- THE ACCOUNT. A run is something a player watches happen, and the page is
     read from the top after every turn, so the log is NEWEST FIRST: the answer
     to the turn just taken is where the eye already is, and nobody scrolls
     down to find it. (It is stored oldest first and reversed here.) --}}
@if ($run->log)
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__hd"><h2>{{ Text::get('cyberspace.run.log_heading') }}</h2></div>

        <div style="padding:var(--s-3) 0">
            @foreach (array_reverse($run->log) as $line)
                <p class="micro" style="margin:0 0 var(--s-2);display:flex;flex-wrap:wrap;gap:var(--s-2);align-items:baseline;@if ($line['tone'] === 'crit') color:var(--crit) @elseif ($line['tone'] === 'ok') color:var(--ok) @else color:var(--ink-2) @endif">
                    @if (isset($line['turn']))
                        <span class="tag">{{ Text::get('cyberspace.run.turn', ['turn' => $line['turn']]) }}</span>
                    @endif
                    {{-- A line may open with a tag naming who did it ("ICE VII lands a code payload…"). --}}
                    <span>
                        @if (! empty($line['tag']))
                            <span class="tag tag--crit">{{ $line['tag'] }}</span>
                        @endif
                        {{ $line['text'] }}
                    </span>
                </p>
            @endforeach
        </div>
    </div>
@endif

@endsection
