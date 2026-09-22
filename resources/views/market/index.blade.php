@use('App\Support\Text')
@use('Illuminate\Support\Number')
@extends('layouts.app')
@section('title', Text::get('market.title'))

@section('content')
    <h1>{{ Text::get('market.title') }}</h1>
    <p class="lede" style="margin-top:var(--s-3);max-width:70ch">{{ Text::get('market.lede') }}</p>

    <div class="panel" style="margin-top:var(--s-5)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('market.shelf') }}</span>
            <div class="spacer"></div>
            {{-- What is in the account, on the screen that spends it. The bar
                 carries it too, but a price is read against a balance and the
                 two should not be a scroll apart. --}}
            <span class="chip-stat num">
                <span class="chip-stat__glyph" aria-hidden="true">&#164;</span>
                <span>{{ Number::format($creds) }}</span>
            </span>
        </div>

        <div class="panel__bd">
            @if (count($groups) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('market.empty') }}</p>
            @else
                {{-- Filters in the page, not a query: everything on the shelf
                     is already here, and a round trip per keystroke would buy
                     nothing. Matching groups open while there is a query. --}}
                <label class="field" style="margin:0;max-width:min(360px,100%)">
                    <span class="sr-only">{{ Text::get('market.search') }}</span>
                    <input class="input" type="search" data-filter="#shelves"
                           placeholder="{{ Text::get('market.search_placeholder') }}"
                           aria-label="{{ Text::get('market.search') }}">
                </label>
            @endif
        </div>
    </div>

    <div id="shelves" class="stack" style="--gap:var(--s-3);margin-top:var(--s-4)">
        @foreach ($groups as $kind => $shelves)
            <details class="panel" data-filter-group>
                <summary class="panel__hd">
                    <span class="micro">{{ $kind }}</span>
                    <div class="spacer"></div>
                    <span class="micro num">{{ count($shelves) }}</span>
                </summary>

                <div class="panel__bd">
                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                        @foreach ($shelves as $shelf)
                            @php($item = $shelf->item)
                            @php($price = (int) $item->base_cost)
                            @php($affordable = $price > 0 ? intdiv($creds, $price) : $shelf->quantity)
                            @php($most = min($shelf->quantity, max($affordable, 1)))

                            <div class="card {{ $item->tierClass() }}"
                                 data-filter-text="{{ $item->displayName() }} {{ $kind }} {{ $item->useSkill?->title }} {{ $item->flavor }}">
                                <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()" />

                                <div class="card__hd">
                                    <div class="card__title">{{ $item->displayName() }}</div>
                                    <div class="card__meta">{{ $item->kindName() }}</div>
                                </div>

                                <div class="card__bd stack" style="--gap:var(--s-2)">
                                    @if ($item->flavor)
                                        <span class="micro" style="color:var(--ink-3)">{{ $item->flavor }}</span>
                                    @endif

                                    <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
                                        <span>{{ Text::get('market.price', ['price' => Number::format($price)]) }}</span>
                                        <span>{{ Text::plural('market.on_shelf', $shelf->quantity) }}</span>
                                    </span>
                                </div>

                                <div class="card__ft">
                                    {{-- An ordinary button and never `btn--go`:
                                         the green one is rationed to one per
                                         screen, and a shelf of twenty of them
                                         would say nothing at all.

                                         One form per row. The quantity is capped by
                                         what is on the shelf and by what the money
                                         reaches, so the field cannot ask for
                                         something the writer would refuse — and the
                                         writer refuses it anyway, because a cap in
                                         a form is a courtesy rather than a rule. --}}
                                    <form method="POST" action="{{ route('market.buy', $item) }}"
                                          class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                                        @csrf
                                        <label class="field" style="flex:0 0 auto;margin:0">
                                            <span class="sr-only">{{ Text::get('market.quantity') }}</span>
                                            <input class="input num" type="number" name="quantity"
                                                   value="1" min="1" max="{{ $most }}"
                                                   style="width:72px"
                                                   aria-label="{{ Text::get('market.quantity') }}">
                                        </label>

                                        <button type="submit" class="btn btn--sm cut"
                                                @disabled($price > $creds)>
                                            {{ Text::get('market.buy') }}
                                        </button>

                                        @if ($price > $creds)
                                            <span class="tag">{{ Text::get('market.too_dear') }}</span>
                                        @endif
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </details>
        @endforeach

        <p class="muted" data-filter-empty hidden style="font-size:var(--fs-sm);margin:0">{{ Text::get('market.no_match') }}</p>
    </div>
@endsection
