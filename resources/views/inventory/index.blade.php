@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('inventory.title'))

@section('content')
    <div class="row row--wrap" style="align-items:flex-end">
        <h1>{{ Text::get('inventory.title') }}</h1>
        <div class="spacer"></div>

        {{-- The balance. It lives here rather than in the topbar because this
             is the screen about what you have; nothing spends it yet, so the
             readout carries no delta. --}}
        <div class="readout">
            <span class="readout__label">{{ Text::get('creds.name') }}</span>
            <span class="readout__value num">{{ number_format($character->creds, 0, '.', ' ') }}</span>
        </div>
    </div>

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
            <x-inventory-list :rows="$physical" :hint="Text::get('inventory.physical_hint')" :containers="$containers" />
        </div>

        <div class="tabpanel" data-panel="data" hidden>
            <x-inventory-list :rows="$data" :hint="Text::get('inventory.data_hint')" :containers="$containers" />
        </div>
    </div>
@endsection
