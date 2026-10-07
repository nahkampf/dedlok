@use('App\Combat\RiderKind')
@use('App\Support\Text')

@extends('layouts.app')
@section('title', Text::get('combat.fight.screen.title'))

@section('content')

{{-- THE ONLY SCREEN A FIGHTER CAN REACH. EnsureFightIsResolved sends every other request here, so this
     page is the whole of the fight: who you are against, where you stand, what you can do and what has
     happened. Every chance on a button is the one the next roll will use (Fights::options()). --}}

<h1>{{ Text::get('combat.fight.screen.heading') }}</h1>

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__bd row row--wrap" style="--gap:var(--s-5);align-items:flex-start">
        {{-- Found by convention (public/images/npcs/<id>.jpg). An opponent deleted mid-fight has no
             picture to show, and a missing one renders nothing rather than a broken one. --}}
        @if ($fight->npc_id !== null)
            <x-entity-image kind="npcs" :id="$fight->npc_id" :alt="$fight->opponent_name" portrait />
        @endif

        <div class="stack" style="--gap:var(--s-3);flex:1 1 min(260px,100%);min-width:0">
            <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                <span style="font-size:var(--fs-lg);font-weight:650">{{ $fight->opponent_name }}</span>
                <span class="tag tag--crit">{{ Text::get('combat.fight.screen.grade', ['grade' => $fight->grade]) }}</span>
            </div>

            <div class="stack" style="--gap:var(--s-1)">
                <div class="row row--wrap micro" style="--gap:var(--s-2)">
                    <span>{{ Text::get('combat.fight.screen.hit_points') }}</span>
                    <span class="num">{{ $state->b->hp }} / {{ $state->b->hpMax }}</span>
                </div>
                <div class="meter" style="--v:{{ round($state->b->hp / max(1, (int) $state->b->hpMax), 3) }};--fill:var(--crit)">
                    <span class="meter__fill"></span>
                </div>
            </div>

            @if ($state->b->statuses !== [])
                <div class="row row--wrap" style="--gap:var(--s-1)">
                    @foreach ($state->b->statuses as $status)
                        <span class="tag">{{ Text::get('combat.fight.screen.status_for', ['status' => RiderKind::from($status['kind'])->label(), 'turns' => $status['turns']]) }}</span>
                    @endforeach
                </div>
            @endif

            <div class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                <span class="tag">{{ Text::get('combat.fight.screen.round', ['round' => $state->round]) }}</span>
                <span class="tag">{{ Text::get('combat.fight.screen.range.'.$state->range->value) }}</span>
                <span class="tag">{{ Text::get('combat.fight.screen.distance', ['distance' => $state->distance]) }}</span>
                @if ($state->first->value === 'b' && $state->round === 1)
                    <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.first') }}</span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- YOU. The topbar already carries your two dials on every screen; here they are drawn as bars with
     what is on you, because in a fight they are what you are watching. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__bd grid" style="grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));--gap:var(--s-3)">
        @foreach (['current_hitpoints' => 'hit_points', 'current_synaptic_integrity' => 'integrity'] as $resource => $label)
            <div class="stack" style="--gap:var(--s-1)">
                <div class="row row--wrap micro" style="--gap:var(--s-2)">
                    <span>{{ Text::get('combat.fight.screen.you') }} · {{ Text::get('combat.fight.screen.'.$label) }}</span>
                    <span class="num">{{ (int) $character->{$resource} }}</span>
                </div>
                <div class="meter" data-hot-low style="--v:{{ round($character->resourceRatio($resource), 3) }};--fill:var(--ok)">
                    <span class="meter__fill"></span>
                </div>
            </div>
        @endforeach
        @if ($state->a->statuses !== [])
            <div class="row row--wrap" style="--gap:var(--s-1);grid-column:1/-1">
                @foreach ($state->a->statuses as $status)
                    <span class="tag tag--crit">{{ Text::get('combat.fight.screen.status_for', ['status' => RiderKind::from($status['kind'])->label(), 'turns' => $status['turns']]) }}</span>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- WHAT YOU CAN DO. One form per button, each with its own hidden action, as the run briefing's
     intentions are. Every action takes the turn. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('combat.fight.screen.actions') }}</h2>
        <div class="spacer"></div>
        <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.actions_hint') }}</span>
    </div>

    <div class="stack" style="--gap:var(--s-3);padding:var(--s-3) 0">
        @php($weapon = $state->a->arm($state->range)?->name)
        @if ($can_attack)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="attack">
                <button type="submit" class="btn btn--primary cut" style="justify-self:start">{{ Text::get('combat.fight.screen.attack', ['weapon' => $weapon]) }}</button>
                <span class="micro" style="color:var(--ink-3)">
                    {{ Text::get('combat.fight.screen.chance') }}
                    <span class="num">{{ implode(' · ', array_map(fn (float $c): string => round($c * 100).'%', $attack)) }}</span>
                    @if (count($attack) > 1) — {{ Text::get('combat.fight.screen.shots', ['count' => count($attack)]) }} @endif
                </span>
            </form>
        @else
            <span class="tag">{{ Text::get('combat.fight.screen.nothing_to_attack') }}</span>
        @endif

        {{-- Fight-only drugs on the belt. Using one IS the turn: the opponent acts, and it works from the next round. --}}
        @foreach ($uses as $use)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="use">
                <input type="hidden" name="round" value="{{ $use['id'] }}">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.use', ['item' => $use['name']]) }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.use_hint', ['turns' => $use['turns'], 'quantity' => $use['quantity']]) }}</span>
            </form>
        @endforeach

        @if ($doses !== [])
            <span class="micro" style="color:var(--ink-3)">
                {{ Text::get('combat.fight.screen.in_effect') }}
                @foreach ($doses as $dose)
                    <span class="tag">{{ Text::get('combat.fight.screen.dose_left', ['item' => $dose['name'], 'turns' => $dose['turns']]) }}</span>
                @endforeach
            </span>
        @endif

        @foreach ($loads as $load)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="load">
                <input type="hidden" name="round" value="{{ $load['key'] }}">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.load', ['round' => $load['name']]) }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.load_hint', ['stock' => $load['stock']]) }}</span>
            </form>
        @endforeach

        @if ($can_close_in)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="close_in">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.close_in') }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.chance') }} <span class="num">{{ round($closing * 100) }}%</span> — {{ Text::get('combat.fight.screen.close_in_hint') }}</span>
            </form>
        @endif

        @if ($can_create_distance)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="create_distance">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.create_distance') }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.chance') }} <span class="num">{{ round($flight * 100) }}%</span> — {{ Text::get('combat.fight.screen.create_distance_hint', ['max' => config('combat.distance.max')]) }}</span>
            </form>
        @endif

        @if ($can_disengage)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="disengage">
                <button type="submit" class="btn btn--ghost cut" style="justify-self:start">{{ Text::get('combat.fight.screen.disengage') }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.chance') }} <span class="num">{{ round($flight * 100) }}%</span> — {{ Text::get('combat.fight.screen.disengage_hint', ['beyond' => config('combat.distance.flee_beyond')]) }}</span>
            </form>
        @endif

        @unless ($can_attack)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="brace">
                <button type="submit" class="btn btn--ghost cut" style="justify-self:start">{{ Text::get('combat.fight.screen.brace') }}</button>
                <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.fight.screen.brace_hint') }}</span>
            </form>
        @endunless
    </div>
</div>

{{-- THE ACCOUNT, newest last, the way a transcript reads. Each line carries the round it happened in. --}}
@if ($state->log !== [])
    <div class="panel panel--snug" style="margin-top:var(--s-4)">
        <div class="panel__hd"><h2>{{ Text::get('combat.fight.screen.log') }}</h2></div>

        <div class="stack" style="--gap:var(--s-1);padding:var(--s-3) 0">
            @foreach ($state->log as $line)
                <p class="micro row row--wrap" style="margin:0;--gap:var(--s-2);
                    @if ($line['tone'] === 'crit') color:var(--crit)
                    @elseif ($line['tone'] === 'ok') color:var(--ok)
                    @elseif ($line['tone'] === 'warn') color:var(--warn)
                    @else color:var(--ink-2) @endif">
                    <span class="tag">{{ Text::get('combat.fight.screen.round', ['round' => $line['round']]) }}</span>
                    <span style="min-width:0">{{ $line['text'] }}</span>
                </p>
            @endforeach
        </div>
    </div>
@endif

@endsection
