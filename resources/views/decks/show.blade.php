@use('App\Support\Text')
@extends('layouts.app')
@section('title', $item->displayName())

@section('content')
    <div class="stack" style="--gap:var(--s-5)">
        <div class="stack" style="--gap:var(--s-2)">
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
                    {{-- Brass, not bone: nothing is destroyed — every module
                         comes back — but it undoes real work and you will be
                         putting them all in again. That is what --warn is for,
                         and it is a step below the ember that means gone. --}}
                    <button type="submit" class="btn btn--warn cut">{{ Text::get('decks.fitting.strip') }}</button>
                </form>
                <span class="field__hint" style="align-self:center">{{ Text::get('decks.fitting.strip_hint') }}</span>
            @endif
        </div>

        {{-- The board, and what the board is doing, side by side.

             They are one subject: the object and its current state. Splitting
             them into two stacked sections made the second read as an unrelated
             list further down the page. The board sets the height of the row and
             the panel scrolls inside it, so a busy deck never stretches the
             diagram — and below 900px the split simply stops, which is where
             this started and the right answer on a phone. --}}
        <div class="board-split">
            <x-deck-board :item="$item" :entry="$entry" :fitted="$fitted" :spares="$spares" :thermal="$thermal" />

            {{-- Open by default: it is short, it is live, and it is the reason to be
                 on this screen once the deck is built. --}}
            <details class="panel" open>
                <summary class="panel__hd">
                    <span class="micro">{{ Text::get('programs.tabs.running') }}</span>
                    <div class="spacer"></div>
                    @if ($equipped)
                        {{-- The budget a start is checked against, shown where the
                             starting happens. Both halves are RESOLVED totals, so a
                             skill that frees up compute shows here without this screen
                             knowing that skills exist. --}}
                        <span class="micro num" style="color:var(--ink-2)">
                            {{ Text::get('programs.budget', ['used' => $spent['qflops'], 'total' => $totals->qflops]) }}
                            {{ Text::get('programs.units.compute') }}
                            &middot;
                            {{ Text::get('programs.budget', ['used' => $spent['memory'], 'total' => $totals->quantabits]) }}
                            {{ Text::get('programs.units.memory') }}
                        </span>
                    @endif
                </summary>

                <div class="panel__bd">
                    @if (! $equipped)
                        {{-- Empty by rule rather than by accident, so the screen says
                             which: putting a deck down stops everything on it. --}}
                        <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('programs.not_equipped') }}</p>
                    @else
                        @forelse ($processes as $process)
                            @php($program = $process->item?->program)
                            <div class="row row--wrap" style="justify-content:space-between;align-items:center;--gap:var(--s-3);padding:var(--s-3) 0;@if (! $loop->first) border-top:var(--hair) solid var(--line-0) @endif">
                                <div style="min-width:0">
                                    <div style="color:var(--ink-0)">{{ $process->item?->displayName() }}</div>
                                    <div class="micro" style="color:var(--ink-2)">
                                        {{ $program?->kind->label() }}
                                        @if ($process->started_at)
                                            &middot; {{ $process->started_at->diffForHumans() }}
                                        @endif
                                    </div>
                                </div>

                                <div class="row" style="--gap:var(--s-3);align-items:center">
                                    @if ($program)
                                        <span class="micro num" style="color:var(--ink-2);white-space:nowrap">
                                            {{ Text::get('programs.costs.compute', ['value' => $program->qflops_cost]) }}
                                            &middot;
                                            {{ Text::get('programs.costs.memory', ['value' => $program->memory_cost]) }}
                                        </span>
                                    @endif

                                    {{-- Stopping destroys nothing: the program goes back
                                         to storage, merging into the pile it came out
                                         of. So nothing stands in front of it, the same
                                         reasoning stripping a deck follows. --}}
                                    <form method="POST" action="{{ route('inventory.run', $process) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--sm btn--ghost cut">
                                            {{ Text::get('programs.action.stop') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('programs.running_none') }}</p>
                        @endforelse
                    @endif
                </div>
            </details>
        </div>


        {{-- Folds, because it is the longest thing on the screen and a player
             who has finished building is here for the board and what is running
             on it. <details> does the work: no script, and the keyboard and
             screen-reader behaviour come free. Open by default — it is the
             deck's own numbers, and hiding them by default would make the
             screen look emptier than it is. --}}
        <details class="panel" open>
            <summary class="panel__hd">
                <span class="micro">{{ Text::get('decks.board.spec') }}</span>
                <div class="spacer"></div>
                <span class="micro num">{{ Text::plural('admin.items.sockets.total', $item->slotLayout()->total()) }}</span>
            </summary>
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
                            'value' => \App\Support\Qb::format($totals->qflops, ' '),
                        ]) }}</span>
                    </div>
                    <div class="readout">
                        <span class="readout__label">{{ Text::get('decks.ready.memory') }}</span>
                        <span class="readout__value">{{ Text::get('decks.units.quantabits', [
                            'value' => \App\Support\Qb::format($totals->quantabits, ' '),
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
                        <span class="readout__label">{{ Text::get('decks.ready.thermal') }}</span>
                        <span class="readout__value" style="color:{{ $thermal->worst()->inkToken() }}">
                            {{ Text::get('decks.units.thermal_load', [
                                'value' => number_format($totals->netThermalLoad(), 0, '.', ' '),
                            ]) }}
                        </span>
                    </div>
                </div>

                <x-deck-vitals :totals="$totals" :thermal="$thermal" :spent="$spent" />

                @if ($totals->cooling > 0)
                    <p class="muted" style="font-size:var(--fs-xs);margin:0">
                        {{ Text::get('decks.thermal.cooled', ['value' => $totals->cooling]) }}
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
                @if ($thermal->overLimit() !== [])
                    <div class="hud-alert">
                        <span class="hud-alert__code">{{ Text::get('decks.thermal.over_limit_code') }}</span>
                        <div class="stack" style="--gap:var(--s-1);min-width:0">
                            <span style="font-size:var(--fs-sm)">
                                {{ Text::get('decks.thermal.over_limit', ['modules' => implode(', ', $thermal->overLimit())]) }}
                            </span>
                            <span class="micro" style="color:var(--ink-2)">{{ Text::get('decks.thermal.over_limit_fix') }}</span>
                        </div>
                    </div>
                @endif

                {{-- Over the warning level: where the risk of destruction
                     begins. The risk itself is not built, so this names the
                     parts rather than quoting odds it cannot compute. --}}
                @if ($thermal->atRisk() !== [])
                    <div class="callout callout--warn">
                        <span class="dot dot--warn" style="margin-top:6px"></span>
                        <div class="stack" style="--gap:var(--s-2);min-width:0">
                            <div class="row row--wrap" style="--gap:var(--s-2)">
                                <span class="micro" style="color:var(--ink-2)">{{ Text::get('decks.thermal.at_risk') }}</span>
                                @foreach ($thermal->atRisk() as $name)
                                    <span class="tag tag--warn">{{ $name }}</span>
                                @endforeach
                            </div>
                            <span class="micro" style="color:var(--ink-3)">
                                {{ Text::get('decks.thermal.at_risk_note') }}
                                {{ Text::get('decks.thermal.risk_not_built') }}
                            </span>
                        </div>
                    </div>
                @endif



                <p class="muted" style="font-size:var(--fs-xs);margin:0">{{ Text::get('decks.equip.nothing_yet') }}</p>

                @if ($item->displayFlavor())
                    <p class="muted" style="font-size:var(--fs-xs);font-style:italic;margin:0">{{ $item->displayFlavor() }}</p>
                @endif
            </div>
        </details>
    </div>

    {{-- DECRYPTION: the files waiting to be broken, here because the clock runs on the deck being carried and
         only while a Decryptor runs on it. The head is the one being worked on. --}}
    @if ($decryption !== null)
        <details class="panel" style="margin-top:var(--s-5)" @open($decryption['entries'] !== [])>
            <summary class="panel__hd">
                <span class="micro">{{ Text::get('decryption.heading') }}</span>
                <div class="spacer"></div>
                <span class="micro num" style="color:var(--ink-2)">{{ count($decryption['entries']) }}</span>
            </summary>

            <div class="panel__bd stack" style="--gap:var(--s-3)">
                <span class="field__hint">{{ Text::get('decryption.cycle', ['time' => \Carbon\CarbonInterval::seconds(\App\Decryption\DecryptionQueue::cycleSeconds($character))->cascade()->forHumans(['parts' => 2])]) }}</span>

                @if ($decryption['entries'] === [])
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('decryption.empty') }}</p>
                @elseif (! $decryption['running'])
                    <p class="muted" style="font-size:var(--fs-sm);margin:0;color:var(--warn)">{{ Text::get('decryption.no_decryptor') }}</p>
                @endif

                @if (count($decryption['entries']) > 1)
                    <p class="micro" style="color:var(--ink-3);margin:0">{{ Text::get('decryption.reorder_hint') }}</p>
                @endif

                <div class="stack" style="--gap:var(--s-2)" data-queue-sort="{{ route('decryption.reorder') }}">
                    @foreach ($decryption['entries'] as $line)
                        @php($file = $line['row']->extractedFile())
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center" data-queue-row="{{ $line['entry']->id }}">
                            @if (count($decryption['entries']) > 1)
                                <button type="button" class="micro num queue-handle" data-queue-handle
                                        title="{{ Text::get('decryption.reorder_handle') }}"
                                        aria-label="{{ Text::get('decryption.reorder_handle') }}">{{ $line['entry']->position }}</button>
                            @else
                                <span class="micro num" style="color:var(--ink-3)">{{ $line['entry']->position }}</span>
                            @endif
                            <span class="item-row__name" style="flex:1 1 180px;min-width:0">
                                @if ($line['entry']->worker_id)
                                    <span class="dot dot--live" aria-hidden="true"></span>
                                @endif
                                {{ $file?->label }}
                                <span class="micro" style="color:var(--ink-3)">
                                    · {{ $line['odds'] ? Text::get('decryption.encryption', ['skill' => $line['odds']['skill']->title]) : '' }}
                                </span>
                            </span>
                            <span class="micro num" style="color:var(--ink-3)">
                                @if ($line['odds'])
                                    {{ Text::get('decryption.odds', ['percent' => round($line['odds']['chance'] * 100), 'time' => \Carbon\CarbonInterval::seconds((int) ceil($line['odds']['seconds']))->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}
                                @endif
                            </span>
                            <span class="tag">{{ Text::get($line['entry']->worker_id ? 'decryption.working' : 'decryption.waiting') }}</span>
                            <form method="POST" action="{{ route('decryption.destroy', $line['entry']) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('decryption.remove') }}</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    @endif

    {{-- Closed by default, and it is the one that earns it: two slot grids are
         the longest thing on the page, and storage is somewhere you go rather
         than something you monitor.

         Two grids, not one. A program and a file share an inventory but are not
         the same kind of thing — one is something you RUN, the other something
         you HOLD — and a single list sorted by name would be useful to nobody.
         `bare` because they are already inside a panel, and a panel in a panel
         boxes what is already boxed. --}}
    <details class="panel" style="margin-top:var(--s-5)">
        <summary class="panel__hd">
            <span class="micro">{{ Text::get('programs.tabs.storage') }}</span>
            <div class="spacer"></div>
            <span class="micro num" style="color:var(--ink-2)">
                {{ Text::plural('inventory.count', $stored[0]->sum('quantity') + $stored[1]->sum('quantity')) }}
            </span>
        </summary>

        <div class="panel__bd">
            <x-inventory-grid bare :rows="$stored[0]" :hint="Text::get('programs.storage.programs')" :containers="$storageContainers" />
            <x-inventory-grid bare :rows="$stored[1]" :hint="Text::get('programs.storage.data')" :containers="$storageContainers" />
        </div>
    </details>

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
                @if ($decryption !== null && $decryption['running'] && $decryption['entries'] !== [])
                    <p style="font-size:var(--fs-sm);margin:0;color:var(--warn)">{{ Text::get('decryption.unequip_warning') }}</p>
                @endif

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
