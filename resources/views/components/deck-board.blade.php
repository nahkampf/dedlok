@use('App\Decks\BoardLayout')
@use('App\Support\Text')
@props([
    'item',
    'editable' => false,
    'field' => null,
    // Present only on the player's screen: the deck object, what is in each
    // socket, and what they are carrying that could go in one.
    'entry' => null,
    'fitted' => null,
    'spares' => null,
    // What the deck's thermal load is doing to each socket. Absent everywhere but the
    // player's screen, where it is the whole point of the pulsing border.
    'thermal' => null,
])

@php($board = $item->board())
@php($fitted = $fitted ?? collect())
@php($spares = $spares ?? collect())
@php($interactive = $entry !== null)
@php($thermal = $thermal ?? new \App\Decks\NoThermalExposure)

{{-- A node knows its TYPE and where it sits; the sockets themselves are rows
     on the definition. Grouping them here rather than inside BoardLayout keeps
     that class pure — it decides geometry, and geometry has no opinion about
     which particular Socket K you are looking at. --}}
@php($socketsByType = $item->deckSlots->groupBy(fn ($slot) => $slot->type()->key()))

<div class="board-wrap">
    <div class="frame scanlines brackets board"
         @if ($editable)
             data-board-edit="{{ $field }}"
             data-board-min="{{ BoardLayout::MIN }}"
             data-board-max="{{ BoardLayout::MAX }}"
         @endif
         @unless ($interactive) role="img" @endunless
         aria-label="{{ Text::get('decks.board.aria', [
             'deck' => $item->displayName(),
             'sockets' => $item->slotLayout()->summary(),
         ]) }}">

        <span class="frame__label">{{ Text::get('decks.board.label') }}</span>

        @if ($board->isEmpty())
            <span class="frame__hud micro">{{ Text::get('decks.board.empty') }}</span>
        @else
            {{-- The wires, behind everything. preserveAspectRatio="none" so
                 the viewBox maps straight onto the percentage coordinates and
                 an x of 50 is half way across whatever width the board is. --}}
            <svg class="board__wires" viewBox="0 0 100 100" preserveAspectRatio="none"
                 aria-hidden="true" focusable="false">
                @foreach ($board->wires() as [$node, $hub])
                    <line class="board__wire" data-from="{{ $node->key() }}"
                          x1="{{ $node->x }}" y1="{{ $node->y }}"
                          x2="{{ $hub->x }}" y2="{{ $hub->y }}"></line>
                @endforeach
            </svg>

            @php($hub = $board->hub())

            @foreach ($board->nodes() as $node)
                @php($sockets = $socketsByType[$node->key()] ?? collect())

                <div class="board__node @if ($node->isCpu()) board__node--cpu @endif"
                     data-node="{{ $node->key() }}"
                     @if ($hub && $node->key() === $hub->key()) data-hub @endif
                     @if ($editable) tabindex="0" @endif
                     style="left:{{ $node->x }}%;top:{{ $node->y }}%">

                    <{{ $node->isGrouped() ? 'div' : 'span' }}
                        @class(['board__box' => $node->isGrouped()])
                        @if ($node->isGrouped()) style="--cols:{{ $node->columns() }}" @endif>

                        @foreach ($sockets as $socket)
                            @php($fitting = $fitted[$socket->id] ?? null)
                            @php($module = $fitting?->module?->item)

                            @if ($interactive)
                                {{-- A socket you can act on is a button. The
                                     dialog behind it is rendered outside the
                                     board — see below. --}}
                                <button type="button"
                                    class="slot {{ $module ? 'slot--filled '.$module->tierClass().' '.$thermal->verdict($socket->id)->slotClass() : 'slot--empty' }}"
                                    data-open="#socket-{{ $socket->id }}"
                                    aria-haspopup="dialog"
                                    aria-label="{{ $node->label() }} — {{ $module?->displayName() ?? Text::get('decks.fitting.empty_socket') }}">
                                    @if ($module)
                                        <span class="slot__icon" aria-hidden="true">{{ $module->glyph() }}</span>
                                    @endif
                                </button>
                            @else
                                <span class="slot {{ $module ? 'slot--filled '.$module->tierClass().' '.$thermal->verdict($socket->id)->slotClass() : 'slot--empty' }}"
                                      @if ($module) title="{{ $module->displayName() }}" @endif>
                                    @if ($module)
                                        <span class="slot__icon" aria-hidden="true">{{ $module->glyph() }}</span>
                                    @endif
                                </span>
                            @endif
                        @endforeach
                    </{{ $node->isGrouped() ? 'div' : 'span' }}>

                    <span class="board__label">
                        {{ $node->label() }}
                        @if ($node->isGrouped())
                            <span class="board__count">{{ Text::get('decks.board.count', ['count' => $node->count]) }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
        @endif
    </div>
</div>

@if ($interactive)
    {{-- The card for each fitted module, and the confirmation its Destroy
         button opens. Out here for the same reason the socket dialogs are:
         .board__node carries a transform, and a modal does not want a
         containing block that is not the viewport. --}}
    @foreach ($fitted as $fitting)
        @if ($fitting->module)
            <template id="detail-{{ $fitting->module_id }}">
                <x-item-detail :entry="$fitting->module" :deck="$entry" :socket="$fitting->slot" />
            </template>

            <x-item-destroy :entry="$fitting->module" />
        @endif
    @endforeach

    {{-- One dialog per socket, outside the board.

         Outside deliberately: .board__node carries a transform, and a modal
         nested under one is a modal whose containing block is not the
         viewport. The top layer is supposed to be immune to that; it is not
         worth finding out which browsers agree. --}}
    @foreach ($item->deckSlots as $socket)
        @php($fitting = $fitted[$socket->id] ?? null)
        @php($module = $fitting?->module)
        @php($fits = $spares->filter(fn ($row) => $socket->accepts($row->item)))

        <dialog class="modal" id="socket-{{ $socket->id }}">
            <div class="panel__hd">
                <span class="micro">{{ $socket->type()->fullLabel() }}</span>
                <div class="spacer"></div>
                <button class="btn btn--sm btn--ghost" data-close aria-label="{{ Text::get('decks.board.close') }}">✕</button>
            </div>
            <div class="panel__bd stack" style="--gap:var(--s-3)">
                @if ($module)
                    <div class="item-row {{ $module->item->tierClass() }}">
                        <span class="slot__icon">{{ $module->item->glyph() }}</span>
                        <span class="stack" style="--gap:2px;min-width:0">
                            <span class="item-row__name">{{ $module->item->displayName() }}</span>
                            @if ($module->item->hardwareSummary())
                                <span class="micro" style="color:var(--ink-3)">{{ $module->item->hardwareSummary() }}</span>
                            @endif
                        </span>
                    </div>

                    @php($verdict = $thermal->verdict($socket->id))
                    @unless ($verdict->isSafe())
                        <p class="item-card__note" style="padding:0">{{ $verdict->label() }}</p>
                    @endunless

                    <div class="row row--wrap" style="--gap:var(--s-2)">
                        <form method="POST" action="{{ route('deck.fit', [$entry, $socket]) }}">
                            @csrf
                            <button type="submit" class="btn btn--ghost cut">
                                {{ Text::get('decks.fitting.unfit') }}
                            </button>
                        </form>

                        {{-- Everything else you can do to it lives on its card,
                             which is one button away rather than a second copy
                             of the same list here. --}}
                        <button type="button" class="btn btn--ghost cut"
                                data-inspect-open="#detail-{{ $module->id }}">
                            {{ Text::get('inventory.actions.details') }}
                        </button>
                    </div>
                @elseif ($socket->kind->seats() === null)
                    {{-- An IO port is not an empty socket, it is a socket that
                         seats nothing. Saying "nothing you own fits" would
                         send a player looking for the thing that does. --}}
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('decks.fitting.seats_nothing') }}</p>
                @elseif ($fits->isEmpty())
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('decks.fitting.nothing_fits') }}</p>
                @else
                    <p class="micro" style="color:var(--ink-3);margin:0">
                        {{ Text::get('decks.fitting.choose', ['socket' => $socket->type()->label()]) }}
                    </p>

                    @foreach ($fits as $spare)
                        <form method="POST" action="{{ route('deck.fit', [$entry, $socket]) }}">
                            @csrf
                            <input type="hidden" name="module" value="{{ $spare->id }}">
                            <button type="submit" class="btn btn--block cut" style="text-align:left">
                                {{ $spare->item->displayName() }}
                                @if ($spare->item->hardwareSummary())
                                    <span class="micro" style="color:var(--ink-3)">· {{ $spare->item->hardwareSummary() }}</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                @endif
            </div>
        </dialog>
    @endforeach
@endif
