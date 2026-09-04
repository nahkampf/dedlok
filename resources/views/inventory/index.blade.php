@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('inventory.title'))

@section('content')
    {{-- No balance here any more. It is in the bar at the top of every screen,
         and two copies of one number a few pixels apart is one of them wrong
         the first time anything spends it. --}}
    <h1>{{ Text::get('inventory.title') }}</h1>

    @if ($equipped)
        {{-- The equipped deck is not in either inventory — it is on you. This
             is where it shows, and the only way through to its board now that
             loose() leaves it out of the grid. --}}
        <div class="panel" style="margin-top:var(--s-5)">
            <div class="panel__hd">
                <span class="micro">{{ Text::get('inventory.in_use') }}</span>
            </div>
            <div class="item-row {{ $equipped->item->tierClass() }}">
                <span class="slot__icon">{{ $equipped->item->glyph() }}</span>
                <span class="stack" style="--gap:2px;min-width:0">
                    <span class="item-row__name">{{ $equipped->item->displayName() }}</span>
                    @if ($equipped->item->hardwareSummary())
                        <span class="micro" style="color:var(--ink-3)">{{ $equipped->item->hardwareSummary() }}</span>
                    @endif
                </span>
                <span class="row row--wrap" style="--gap:var(--s-2);justify-content:flex-end">
                    {{-- An equipped deck is in no grid, so this is the only way
                         to its card — and the card is where every action is. --}}
                    <button type="button" class="btn btn--sm btn--ghost cut"
                            data-inspect-open="#detail-{{ $equipped->id }}">
                        {{ Text::get('inventory.actions.details') }}
                    </button>
                    <a class="btn btn--sm cut" href="{{ route('deck.show', $equipped) }}">
                        {{ Text::get('decks.board.open') }}
                    </a>
                </span>
            </div>
        </div>

        <template id="detail-{{ $equipped->id }}">
            <x-item-detail :entry="$equipped" />
        </template>

        <x-item-destroy :entry="$equipped" />
    @endif

    {{-- Two inventories, one screen. The split is a property of the item, so a
         row can never appear in the wrong tab: Character::inventoryIn() filters
         through the definition's container. --}}
    <div style="margin-top:var(--s-5)">
        <div class="tabs" role="tablist">
            <button class="tab" role="tab" aria-selected="true" data-tab="physical">
                {{ Text::get('inventory.physical') }}
            </button>
            <button class="tab" role="tab" aria-selected="false" data-tab="data">
                {{ Text::get('inventory.data') }}
            </button>
        </div>

        <div class="tabpanel" data-panel="physical">
            <x-inventory-grid :rows="$physical" :hint="Text::get('inventory.physical_hint')" :containers="$containers" />
        </div>

        <div class="tabpanel" data-panel="data" hidden>
            <x-inventory-grid :rows="$data" :hint="Text::get('inventory.data_hint')" :containers="$containers" />
        </div>
    </div>
@endsection
