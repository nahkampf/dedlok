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
{{-- A way back, and nothing more: the screen opens on the system itself. --}}
<p style="margin:var(--s-4) 0 0">
    <a class="btn btn--ghost btn--sm cut" href="{{ route('cyberspace.index') }}">
        &larr; {{ Text::get('cyberspace.map.title') }}
    </a>
</p>

{{-- WILL THE FIRST TURN PULL YOU OUT? Synaptic Integrity already under the mark you left for yourself: whatever you press, the
     run egresses before it happens. The same test the run makes (Simulator::siBelowThreshold), said before the door. --}}
@if ($autoEgress)
    <div class="callout callout--warn" style="margin-top:var(--s-4)" role="alert">
        {{ Text::get('cyberspace.run.setup.auto_egress', [
            'si' => round($character->current_synaptic_integrity / max(1, $character->maximumFor('current_synaptic_integrity')) * 100),
            'at' => $character->egress_si_at,
        ]) }}
    </div>
@endif

{{-- THE SYSTEM, AS THE SCANNER WINDOW SHOWS IT.

     The unknowns are the product here. A blank column would read as "nothing there", which for an
     IDS is the opposite of the truth — so every fact is listed either way and an unread one says so
     in its own words, in the same rows and the same boxes the scanner uses (<x-scan-facts>). A file
     that is not complete wears a thin hazard stripe along its top: the one loud thing on the screen. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    @if (! $scan || ! $scan->isComplete())
        <div class="hazard-stripe hazard-stripe--thin" role="img"
             aria-label="{{ Text::get($scan && $scan->hasFindings() ? 'cyberspace.run.setup.partial' : 'cyberspace.run.setup.unknown') }}"></div>
    @endif

    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.known') }}</h2>
        <div class="spacer"></div>
        <span class="tag">{{ $target->displayName() }}</span>
        <span class="micro"><x-address :address="$target->address" /></span>

        {{-- Who answers for it, and what they already make of you. The briefing is where a run is
             decided, so it is where the cost of being seen doing it belongs. --}}
        <x-standing-tag :entity="$target->owner" :standing="$standing" />

        @if (! $scan || ! $scan->hasFindings())
            <span class="tag tag--crit">{{ Text::get('cyberspace.run.setup.unknown') }}</span>
        @elseif ($scan->isComplete())
            <span class="tag tag--ok">{{ Text::get('cyberspace.run.setup.complete') }}</span>
        @else
            <span class="tag tag--warn">{{ Text::get('cyberspace.run.setup.partial') }}</span>
        @endif
    </div>

    <div style="padding:var(--s-3) 0">
        @if ($backdoor)
            <p style="margin:0 0 var(--s-3)"><span class="tag tag--ok">{{ Text::get('cyberspace.run.setup.held') }}</span></p>
        @endif

        @if (! $scan || ! $scan->hasFindings())
            <p class="micro" style="color:var(--ink-2);margin:0 0 var(--s-3)">
                {{ Text::get('cyberspace.run.setup.unread') }}
            </p>
        @endif

        <x-scan-facts :facts="$overview" />

        {{-- THE ASSESSOR'S OVERALL, while one runs on the deck. The figure for the loadout as ticked, worked out by the
             server on every tick (`run.assess`); the briefing's script only shows what comes back. --}}
        @if ($assess)
            <p class="micro" style="color:var(--ink-2);margin:var(--s-3) 0 0">
                {{ Text::get('assessor.overall') }} <b class="num" data-assess-readout data-assess-url="{{ route('run.assess') }}">{!! $assess !!}</b>
            </p>
        @endif
    </div>
</div>

{{-- THE DECK. Two things about it end runs: not meeting its own requirements, which refuses the run
     outright, and cooking a module, which ends one from the inside. Both are said here rather than
     discovered in there — and the bars are the deck screen's own (<x-deck-vitals>). --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.deck') }}</h2>
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
            @endif

            <x-deck-vitals :totals="$totals" :thermal="$thermal" :spent="$spent" />

            {{-- WHAT IS RUNNING, AND WHAT EACH THING IS. An icebreaker and a decryptor are both "a
                 program" right up until one of them is the only thing between you and an ICE.

                 NOTHING WHEN NOTHING IS RUNNING. There used to be a warning here that you could neither
                 run nor scan, and it was wrong on both counts: a run's icebreakers are started by the run
                 (the loadout above), and nothing is scanned from this screen. An empty list says nothing. --}}
            @unless ($processes->isEmpty())
                <div class="micro" style="color:var(--ink-2);margin:var(--s-3) 0 var(--s-2)">
                    {{ Text::get('cyberspace.run.setup.running') }}
                </div>

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
            @endunless
        @endif
    </div>
</div>

{{-- SUSPEND BACKGROUND PROCESSES. A ticked one is stopped when the run begins and started again when it ends; scanners (Analysers) come ticked; ticking only
     gives its compute and memory back to the sums on this page. A Compressor the deck's storage needs cannot be ticked. An accordion closed until wanted; its inputs belong to the run form by `form=`, not by sitting
     inside it. --}}
<details class="panel panel--snug" style="margin-top:var(--s-4)">
    <summary class="panel__hd"><h2>{{ Text::get('cyberspace.run.setup.suspend') }}</h2></summary>

    <div style="padding:var(--s-3) 0;display:grid;gap:var(--s-3)">
        @if ($suspendable->isEmpty())
            <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.run.setup.suspend_none') }}</p>
        @else
            <fieldset class="stack" style="--gap:var(--s-2);border:0;padding:0;margin:0">
                @foreach ($suspendable as $row)
                    @php($program = $row->item->program)
                    @php($blocked = in_array($row->id, $suspendBlocked, true))
                    <label class="check">
                        <input type="checkbox" name="suspend[]" value="{{ $row->id }}" form="run-form"
                               data-gain-qflops="{{ \App\Support\Qb::tenths($program->qflops_cost) }}" data-gain-memory="{{ \App\Support\Qb::tenths($program->memory_cost) }}"
                               @if ($blocked) disabled @elseif ($program->kind === \App\Programs\ProgramKind::Analyser) checked @endif>
                        <span class="check__box"></span>
                        <span>
                            {{ $row->item->displayName() }}
                            <span class="micro num" style="display:block;color:var(--ink-2)">
                                {{ Text::get('programs.costs.compute', ['value' => \App\Support\Qb::of($program->qflops_cost)]) }}
                                · {{ Text::get('programs.costs.memory', ['value' => \App\Support\Qb::of($program->memory_cost)]) }}
                            </span>
                            @if ($blocked)
                                <span class="micro" style="display:block;color:var(--warn)">{{ \App\Programs\ProcessRefusal::WouldOverfillStorage->message() }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </fieldset>
        @endif

        <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.run.setup.suspend_hint') }}</p>
    </div>
</details>

{{-- THE ROUTE. Shown rather than chosen: every routing backdoor is free to include and hinders by its
     system's grade, so there is nothing to pick. See App\Cyberspace\ChainBuilder.

     Every node can be pointed at (tapped, on a phone) for what is known about it: a relay's card is the
     scanner window's own rows if you have read it, and yours is you. The kit's inspector does both. --}}
<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.chain') }}</h2>
        <div class="spacer"></div>
        <span class="micro num" title="{{ Text::get('cyberspace.run.setup.chain_hops_hint') }}">{{ Text::get('cyberspace.run.setup.chain_hops', ['used' => $chain->count(), 'cap' => $chainCap]) }}</span>
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
                    <span class="trace__node" tabindex="0" role="button" aria-label="{{ $hop->displayName() }}"
                          data-inspect="#hop-{{ $hop->id }}">&#x25C7;</span>
                @endforeach
                <span class="trace__node trace__node--you" tabindex="0" role="button" aria-label="{{ $character->handle }}"
                      data-inspect="#hop-you">&#x25C9;</span>
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

            {{-- The cards the nodes open. A <template> each, cloned by the inspector into the floating
                 layer on hover and into a modal on tap, so each is written once. --}}
            @foreach ($chain as $hop)
                @php($hopScan = $hopScans[$hop->address] ?? null)
                <template id="hop-{{ $hop->id }}">
                    <div class="item-card">
                        <div class="item-card__hd">
                            <span class="item-card__name">{{ $hop->displayName() }}</span>
                            <div class="row row--wrap" style="--gap:var(--s-2);margin-top:var(--s-2)">
                                <span class="tag tag--ok">{{ Text::get('cyberspace.run.setup.hop_routing') }}</span>
                                <span class="tag">{{ Text::get('cyberspace.run.setup.hop', ['grade' => $hop->grade]) }}</span>
                                <span class="tag">{{ $hop->type->label() }}</span>
                                @if ($hop->owner)
                                    <span class="tag">{{ $hop->owner->name }}</span>
                                @endif
                            </div>
                            <p class="micro" style="margin:var(--s-2) 0 0;color:var(--ink-2)">{{ $hop->address }}</p>
                        </div>
                        <div class="item-card__stats">
                            @if ($hopScan === null)
                                <p class="micro" style="margin:0 0 var(--s-2);color:var(--ink-2)">{{ Text::get('cyberspace.run.setup.hop_unscanned') }}</p>
                            @endif
                            <x-scan-facts :facts="($hopScan ?? new \App\Models\SystemScan)->facts($character)" />
                        </div>
                    </div>
                </template>
            @endforeach

            <template id="hop-you">
                <div class="item-card">
                    <div class="item-card__hd">
                        <span class="item-card__name">{{ $character->handle }}</span>
                        <div class="row row--wrap" style="--gap:var(--s-2);margin-top:var(--s-2)">
                            <span class="tag">{{ Text::get('cyberspace.run.setup.you') }}</span>
                        </div>
                    </div>
                    <div class="item-card__stats">
                        <div class="item-card__stat"><span>{{ Text::get('cyberspace.run.setup.heat') }}</span><b>{{ rtrim(rtrim(number_format((float) $character->heat, 1), '0'), '.') }}</b></div>
                        <div class="item-card__stat"><span>{{ Text::get('cyberspace.run.setup.routing_allowed') }}</span><b>{{ $chainCap }}</b></div>
                        @if ($character->equippedDeck?->item)
                            <div class="item-card__stat"><span>{{ Text::get('cyberspace.run.setup.deck') }}</span><b>{{ $character->equippedDeck->item->displayName() }}</b></div>
                        @endif
                    </div>
                </div>
            </template>
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
@php($free = $totals === null ? ['qflops' => 0, 'memory' => 0] : [
    'qflops' => max(0, \App\Support\Qb::tenths($totals->qflops) - \App\Support\Qb::tenths($spent['qflops'])),
    'memory' => max(0, \App\Support\Qb::tenths($totals->quantabits) - \App\Support\Qb::tenths($spent['memory'])),
])
{{-- data-free-* is what is left of the deck in TENTHS before anything on this page is ticked; each ticked row carries its
     own cost the same way, and ui-kit.js does the sum. Display only: the run's start asks the runner again. --}}
 {{-- A MEMBER at the door does not choose a purpose: the owner starts the run. They get this same screen to choose
     what goes in with them, and its button is Accept (or Update, once they have). --}}
@php($asMember = $party && $party->owner_character_id !== $character->id && $party->x == $target->x && $party->y == $target->y && $party->started_at === null)
@unless ($target->type->isGateway())
    @include('run._party')
@endunless

<form id="run-form" method="POST" action="{{ $asMember ? route('run.party.accept') : route('run.start') }}" data-run-budget data-unable-cpu="{{ Text::get('cyberspace.run.setup.scripts_unable_cpu') }}" data-unable-ram="{{ Text::get('cyberspace.run.setup.scripts_unable_ram') }}" data-unable-both="{{ Text::get('cyberspace.run.setup.scripts_unable_both') }}" data-free-qflops="{{ $free['qflops'] }}" data-free-memory="{{ $free['memory'] }}">
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
                    @php($refusal = $loadoutRefusals->get($row->id))
                    <label class="check check--radio">
                        <input data-cost-qflops="{{ \App\Support\Qb::tenths($row->item->program->qflops_cost) }}" data-cost-memory="{{ \App\Support\Qb::tenths($row->item->program->memory_cost) }}" type="radio" name="icebreaker" value="{{ $row->id }}" @checked(! $refusal && $row->id === $icebreakers->pluck('id')->intersect($chosen)->first()) @disabled($refusal)>
                        <span class="check__box"></span>
                        <span>
                            {{ $row->item->displayName() }}
                            <span class="micro num" style="color:var(--ink-2)">
                                · {{ Text::get('programs.costs.compute', ['value' => $row->item->program->qflops_cost]) }}
                                · {{ Text::get('programs.costs.memory', ['value' => $row->item->program->memory_cost]) }}
                            </span>
                            @if ($refusal)
                                <span class="micro" style="display:block;color:var(--warn)">{{ $refusal->message() }}</span>
                            @else
                                <span class="micro" data-unable style="display:none;color:var(--warn)"></span>
                            @endif
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
                    @php($refusal = $loadoutRefusals->get($row->id))
                    <label class="check">
                        <input data-cost-qflops="{{ \App\Support\Qb::tenths($row->item->program->qflops_cost) }}" data-cost-memory="{{ \App\Support\Qb::tenths($row->item->program->memory_cost) }}" type="checkbox" name="programs[]" value="{{ $row->id }}" @checked(! $refusal && in_array($row->id, $chosen, true)) @disabled($refusal)>
                        <span class="check__box"></span>
                        <span>
                            {{ $row->item->displayName() }}
                            <span class="micro num" style="color:var(--ink-2)">
                                · {{ $row->item->program->kind->label() }}
                                · {{ Text::get('programs.costs.compute', ['value' => $row->item->program->qflops_cost]) }}
                                · {{ Text::get('programs.costs.memory', ['value' => $row->item->program->memory_cost]) }}
                            </span>
                            @if ($refusal)
                                <span class="micro" style="display:block;color:var(--warn)">{{ $refusal->message() }}</span>
                            @else
                                <span class="micro" data-unable style="display:none;color:var(--warn)"></span>
                            @endif
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

<div class="panel panel--snug" style="margin-top:var(--s-4)">
    <div class="panel__hd">
        <h2>{{ Text::get('cyberspace.run.setup.scripts') }}</h2>
    </div>

    <div style="padding:var(--s-3) 0;display:grid;gap:var(--s-3)">
        @if ($scripts->isEmpty())
            <p class="micro" style="color:var(--ink-2);margin:0">{{ Text::get('cyberspace.run.setup.scripts_none') }}</p>
        @else
            <fieldset class="stack" style="--gap:var(--s-2);border:0;padding:0;margin:0">
                @foreach ($scripts as $row)
                    @php($program = $row->item->program)
                    @php($refusal = $scriptRefusals->get($row->id))
                    <label class="check">
                        <input type="checkbox" name="scripts[]" value="{{ $row->id }}"
                               data-script data-cost-qflops="{{ \App\Support\Qb::tenths($program->qflops_cost) }}" data-cost-memory="{{ \App\Support\Qb::tenths($program->memory_cost) }}"
                               @if ($refusal) disabled @endif>
                        <span class="check__box"></span>
                        <span>
                            {{ $row->item->displayName() }}
                            @if ($row->quantity > 1) <span class="micro num" style="color:var(--ink-2)">× {{ $row->quantity }}</span> @endif
                            <span class="micro" style="display:block;color:var(--ink-1)">
                                @php($effects = collect($program->modifiers())->map(fn ($m) => $m->effectText())->unique()->values())
                                {{ $effects->isEmpty() ? Text::get('cyberspace.run.setup.scripts_no_effect') : $effects->implode(' · ') }}
                            </span>
                            <span class="micro num" style="display:block;color:var(--ink-2)">
                                {{ Text::get('programs.costs.compute', ['value' => \App\Support\Qb::of($program->qflops_cost)]) }}
                                · {{ Text::get('programs.costs.memory', ['value' => \App\Support\Qb::of($program->memory_cost)]) }}
                                @if ($program->isTemporary())
                                    · {{ Text::get('programs.costs.lifetime', ['time' => \Carbon\CarbonInterval::seconds($program->lifetime_seconds)->cascade()->forHumans(['short' => true, 'parts' => 2])]) }}
                                @endif
                            </span>
                            @if ($refusal)
                                <span class="micro" style="display:block;color:var(--warn)">{{ $refusal->message() }}</span>
                            @else
                                <span class="micro" data-unable style="display:none;color:var(--warn)"></span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </fieldset>

            <p class="micro num" style="color:var(--ink-2);margin:0">
                {{ Text::get('cyberspace.run.setup.scripts_free') }}
                <span data-free-readout>{{ Text::get('programs.costs.compute', ['value' => \App\Support\Qb::of($free['qflops'] / 10)]) }} · {{ Text::get('programs.costs.memory', ['value' => \App\Support\Qb::of($free['memory'] / 10)]) }}</span>
            </p>
        @endif

        <p class="micro" style="color:var(--ink-2);margin:0">
            {{ Text::get('cyberspace.run.setup.scripts_hint') }}
        </p>
    </div>
</div>

@if ($asMember)
<div class="panel panel--snug" style="margin-top:var(--s-4);margin-bottom:var(--s-6)">
    <div class="panel__bd">
        <button type="submit" class="btn cut" style="min-width:min(200px,100%)" @unless ($ready) disabled @endunless>
            {{ Text::get($party->members->firstWhere('character_id', $character->id)?->joined_at ? 'cyberspace.party.update' : 'cyberspace.party.accept') }}
        </button>
    </div>
</div>
@else
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
                ['intent' => 'infiltrate', 'purpose' => RunPurpose::Infiltrate, 'kind' => null],
                ['intent' => 'denial', 'purpose' => RunPurpose::Denial, 'kind' => null],
            ] as $option)
                {{-- A gateway takes Infiltrate and nothing else; nothing else takes Infiltrate. --}}
                @continue(($option['purpose'] === RunPurpose::Infiltrate) !== $target->type->isGateway() && $option['purpose'] !== RunPurpose::Denial)
                @continue($option['purpose'] === RunPurpose::Denial && $target->type->isGateway())
                <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                    {{-- A Placement job for this system makes the siphon door the job's own: named for it, and green
                         (the one filled signal button on the screen: it is the whole point of this run). --}}
                    @php($placing = $placement && $option['intent'] === 'siphon')
                    <button type="submit" @class(['btn', 'cut', 'btn--go' => $placing]) style="min-width:min(200px,100%)"
                            name="purpose" value="{{ $option['purpose']->value }}"
                            @if ($option['kind']) formaction="{{ route('run.start', ['backdoor_kind' => $option['kind']->value]) }}" @endif
                            @unless ($ready) disabled @endunless>
                        {{ Text::get($placing ? 'cyberspace.run.setup.intent_placement' : 'cyberspace.run.setup.intent_'.$option['intent']) }}
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
@endif

</form>

@endsection
