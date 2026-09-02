@use('App\Support\Text')
@extends('layouts.app')
@section('title', config('app.name'))
@use('Illuminate\Support\Number')

@section('content')
    <h1 class="display">{{ Text::get('ui.nav.run') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:60ch">
        Laravel {{ app()->version() }} · {{ config('version.app') }}
    </p>

    <div class="rule"><span class="micro">The catalogue</span></div>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
        {{-- The row carries the whole definition now, words included. It used
             to carry a slug and nothing a player could read. --}}
        @forelse ($items as $item)
            <div class="item-card {{ $item->tierClass() }}" style="width:100%">
                <div class="item-card__hd">
                    <div class="item-card__name">{{ $item->displayName() }}</div>
                    <div class="item-card__tier">{{ $item->tierName() }}</div>
                </div>
                <div class="item-card__stats">
                    <div class="item-card__stat">
                        <span>{{ Text::get('items.stat.weight') }}</span>
                        <b class="num">{{ Number::format((float) $item->weight) }}</b>
                    </div>
                    <div class="item-card__stat">
                        <span>{{ Text::get('items.stat.cost') }}</span>
                        <b class="num">{{ Number::format($item->base_cost) }}</b>
                    </div>
                </div>
                {{-- Flavour is optional: most items have none. --}}
                @if ($flavor = $item->displayFlavor())
                    <div class="item-card__flavor">{{ $flavor }}</div>
                @endif
            </div>
        @empty
            <p class="muted" style="font-size:var(--fs-sm)">{{ Text::get('items.catalogue_empty') }}</p>
        @endforelse
    </div>
@endsection
