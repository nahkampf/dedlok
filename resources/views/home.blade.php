@extends('layouts.app')
@section('title', config('app.name'))
@use('Illuminate\Support\Number')

@section('content')
    <h1 class="display">{{ __('ui.nav.run') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:60ch">
        Laravel {{ app()->version() }} · {{ config('i18n.locales.'.app()->getLocale().'.native') }}
        ({{ app()->getLocale() }}) · resolved by SetLocale, no locale in the URL.
    </p>

    <div class="rule"><span class="micro">Plurals via ICU</span></div>
    <div class="panel"><div class="panel__bd">
        <table class="table table--dense">
            <thead><tr><th>count</th><th>{{ __('combat.hits', ['count' => 0]) }}</th><th>trans_choice</th></tr></thead>
            <tbody>
            @foreach ([0, 1, 5] as $n)
                <tr>
                    <td class="num">{{ $n }}</td>
                    <td>{{ trans_choice('combat.hits', $n, ['count' => $n]) }}</td>
                    <td class="muted">{{ trans_choice('combat.enemies', $n, ['count' => $n]) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div></div>

    <div class="rule"><span class="micro">Rows from the items table, words from lang</span></div>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
        {{-- Every word here is a lookup: the row carries a slug, a tier number
             and its mechanical values, and nothing a player reads. --}}
        @forelse ($items as $item)
            <div class="item-card {{ $item->tierClass() }}" style="width:100%">
                <div class="item-card__hd">
                    <div class="item-card__name">{{ $item->displayName() }}</div>
                    <div class="item-card__tier">{{ $item->tierName() }}</div>
                </div>
                <div class="item-card__stats">
                    <div class="item-card__stat">
                        <span>{{ __('items.stat.weight') }}</span>
                        <b class="num">{{ Number::format((float) $item->weight, locale: $icu) }}</b>
                    </div>
                    <div class="item-card__stat">
                        <span>{{ __('items.stat.cost') }}</span>
                        <b class="num">{{ Number::format($item->base_cost, locale: $icu) }}</b>
                    </div>
                </div>
                {{-- Flavour is optional, and a missing key would otherwise
                     render as the key itself. --}}
                @if ($flavor = $item->displayFlavor())
                    <div class="item-card__flavor">{{ $flavor }}</div>
                @endif
            </div>
        @empty
            <p class="muted" style="font-size:var(--fs-sm)">{{ __('items.catalogue_empty') }}</p>
        @endforelse
    </div>

    <div class="rule"><span class="micro">Locale-aware formatting (ext-intl)</span></div>
    <div class="panel"><div class="panel__bd">
        <div class="vitals">
            <div class="vital"><span class="vital__label">Date</span><span></span>
                <span class="vital__value">{{ $formatted['date'] }}</span></div>
            <div class="vital"><span class="vital__label">Number</span><span></span>
                <span class="vital__value">{{ $formatted['number'] }}</span></div>
            <div class="vital"><span class="vital__label">Relative</span><span></span>
                <span class="vital__value">{{ $formatted['relative'] }}</span></div>
        </div>
    </div></div>
@endsection
