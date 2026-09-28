@use('App\Cyberspace\BackdoorKind')
@use('App\Cyberspace\RunPurpose')
@use('App\Support\Text')

@extends('layouts.app')
@section('title', Text::get('cyberspace.run.setup.title'))

@section('content')

{{-- THE BRIEFING.

     Everything decided on this screen is decided for good: the purpose is
     fixed at the door, a backdoor's kind is permanent once it lands, and the
     route is snapshotted the moment the run begins. So the screen's job is not
     to look confident — it is to say what is known and, louder, what is not.
     An unread machine, a deck cooking itself and no icebreaker loaded are the
     three things that turn a plan into a bad night. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.title') }}</h2>
        <div class="spacer"></div>
        <span class="tag">{{ $target->displayName() }}</span>
        <span class="micro"><x-address :address="$target->address" /></span>

        {{-- Who answers for it, and what they already make of you. The
             briefing is where a run is decided, so it is where the cost of
             being seen doing it belongs. --}}
        <x-standing-tag :entity="$target->owner" :standing="$standing" />
    </div>

    <div class="row row--wrap" style="--gap:var(--s-3);align-items:center;padding:var(--s-3) 0">
        <a class="btn btn--ghost btn--sm cut" href="{{ route('cyberspace.index') }}">
            &larr; {{ Text::get('cyberspace.map.title') }}
        </a>
        @if ($backdoor)
            <span class="tag tag--ok">{{ Text::get('cyberspace.run.setup.held') }}</span>
        @endif
    </div>
</div>

{{-- WHAT IS KNOWN, AND WHAT IS NOT.

     The unknowns are the product here. A blank column would read as "nothing
     there", which for an IDS is the opposite of the truth — so every fact is
     listed either way and an unread one says so in its own words. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.known') }}</h2>
        <div class="spacer"></div>
        @if (! $scan || ! $scan->hasFindings())
            <span class="tag tag--crit">{{ Text::get('cyberspace.run.setup.unknown') }}</span>
        @elseif ($scan->isComplete())
            <span class="tag tag--ok">{{ Text::get('cyberspace.run.setup.complete') }}</span>
        @else
            <span class="tag tag--warn">{{ Text::get('cyberspace.run.setup.partial') }}</span>
        @endif
    </div>

    @if (! $scan || ! $scan->hasFindings())
        <p class="micro" style="color:var(--ink-2);padding:var(--s-3) 0;margin:0">
            {{ Text::get('cyberspace.run.setup.unread') }}
        </p>
    @else
        <div class="card__stats" style="padding-bottom:var(--s-2)">
            @foreach ($facts as $fact)
                @php($value = $fact->readFrom($scan))
                <div class="card__stat">
                    <span>{{ $fact->label() }}</span>
                    <b @if ($value === null) style="color:var(--ink-2)" @endif>
                        {{ $value ?? Text::get('cyberspace.run.setup.unknown') }}
                    </b>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- THE DECK. Two things about it end runs: not meeting its own requirements,
     which refuses the run outright, and cooking a module, which ends one from
     the inside. Both are said here rather than discovered in there. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.deck') }}</h2>
        <div class="spacer"></div>
        @if ($totals)
            <span class="micro num" title="{{ Text::get('decks.ready.thermal') }}">{{ $totals->netThermalLoad() }} TU</span>
        @endif
    </div>

    <div style="padding:var(--s-3) 0">
        @if (! $totals)
            <p class="micro" style="color:var(--crit);margin:0">{{ Text::get('cyberspace.run.setup.deck_missing') }}</p>
        @else
            @unless ($ready)
                <p class="micro" style="color:var(--crit);margin:0 0 var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.not_ready') }}
                </p>
            @endunless

            @if ($thermal && $thermal->overLimit() !== [])
                <p class="micro" style="color:var(--crit);margin:0 0 var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.thermal_over', ['parts' => implode(', ', $thermal->overLimit())]) }}
                </p>
            @elseif ($thermal && $thermal->atRisk() !== [])
                <p class="micro" style="color:var(--warn);margin:0 0 var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.thermal_risk', ['parts' => implode(', ', $thermal->atRisk())]) }}
                </p>
            @else
                <p class="micro" style="color:var(--ink-2);margin:0 0 var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.thermal_safe') }}
                </p>
            @endif

            {{-- WHAT IS RUNNING, AND WHAT EACH THING IS. An icebreaker and a
                 decryptor are both "a program" right up until one of them is
                 the only thing between you and an ICE. --}}
            <div class="micro" style="color:var(--ink-2);margin-bottom:var(--s-2)">
                {{ Text::get('cyberspace.run.setup.running') }}
            </div>

            @if ($processes->isEmpty())
                <p class="micro" style="color:var(--warn);margin:0">
                    {{ Text::get('cyberspace.run.setup.running_none') }}
                </p>
            @else
                <div class="row row--wrap" style="--gap:var(--s-2)">
                    @foreach ($processes as $process)
                        <span class="tag" title="{{ $process->item?->displayName() }}">
                            {{ $process->item?->displayName() }}
                            @if ($process->item?->program)
                                · {{ $process->item->program->kind->label() }}
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>

{{-- THE ROUTE. Shown rather than chosen: every routing backdoor is free to
     include and hinders by its machine's grade, so there is nothing to pick.
     See App\Cyberspace\ChainBuilder. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.chain') }}</h2>
        <div class="spacer"></div>
        <span class="micro num">{{ $chain->count() }}/{{ $chainCap }}</span>
    </div>

    <div style="padding:var(--s-3) 0">
        @if ($chain->isEmpty())
            <p class="micro" style="color:var(--ink-2);margin:0">
                {{ $chainCap < 1
                    ? Text::get('cyberspace.run.setup.chain_untrained')
                    : Text::get('cyberspace.run.setup.chain_none') }}
            </p>
        @else
            <div class="trace" style="--v:0">
                @foreach ($chain as $hop)
                    <span class="trace__node" title="{{ $hop->displayName() }}">&#x25C7;</span>
                @endforeach
                <span class="trace__node trace__node--you" title="{{ $character->handle }}">&#x25C9;</span>
            </div>

            <div class="row row--wrap" style="--gap:var(--s-2);margin-top:var(--s-2)">
                @foreach ($chain as $hop)
                    <span class="tag" title="{{ $hop->displayName() }}">
                        {{ Text::get('cyberspace.run.setup.hop', ['grade' => $hop->grade]) }}
                    </span>
                @endforeach
            </div>

            <p class="micro" style="color:var(--ink-2);margin:var(--s-2) 0 0">
                {{ Text::get('cyberspace.run.setup.chain_cost') }}
            </p>
        @endif
    </div>
</div>

{{-- THE LOADOUT AND THE INTENTIONS, ONE FORM.

     Run-only software is started by the run and by nothing else, so it is
     chosen here and cannot be changed once inside. One icebreaker, by radio,
     because only one may run; anything else run-only, by checkbox. Each
     intention is its own submit button carrying its purpose, and a backdoor's
     kind rides on the button's formaction, so the loadout goes with whichever
     one is pressed. An unbuilt intention says so with a tag rather than being
     absent — a screen that grows buttons as features land is a screen nobody
     learns. --}}
<form method="POST" action="{{ route('run.start') }}">
    @csrf

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.loadout') }}</h2>
    </div>

    <div style="padding:var(--s-3) 0;display:grid;gap:var(--s-3)">
        @php([$icebreakers, $others] = $loadout)

        @if ($icebreakers->isEmpty())
            <p class="micro" style="color:var(--warn);margin:0">
                {{ Text::get('cyberspace.run.setup.no_icebreaker') }}
            </p>
        @else
            <fieldset class="stack" style="--gap:var(--s-2);border:0;padding:0;margin:0">
                <legend class="micro" style="color:var(--ink-2);margin-bottom:var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.loadout_icebreaker') }}
                </legend>
                <label class="check check--radio">
                    <input type="radio" name="icebreaker" value="" @checked($icebreakers->pluck('id')->intersect($chosen)->isEmpty())>
                    <span class="check__box"></span>
                    <span>{{ Text::get('cyberspace.run.setup.loadout_none') }}</span>
                </label>
                @foreach ($icebreakers as $row)
                    <label class="check check--radio">
                        <input type="radio" name="icebreaker" value="{{ $row->id }}" @checked($row->id === $icebreakers->pluck('id')->intersect($chosen)->first())>
                        <span class="check__box"></span>
                        <span>
                            {{ $row->item->displayName() }}
                            <span class="micro num" style="color:var(--ink-2)">
                                · {{ Text::get('programs.costs.compute', ['value' => $row->item->program->qflops_cost]) }}
                                · {{ Text::get('programs.costs.memory', ['value' => $row->item->program->memory_cost]) }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
        @endif

        @if ($others->isNotEmpty())
            <fieldset class="stack" style="--gap:var(--s-2);border:0;padding:0;margin:0">
                <legend class="micro" style="color:var(--ink-2);margin-bottom:var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.loadout_other') }}
                </legend>
                @foreach ($others as $row)
                    <label class="check">
                        <input type="checkbox" name="programs[]" value="{{ $row->id }}" @checked(in_array($row->id, $chosen, true))>
                        <span class="check__box"></span>
                        <span>
                            {{ $row->item->displayName() }}
                            <span class="micro num" style="color:var(--ink-2)">
                                · {{ $row->item->program->kind->label() }}
                                · {{ Text::get('programs.costs.compute', ['value' => $row->item->program->qflops_cost]) }}
                                · {{ Text::get('programs.costs.memory', ['value' => $row->item->program->memory_cost]) }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
        @endif

        <p class="micro" style="color:var(--ink-2);margin:0">
            {{ Text::get('cyberspace.run.setup.loadout_hint') }}
        </p>
    </div>
</div>

<div class="panel panel--snug" style="margin-top:var(--s-4);margin-bottom:var(--s-6)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.intent') }}</h2>
    </div>

    @if ($lockedDown)
        <p class="micro" style="color:var(--warn);padding:var(--s-3) 0;margin:0">
            {{ Text::get('cyberspace.run.refused.locked_down') }}
        </p>
    @else
        <div style="padding:var(--s-3) 0;display:grid;gap:var(--s-3)">
            @foreach ([
                ['intent' => 'extraction', 'purpose' => RunPurpose::Extraction, 'kind' => null],
                ['intent' => 'siphon', 'purpose' => RunPurpose::Backdoor, 'kind' => BackdoorKind::Siphon],
                ['intent' => 'routing', 'purpose' => RunPurpose::Backdoor, 'kind' => BackdoorKind::Routing],
                ['intent' => 'burn', 'purpose' => RunPurpose::Burn, 'kind' => null],
            ] as $option)
                <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    <button type="submit" class="btn cut" style="min-width:min(200px,100%)"
                            name="purpose" value="{{ $option['purpose']->value }}"
                            @if ($option['kind']) formaction="{{ route('run.start', ['backdoor_kind' => $option['kind']->value]) }}" @endif
                            @unless ($ready) disabled @endunless>
                        {{ Text::get('cyberspace.run.setup.intent_'.$option['intent']) }}
                    </button>
                    <span class="micro" style="color:var(--ink-2);flex:1;min-width:min(240px,100%)">
                        {{ Text::get('cyberspace.run.setup.intent_'.$option['intent'].'_hint') }}
                        @if ($option['purpose'] === RunPurpose::Backdoor && $backdoor)
                            {{ Text::get('cyberspace.run.setup.held_kind', ['kind' => $backdoor->kind->label()]) }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>

</form>

@endsection
