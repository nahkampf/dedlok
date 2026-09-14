@use('App\Support\Text')
@extends('layouts.app')
@section('title', Text::get('inventory.title'))

@section('content')
    {{-- No balance here any more. It is in the bar at the top of every screen,
         and two copies of one number a few pixels apart is one of them wrong
         the first time anything spends it. --}}
    <h1>{{ Text::get('inventory.title') }}</h1>

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
