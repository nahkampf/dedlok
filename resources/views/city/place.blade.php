@use('App\Support\Text')
@use('Carbon\CarbonInterval')
@extends('layouts.app')
@section('title', $place->name)

@section('content')
    <p style="margin:0"><a href="{{ route('city.district', $place->district) }}">← {{ $place->district->name }}</a></p>
    <h1 style="margin-top:var(--s-2)"><span aria-hidden="true" style="color:{{ $place->district->color->token() }}">&#9672;</span> {{ $place->name }}</h1>
    @if ($place->description)
        <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ $place->description }}</p>
    @endif

    <div class="stack" style="--gap:var(--s-5);margin-top:var(--s-5)">
        @unless ($here)
            <div class="callout callout--warn">{{ Text::get('city.not_here', ['district' => $place->district->name]) }}</div>
        @endunless

        @if ($wares->isNotEmpty())
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.shop.heading') }}</span></div>
                <div class="panel__bd">
                    @include('market._grouped', ['entries' => $wares, 'itemOf' => fn ($w) => $w->item, 'card' => 'city._ware', 'id' => 'ware-shelves'])
                </div>
            </div>
        @endif

        @if ($sellable !== [])
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.shop.they_buy') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @foreach ($sellable as $entry)
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <span class="item-row__name" style="flex:1 1 160px;min-width:0">{{ $entry['row']->item->displayName() }} <span class="micro" style="color:var(--ink-3)">× {{ $entry['row']->quantity }}@if ($entry['left'] !== null) · {{ Text::get('city.shop.buy_left', ['count' => $entry['left']]) }}@endif</span></span>
                            <span class="num micro">{{ number_format($entry['unit']) }} ¤</span>
                            @if ($here)
                                <form method="POST" action="{{ route('city.sell', [$place->slug, $entry['row']->id]) }}" class="row" style="--gap:var(--s-2)">
                                    @csrf
                                    <input class="input num" type="number" name="quantity" min="1" max="{{ $entry['row']->quantity }}" value="{{ min($entry['row']->quantity, $entry['left'] ?? $entry['row']->quantity) ?: 1 }}" style="width:5.5rem" aria-label="{{ Text::get('city.shop.quantity') }}">
                                    <button type="submit" class="btn btn--sm cut" @disabled($entry['left'] === 0)>{{ Text::get('city.shop.sell') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($surgeries !== [])
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.surgery.heading') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @foreach ($surgeries as $entry)
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <span class="item-row__name" style="flex:1 1 160px;min-width:0">
                                {{ $entry['row']->item->displayName() }}
                                <span class="micro" style="color:var(--ink-3)">· {{ $entry['op']->label() }}</span>
                            </span>
                            <span class="micro" style="color:var(--ink-3)">
                                {{ Text::get('city.surgery.fee', ['fee' => number_format($entry['quote']['fee'])]) }}
                                · {{ Text::get('city.surgery.time', ['time' => CarbonInterval::seconds($entry['quote']['seconds'])->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}
                                @if ($entry['quote']['chance'] !== null)
                                    · {{ Text::get('city.surgery.chance', ['percent' => round($entry['quote']['chance'] * 100)]) }}
                                @endif
                            </span>
                            @if ($here)
                                <form method="POST" action="{{ route('city.operate', [$place->slug, $entry['row']->id, $entry['op']->value]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm cut" @disabled($character->creds < $entry['quote']['fee'])>{{ Text::get('city.surgery.begin') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($treatments !== [])
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.clinic.heading') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('city.clinic.order') }}</p>
                    @foreach ($treatments as $row)
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <span class="item-row__name" style="flex:1 1 160px;min-width:0">{{ $row['service']->label() }}</span>
                            <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.clinic.points', ['points' => $row['points'], 'price' => number_format($row['per_point'])]) }}</span>
                            @if ($here)
                                <form method="POST" action="{{ route('city.treat', [$place->slug, $row['service']->value]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm cut" @disabled($row['points'] < 1 || $character->creds < $row['total'])>
                                        {{ Text::get('city.clinic.treat', ['total' => number_format($row['total'])]) }}
                                    </button>
                                </form>
                                @if ($row['points'] > 1)
                                    <button type="button" class="btn btn--sm btn--ghost cut" data-open="#partial-{{ $row['service']->value }}">{{ Text::get('city.clinic.partial') }}</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($here)
                @foreach ($treatments as $row)
                    @if ($row['points'] > 1)
                        @php($max = min((int) ceil($row['points']) - 1, $row['per_point'] > 0 ? intdiv((int) $character->creds, $row['per_point']) : PHP_INT_MAX))
                        @php($label = Text::get('city.clinic.partial_label', ['value' => '{value}', 'total' => '{total}']))
                        <dialog class="modal" id="partial-{{ $row['service']->value }}">
                            <div class="panel__hd">
                                <span class="micro">{{ Text::get('city.clinic.partial_title', ['service' => $row['service']->label()]) }}</span>
                                <div class="spacer"></div>
                                <button type="button" class="btn btn--sm btn--ghost" data-close aria-label="{{ Text::get('city.clinic.partial_close') }}">✕</button>
                            </div>
                            <form method="POST" action="{{ route('city.treat', [$place->slug, $row['service']->value]) }}" class="panel__bd stack" style="--gap:var(--s-4)">
                                @csrf
                                <label class="field">
                                    <span data-range-out>{{ str_replace(['{value}', '{total}'], [1, number_format((int) ceil($row['per_point']))], $label) }}</span>
                                    <input class="range" type="range" name="points" min="1" max="{{ $max }}" step="1" value="1"
                                           data-unit-price="{{ $row['per_point'] }}" data-format="{{ $label }}">
                                </label>
                                <div class="row row--wrap" style="--gap:var(--s-3)">
                                    <button type="submit" class="btn cut">{{ Text::get('city.clinic.partial_go') }}</button>
                                </div>
                            </form>
                        </dialog>
                    @endif
                @endforeach
            @endif
        @endif

        @if ($menu->isNotEmpty())
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.food.heading') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @foreach ($menu as $dish)
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <span class="item-row__name" style="flex:1 1 160px;min-width:0">{{ $dish->name }}</span>
                            <span class="micro" style="color:var(--ink-3)">{{ Text::get('safehouse.meal', ['fullness' => rtrim(rtrim(number_format($dish->meal_fullness, 2), '0'), '.'), 'fatigue' => rtrim(rtrim(number_format($dish->meal_fatigue, 2), '0'), '.')]) }}</span>
                            @if ($here)
                                <form method="POST" action="{{ route('city.eat', [$place->slug, $dish->id]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm cut" @disabled($character->creds < $dish->price)>{{ Text::get('city.food.eat', ['price' => number_format($dish->price)]) }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($happenings->isNotEmpty())
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.encounter.at_place') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @foreach ($happenings as $happening)
                        <form method="POST" action="{{ route('city.place.encounter', [$place->slug, $happening->id]) }}">
                            @csrf
                            <button type="submit" class="btn cut">{{ $happening->title }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($wares->isEmpty() && $menu->isEmpty() && $treatments === [] && $sellable === [] && $surgeries === [] && $happenings->isEmpty())
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('city.nothing_to_do') }}</p>
        @endif
    </div>
@endsection
