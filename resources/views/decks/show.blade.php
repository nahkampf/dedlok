@use('App\Support\Text')
@extends('layouts.app')
@section('title', $item->displayName())

@section('content')
    <div class="stack" style="--gap:var(--s-5)">
        <div class="stack" style="--gap:var(--s-2)">
            <a class="link" href="{{ route('inventory.index') }}">{{ Text::get('inventory.title') }}</a>

            <div class="row row--wrap" style="align-items:center">
                <h1 style="margin:0">{{ $item->displayName() }}</h1>
                @if ($equipped)
                    <span class="tag">{{ Text::get('decks.equip.is_equipped') }}</span>
                @endif
            </div>

            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('decks.board.lede') }}</p>
        </div>

        {{-- What you can do to the deck as a whole, above the board it applies
             to. Neither of these destroys anything, so neither is ember and
             neither asks twice — except unequipping, which asks because there
             are two right answers rather than because it is dangerous. --}}
        <div class="row row--wrap" style="--gap:var(--s-3)">
            @if ($equipped)
                <button type="button" class="btn cut" data-open="#unequip">
                    {{ Text::get('decks.equip.unequip') }}
                </button>
            @else
                <form method="POST" action="{{ route('deck.equip', $entry) }}">
                    @csrf
                    <button type="submit" class="btn cut">{{ Text::get('decks.equip.equip') }}</button>
                </form>
            @endif

            @if ($fitted->isNotEmpty())
                <form method="POST" action="{{ route('deck.strip', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--ghost cut">{{ Text::get('decks.fitting.strip') }}</button>
                </form>
                <span class="field__hint" style="align-self:center">{{ Text::get('decks.fitting.strip_hint') }}</span>
            @endif
        </div>

        <x-deck-board :item="$item" :entry="$entry" :fitted="$fitted" :spares="$spares" :heat="$heat" />

        <div class="panel">
            <div class="panel__hd">
                <span class="micro">{{ Text::get('decks.board.spec') }}</span>
                <div class="spacer"></div>
                <span class="micro num">{{ Text::plural('admin.items.sockets.total', $item->slotLayout()->total()) }}</span>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <p style="font-size:var(--fs-sm);margin:0">{{ $item->slotLayout()->summary() }}</p>

                {{-- What is actually in it, added up. Labelled by the MEASURE
                     and not by the socket: qFLOPS comes from a CPU or an NPU,
                     so a deck with one of each has one compute figure rather
                     than two halves of one. --}}
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(140px,100%),1fr));--gap:var(--s-4)">
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('decks.ready.compute') }}</span>
                        <span class="readout__value">{{ Text::get('decks.units.qflops', [
                            'value' => number_format($totals->qflops, 0, '.', ' '),
                        ]) }}</span>
                    </div>
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('decks.ready.memory') }}</span>
                        <span class="readout__value">{{ Text::get('decks.units.quantabits', [
                            'value' => number_format($totals->quantabits, 0, '.', ' '),
                        ]) }}</span>
                    </div>
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('decks.ready.link') }}</span>
                        <span class="readout__value">{{ Text::get('decks.units.bandwidth', [
                            'value' => number_format($totals->bandwidth, 0, '.', ' '),
                        ]) }}</span>
                    </div>
                    {{-- Coloured by the worst-off module rather than by a
                         ceiling: a deck has no thermal rating, so a heat
                         figure is only hot in relation to what is standing
                         in it. --}}
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('decks.ready.heat') }}</span>
                        <span class="readout__value" style="color:{{ $heat->worst()->inkToken() }}">
                            {{ Text::get('decks.units.heat', [
                                'value' => number_format($totals->netHeat(), 0, '.', ' '),
                            ]) }}
                        </span>
                    </div>
                </div>

                {{-- The plain sum of what is fitted, with no modifiers: the
                     base, which MECHANICS allows for any deck that is not the
                     equipped one — and there is no equipped deck yet. Nothing
                     enforces it, and an over-committed deck is a design
                     tension rather than a row to correct. --}}
                <div class="vital vital--tight">
                    <span class="vital__label">{{ Text::get('decks.fitting.draw') }}</span>
                    <div class="meter" style="--v:{{ $totals->ratio() }};--fill:var({{ $totals->isOver() ? '--crit' : '--ok' }})">
                        <div class="meter__fill"></div>
                    </div>
                    <span class="vital__value num">{{ Text::get('decks.fitting.budget', [
                        'draw' => $totals->draw,
                        'supply' => $totals->supply,
                    ]) }}</span>
                </div>

                {{-- No heat meter beside the power one, and that asymmetry is
                     the design: power is measured against a supply the deck
                     carries, and heat is measured against nothing at all. What
                     the number means is decided per module, below. --}}
                @if ($totals->cooling > 0)
                    <p class="muted" style="font-size:var(--fs-xs);margin:0">
                        {{ Text::get('decks.heat.cooled', ['value' => $totals->cooling]) }}
                    </p>
                @endif

                {{-- Whether the thing will run at all. A machine with a part
                     missing is an ordinary state to be halfway through, so
                     this names what is missing rather than only saying no. --}}
                <div class="callout {{ $totals->isReady() ? 'callout--ok' : 'callout--warn' }}">
                    <span class="dot {{ $totals->isReady() ? 'dot--ok' : 'dot--warn' }}" style="margin-top:6px"></span>
                    <div class="stack" style="--gap:var(--s-2);min-width:0">
                        <strong style="font-size:var(--fs-sm)">
                            {{ Text::get($totals->isReady() ? 'decks.ready.yes' : 'decks.ready.no') }}
                        </strong>

                        @unless ($totals->isReady())
                            <div class="row row--wrap" style="--gap:var(--s-2)">
                                <span class="micro" style="color:var(--ink-2)">{{ Text::get('decks.ready.needs') }}</span>
                                @foreach ($totals->unmet() as $requirement)
                                    <span class="tag tag--warn">{{ $requirement->label() }}</span>
                                @endforeach
                            </div>
                        @endunless
                    </div>
                </div>

                @if ($totals->isOver())
                    <p class="muted" style="font-size:var(--fs-xs);margin:0">
                        {{ Text::get('decks.fitting.over_budget') }}
                    </p>
                @endif

                {{-- Past a module's hard limit. Not a risk but a certainty, so
                     it is the loudest thing this screen has — the kit's own
                     "you should feel this" component, and the only place on
                     the deck screen that spends the accent. --}}
                @if ($heat->overLimit() !== [])
                    <div class="hud-alert">
                        <span class="hud-alert__code">{{ Text::get('decks.heat.over_limit_code') }}</span>
                        <div class="stack" style="--gap:var(--s-1);min-width:0">
                            <span style="font-size:var(--fs-sm)">
                                {{ Text::get('decks.heat.over_limit', ['modules' => implode(', ', $heat->overLimit())]) }}
                            </span>
                            <span class="micro" style="color:var(--ink-2)">{{ Text::get('decks.heat.over_limit_fix') }}</span>
                        </div>
                    </div>
                @endif

                {{-- Over the warning level: where the risk of destruction
                     begins. The risk itself is not built, so this names the
                     parts rather than quoting odds it cannot compute. --}}
                @if ($heat->atRisk() !== [])
                    <div class="callout callout--warn">
                        <span class="dot dot--warn" style="margin-top:6px"></span>
                        <div class="stack" style="--gap:var(--s-2);min-width:0">
                            <div class="row row--wrap" style="--gap:var(--s-2)">
                                <span class="micro" style="color:var(--ink-2)">{{ Text::get('decks.heat.at_risk') }}</span>
                                @foreach ($heat->atRisk() as $name)
                                    <span class="tag tag--warn">{{ $name }}</span>
                                @endforeach
                            </div>
                            <span class="micro" style="color:var(--ink-3)">
                                {{ Text::get('decks.heat.at_risk_note') }}
                                {{ Text::get('decks.heat.risk_not_built') }}
                            </span>
                        </div>
                    </div>
                @endif



                <p class="muted" style="font-size:var(--fs-xs);margin:0">{{ Text::get('decks.equip.nothing_yet') }}</p>

                @if ($item->displayFlavor())
                    <p class="muted" style="font-size:var(--fs-xs);font-style:italic;margin:0">{{ $item->displayFlavor() }}</p>
                @endif
            </div>
        </div>
    </div>

    @if ($equipped)
        {{-- Two answers, both ordinary. A deck left built is one you mean to
             pick up again; a stripped one is a deck whose parts are worth more
             elsewhere. Either way it goes back to the stash, and neither
             destroys anything — so neither is the dangerous option and neither
             takes the accent. --}}
        <dialog class="modal" id="unequip">
            <div class="panel__hd">
                <span class="micro">{{ Text::get('decks.equip.confirm') }}</span>
                <div class="spacer"></div>
                <button class="btn btn--sm btn--ghost" data-close aria-label="{{ Text::get('decks.board.close') }}">✕</button>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-4)">
                <p style="font-size:var(--fs-sm);margin:0">{{ Text::get('decks.equip.question') }}</p>

                <div class="stack" style="--gap:var(--s-2)">
                    <form method="POST" action="{{ route('deck.unequip') }}">
                        @csrf
                        <button type="submit" class="btn btn--block cut">{{ Text::get('decks.equip.leave') }}</button>
                    </form>
                    <span class="field__hint">{{ Text::get('decks.equip.leave_hint') }}</span>
                </div>

                <div class="stack" style="--gap:var(--s-2)">
                    <form method="POST" action="{{ route('deck.unequip') }}">
                        @csrf
                        <input type="hidden" name="strip" value="1">
                        <button type="submit" class="btn btn--block btn--ghost cut">{{ Text::get('decks.equip.take') }}</button>
                    </form>
                    <span class="field__hint">{{ Text::get('decks.equip.take_hint') }}</span>
                </div>
            </div>
        </dialog>
    @endif
@endsection
