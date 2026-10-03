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
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @foreach ($wares as $ware)
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <span class="item-row__name" style="flex:1 1 160px;min-width:0">{{ $ware->item->displayName() }}</span>
                            <span class="num micro">{{ number_format($ware->unitPrice()) }} ¤</span>
                            @unless ($ware->isUnlimited())
                                <span class="micro" style="color:var(--ink-3)">{{ $ware->stock > 0 ? Text::get('city.shop.in_stock', ['count' => $ware->stock]) : Text::get('city.shop.sold_out') }}</span>
                            @endunless
                            @if ($here && ($ware->isUnlimited() || $ware->stock > 0))
                                <form method="POST" action="{{ route('city.buy', [$place->slug, $ware->item_id]) }}" class="row" style="--gap:var(--s-2)">
                                    @csrf
                                    <input class="input num" type="number" name="quantity" min="1" max="99" value="1" style="width:5.5rem" aria-label="{{ Text::get('city.shop.quantity') }}">
                                    <button type="submit" class="btn btn--sm cut">{{ Text::get('city.shop.buy') }}</button>
                                </form>
                                @if (isset($fits[$ware->item_id]))
                                    @php($fit = $fits[$ware->item_id])
                                    @if (is_array($fit))
                                        <form method="POST" action="{{ route('city.buy_and_fit', [$place->slug, $ware->item_id]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn--sm cut" @disabled($character->creds < $ware->unitPrice() + $fit['fee'])>
                                                {{ Text::get('city.surgery.buy_and_fit', ['total' => number_format($ware->unitPrice() + $fit['fee']), 'percent' => round($fit['chance'] * 100)]) }}
                                            </button>
                                        </form>
                                    @elseif ($fit === \App\City\CityRefusal::NoRoom)
                                        <span class="micro" style="color:var(--ink-3)">{{ Text::get('city.surgery.no_room') }}</span>
                                    @endif
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($sellable !== [])
            <div class="panel">
                <div class="panel__hd"><span class="micro">{{ Text::get('city.shop.they_buy') }}</span></div>
                <div class="panel__bd stack" style="--gap:var(--s-3)">
                    @foreach ($sellable as $entry)
                        <div class="row row--wrap" style="--gap:var(--s-3);align-items:center">
                            <span class="item-row__name" style="flex:1 1 160px;min-width:0">{{ $entry['row']->item->displayName() }} <span class="micro" style="color:var(--ink-3)">× {{ $entry['row']->quantity }}</span></span>
                            <span class="num micro">{{ number_format($entry['unit']) }} ¤</span>
                            @if ($here)
                                <form method="POST" action="{{ route('city.sell', [$place->slug, $entry['row']->id]) }}" class="row" style="--gap:var(--s-2)">
                                    @csrf
                                    <input class="input num" type="number" name="quantity" min="1" max="{{ $entry['row']->quantity }}" value="{{ $entry['row']->quantity }}" style="width:5.5rem" aria-label="{{ Text::get('city.shop.quantity') }}">
                                    <button type="submit" class="btn btn--sm cut">{{ Text::get('city.shop.sell') }}</button>
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
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($wares->isEmpty() && $treatments === [] && $sellable === [] && $surgeries === [])
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('city.nothing_to_do') }}</p>
        @endif
    </div>
@endsection
