@use('App\Combat\Fight\Side')
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
                <div class="meter" style="--v:{{ round($state->b->hp / max(1, $state->b->hpMax), 3) }};--fill:var(--crit)">
                    <div class="meter__fill"></div>
                </div>
            </div>

            @if ($state->b->statuses !== [])
                <div class="row row--wrap" style="--gap:var(--s-1)">
                    @foreach ($state->b->statuses as $status)
                        @php($kind = RiderKind::from($status['kind']))
                        <span class="tag">{{ $kind === RiderKind::Bleeding ? Text::get('combat.fight.screen.status_level', ['status' => $kind->label(), 'level' => $status['amount']]) : ($kind === RiderKind::Edge ? $kind->label() : Text::get('combat.fight.screen.status_for', ['status' => $kind->label(), 'turns' => $status['turns']])) }}</span>
                    @endforeach
                </div>
            @endif

            {{-- What it carries and what it wears: the weapons it can use, and its resistances (percent off per
                 damage type, and extra by body part). Read off the fight, which copied them when it opened. --}}
            @php($foeArms = array_filter(['ranged' => $state->b->ranged, 'close' => $state->b->melee]))
            @php($foeResist = array_filter((array) $fight->resistances, fn ($v): bool => (int) $v !== 0))
            @php($foeSlots = collect((array) $fight->slot_resistances)->map(fn ($types) => array_filter((array) $types, fn ($v): bool => (int) $v !== 0))->filter())
            @if ($foeArms !== [])
                <div class="row row--wrap micro" style="--gap:var(--s-2)">
                    <span>{{ Text::get('combat.fight.screen.foe_weapons') }}</span>
                    @foreach ($foeArms as $range => $arm)
                        {{-- Hover floats the card, a click or tap opens it as a modal (ui-kit.js, data-inspect). --}}
                        <span class="tag" data-inspect="#foe-arm-{{ $range }}" tabindex="0" style="cursor:help">{{ Text::get('combat.fight.screen.range.'.$range) }}: {{ $arm->name }}</span>
                        @php($round = $arm->spec())
                        <template id="foe-arm-{{ $range }}">
                            <div class="item-card">
                                <div class="item-card__hd">
                                    <span class="stack" style="--gap:5px;min-width:0">
                                        <span class="item-card__name">{{ $arm->name }}</span>
                                        <span class="row row--wrap" style="--gap:var(--s-2)"><span class="tag tag--warn">{{ $arm->class->label() }}</span></span>
                                    </span>
                                </div>
                                <div class="item-card__stats">
                                    <div class="item-card__stat"><span>{{ Text::get('combat.fight.screen.arm_rate') }}</span><b>{{ $arm->rate }}</b></div>
                                    @if ($arm->magazine > 0)
                                        <div class="item-card__stat"><span>{{ Text::get('combat.fight.screen.arm_magazine') }}</span><b>{{ $arm->magazine }}</b></div>
                                    @endif
                                    @if ($round !== null)
                                        <div class="item-card__stat"><span>{{ Text::get('combat.fight.screen.arm_round') }}</span><b>{{ $round->name }}</b></div>
                                        <div class="item-card__stat"><span>{{ Text::get('combat.fight.screen.arm_damage') }}</span><b>{{ round($round->damage, 1) }}</b></div>
                                        <div class="item-card__stat"><span>{{ Text::get('combat.fight.screen.arm_types') }}</span><b>{{ collect($round->profile)->map(fn ($share, $type) => \App\Stats\DamageOrigin::from($type)->label().' '.$share.'%')->implode(', ') }}</b></div>
                                        @if ($round->penetration !== 0)
                                            <div class="item-card__stat"><span>{{ Text::get('combat.fight.screen.arm_penetration') }}</span><b>{{ $round->penetration }}%</b></div>
                                        @endif
                                        @foreach ($round->riders as $rider)
                                            <div class="item-card__stat"><span>{{ RiderKind::from($rider['kind'])->label() }}</span><b>{{ $rider['chance'] }}%</b></div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </template>
                    @endforeach
                </div>
            @endif
            @if ($foeResist !== [] || $foeSlots->isNotEmpty())
                <div class="row row--wrap micro" style="--gap:var(--s-2)">
                    <span>{{ Text::get('combat.fight.screen.foe_armour') }}</span>
                    @foreach ($foeResist as $type => $percent)
                        <span class="tag">{{ \App\Stats\DamageOrigin::from($type)->label() }} {{ $percent > 0 ? '+' : '' }}{{ $percent }}%</span>
                    @endforeach
                    @foreach ($foeSlots as $slot => $types)
                        @foreach ($types as $type => $percent)
                            <span class="tag">{{ \App\Combat\ArmourSlot::from($slot)->label() }} · {{ \App\Stats\DamageOrigin::from($type)->label() }} {{ $percent > 0 ? '+' : '' }}{{ $percent }}%</span>
                        @endforeach
                    @endforeach
                </div>
            @endif

            {{-- WHERE EVERYBODY STANDS. 0 is the meeting point: you stand out from it on the left, they on the right, and the gap
                 is the two added. Ranged weapons work whenever the gap is above 0. Each side can get away once it is far
                 enough out, by its own measure: the ticks at either end are where. Cells are the steps, one per position. --}}
            @php($reach = \App\Combat\Fight\FightState::reach())
            @php($x = fn (float $p): float => round(($p + $reach + 0.5) / ($reach * 2 + 1) * 100, 2))
            @php([$you, $foe] = [-$state->offset(Side::A), $state->offset(Side::B)])
            <div class="stack" style="--gap:var(--s-1)">
                <div class="meter meter--lg" style="--fill:var(--ink-3)" role="img"
                     aria-label="{{ Text::get('combat.fight.screen.bar.aria', ['you' => -$you, 'foe' => $foe, 'gap' => $state->distance]) }}">
                    <div class="meter__fill" style="position:absolute;top:0;left:{{ $x($you) }}%;width:{{ $x($foe) - $x($you) }}%;transform:none;transition:none"></div>
                    <span class="meter__mark" style="--x:{{ $x(0) / 100 }};--mark:var(--ink-2)"></span>
                    <span class="meter__mark" style="--x:{{ $x(-($state->a->escapeBeyond() + 0.5)) / 100 }};--mark:var(--ink-2)"></span>
                    <span class="meter__mark" style="--x:{{ $x($state->b->escapeBeyond() + 0.5) / 100 }};--mark:var(--ink-2)"></span>
                    <span class="meter__mark" style="--x:{{ $x($you) / 100 }};--mark:var(--ok);width:6px;margin-left:-3px"></span>
                    <span class="meter__mark" style="--x:{{ $x($foe) / 100 }};--mark:var(--crit);width:6px;margin-left:-3px"></span>
                </div>
                <div class="row micro" style="--gap:var(--s-2);justify-content:space-between;color:var(--ink-3)">
                    <span>{{ Text::get('combat.fight.screen.bar.you_escape') }}</span>
                    <span>{{ Text::get('combat.fight.screen.bar.meeting') }}</span>
                    <span>{{ Text::get('combat.fight.screen.bar.they_escape') }}</span>
                </div>
            </div>

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
                    <span class="num">{{ $character->{$resource} }}</span>
                </div>
                <div class="meter" data-hot-low style="--v:{{ round($character->resourceRatio($resource), 3) }};--fill:var(--ok)">
                    <div class="meter__fill"></div>
                </div>
            </div>
        @endforeach
        @if ($state->a->statuses !== [])
            <div class="row row--wrap" style="--gap:var(--s-1);grid-column:1/-1">
                @foreach ($state->a->statuses as $status)
                    @php($kind = RiderKind::from($status['kind']))
                    <span class="tag tag--crit">{{ $kind === RiderKind::Bleeding ? Text::get('combat.fight.screen.status_level', ['status' => $kind->label(), 'level' => $status['amount']]) : ($kind === RiderKind::Edge ? $kind->label() : Text::get('combat.fight.screen.status_for', ['status' => $kind->label(), 'turns' => $status['turns']])) }}</span>
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
                    <button type="button" class="link" data-open="#chance-attack">{{ Text::get('combat.fight.screen.chance') }}</button>
                    <span class="num">{{ implode(' · ', array_map(fn (float $c): string => round($c * 100).'%', $attack)) }}</span>
                    @if (count($attack) > 1) — {{ Text::get('combat.fight.screen.shots', ['count' => count($attack)]) }} @endif
                </span>
            </form>
        @else
            <span class="tag">{{ Text::get('combat.fight.screen.nothing_to_attack') }}</span>
        @endif

        {{-- What is on the belt and allowed in a fight: fight-only drugs, and consumables their author allowed. Using one IS
             the turn, and is a check (defence against their pursuit): a fumble spends the turn and keeps the item. A drug
             works from the next round; a consumable does its work at once. --}}
        @foreach ($uses as $use)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="use">
                <input type="hidden" name="round" value="{{ $use['id'] }}">
                <button type="submit" class="btn cut" style="justify-self:start" @disabled(! $use['ready'])>{{ Text::get('combat.fight.screen.use', ['item' => $use['name']]) }}</button>
                <span class="micro" style="color:var(--ink-3)">
                    <button type="button" class="link" data-open="#chance-use">{{ Text::get('combat.fight.screen.chance') }}</button>
                    <span class="num">{{ round($use_chance * 100) }}%</span> —
                    @if ($use['drug'])
                        {{ Text::get('combat.fight.screen.use_hint', ['turns' => $use['turns'], 'quantity' => $use['quantity']]) }}
                    @else
                        {{ Text::get('combat.fight.screen.use_consumable_hint', ['effects' => $use['effects'], 'quantity' => $use['quantity']]) }}
                    @endif
                </span>
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
                <span class="micro" style="color:var(--ink-3)"><button type="button" class="link" data-open="#chance-close_in">{{ Text::get('combat.fight.screen.chance') }}</button> <span class="num">{{ round($closing * 100) }}%</span> — {{ Text::get('combat.fight.screen.close_in_hint') }}</span>
            </form>
        @endif

        @if ($can_create_distance)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="create_distance">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.create_distance') }}</button>
                <span class="micro" style="color:var(--ink-3)"><button type="button" class="link" data-open="#chance-create_distance">{{ Text::get('combat.fight.screen.chance') }}</button> <span class="num">{{ round($flight * 100) }}%</span> — {{ Text::get('combat.fight.screen.create_distance_hint', ['max' => \App\Combat\Fight\FightState::reach()]) }}</span>
            </form>
        @endif

        {{-- At Close only. Headbutt: a bare-hands blow whatever is in the hand, a little harder, and it can hurt you. Takedown: a check
             (muscle and motor against their defence); a success leaves them dazed and gives you the upper hand, a failure costs your next turn. --}}
        @if ($can_headbutt)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="headbutt">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.headbutt') }}</button>
                <span class="micro" style="color:var(--ink-3)"><button type="button" class="link" data-open="#chance-headbutt">{{ Text::get('combat.fight.screen.chance') }}</button> <span class="num">{{ round($headbutt_chance * 100) }}%</span> — {{ Text::get('combat.fight.screen.headbutt_hint', ['bonus' => config('combat.headbutt.damage_bonus'), 'recoil' => config('combat.headbutt.recoil')]) }}</span>
            </form>
        @endif

        @if ($can_takedown)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="takedown">
                <button type="submit" class="btn cut" style="justify-self:start">{{ Text::get('combat.fight.screen.takedown') }}</button>
                <span class="micro" style="color:var(--ink-3)"><button type="button" class="link" data-open="#chance-takedown">{{ Text::get('combat.fight.screen.chance') }}</button> <span class="num">{{ round($takedown_chance * 100) }}%</span> — {{ Text::get('combat.fight.screen.takedown_hint') }}</span>
            </form>
        @endif

        @if ($can_disengage)
            <form method="POST" action="{{ route('fight.act') }}" class="stack" style="--gap:var(--s-1)">
                @csrf
                <input type="hidden" name="action" value="disengage">
                <button type="submit" class="btn btn--ghost cut" style="justify-self:start">{{ Text::get('combat.fight.screen.disengage') }}</button>
                <span class="micro" style="color:var(--ink-3)"><button type="button" class="link" data-open="#chance-create_distance">{{ Text::get('combat.fight.screen.chance') }}</button> <span class="num">{{ round($flight * 100) }}%</span> — {{ Text::get('combat.fight.screen.disengage_hint', ['beyond' => $state->a->escapeBeyond()]) }}</span>
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


{{-- WHY EACH CHANCE IS WHAT IT IS (ChanceExplainer). One dialog per roll; Disengage reads the Create distance one. --}}
@foreach ($explain as $key => $why)
    <dialog class="modal" id="chance-{{ $key }}">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('combat.fight.chance.title.'.$key) }}</span>
            <div class="spacer"></div>
            <button type="button" class="btn btn--sm btn--ghost" data-close>✕</button>
        </div>
        <div class="panel__bd stack" style="--gap:var(--s-3)">
            @php($odds = $key === 'attack' ? $attack : [match ($key) { 'close_in' => $closing, 'use' => $use_chance, 'headbutt' => $headbutt_chance, 'takedown' => $takedown_chance, default => $flight }])
            <div class="row row--wrap" style="--gap:var(--s-2)">
                <span>{{ Text::get('combat.fight.chance.chance') }}</span>
                <b class="num">{{ implode(' · ', array_map(fn (float $c): string => round($c * 100, 1).'%', $odds)) }}</b>
            </div>

            <div class="item-card__stats">
                <div class="item-card__stat"><span>{{ Text::get('combat.fight.chance.yours', ['stat' => Text::get('combat.fight.chance.stats.'.$why['yours_label'])]) }}</span><b class="num">{{ round($why['yours'], 1) }}</b></div>
                <div class="item-card__stat"><span>{{ Text::get('combat.fight.chance.base') }}</span><b class="num">{{ round($why['base'], 1) }}</b></div>
                @foreach ($why['steps'] as $step)
                    <div class="item-card__stat">
                        <span>{{ Text::get('combat.fight.chance.op.'.$step['op']) }} @if ($step['sources'] !== []) <span class="micro" style="color:var(--ink-3)">({{ implode(', ', $step['sources']) }})</span> @endif</span>
                        <b class="num">{{ $step['op'] === 'multiplier' ? '×'.round($step['amount'], 2) : ($step['op'] === 'override' ? '= '.round($step['amount'], 1) : ($step['amount'] >= 0 ? '+' : '').round($step['amount'], 1).($step['op'] === 'percentage_of_base' ? '%' : '')) }}</b>
                    </div>
                @endforeach
                @foreach ($why['extras'] as $extra)
                    <div class="item-card__stat"><span>{{ Text::get('combat.fight.chance.extra.'.$extra['label']) }}</span><b class="num">{{ ($extra['amount'] >= 0 ? '+' : '').round($extra['amount'], 1) }}</b></div>
                @endforeach
                <div class="item-card__stat"><span>{{ Text::get('combat.fight.chance.theirs', ['stat' => Text::get('combat.fight.chance.stats.'.$why['theirs_label'])]) }}</span><b class="num">{{ round($why['theirs'], 1) }}</b></div>
                <div class="item-card__stat"><span>{{ Text::get('combat.fight.chance.margin') }}</span><b class="num">{{ ($why['margin'] >= 0 ? '+' : '').round($why['margin'], 1) }}</b></div>
            </div>
            @if ($why['edge'] ?? false)
                <p class="micro" style="color:var(--ink-3);margin:0">{{ Text::get('combat.fight.chance.edge') }}</p>
            @endif
            @if ($key === 'takedown')
                <p class="micro" style="color:var(--ink-3);margin:0">{{ Text::get('combat.fight.chance.takedown_cap', ['cap' => round((float) config('combat.takedown.cap') * 100)]) }}</p>
            @endif
            @if ($key === 'attack' && count($attack) > 1)
                <p class="micro" style="color:var(--ink-3);margin:0">{{ Text::get('combat.fight.chance.burst', ['falloff' => round((float) config('combat.burst_falloff') * 100)]) }}</p>
            @endif
        </div>
    </dialog>
@endforeach

@endsection
