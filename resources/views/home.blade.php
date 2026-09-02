@extends('layouts.app')
@section('title', config('app.name'))

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

    <div class="rule"><span class="micro">Content from lang files</span></div>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
        @foreach ($items as $item)
            <div class="item-card {{ $item['tier_class'] }}" style="width:100%">
                <div class="item-card__hd">
                    <div class="item-card__name">{{ __("items.{$item['slug']}.name") }}</div>
                    <div class="item-card__tier">{{ __("items.{$item['slug']}.tier") }}</div>
                </div>
                <div class="item-card__stats">
                    <div class="item-card__stat"><span>Damage</span><b class="num">{{ $item['damage'] }}</b></div>
                    <div class="item-card__stat"><span>Weight</span><b class="num">{{ $item['weight'] }}</b></div>
                </div>
                <div class="item-card__flavor">{{ __("items.{$item['slug']}.flavor") }}</div>
            </div>
        @endforeach
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
