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
                <span data-creds>{{ Number::format($creds) }}</span>
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
                    <span class="micro num" data-group-count>{{ count($shelves) }}</span>
                </summary>

                <div class="panel__bd">
                    @php($variants = $shelves->groupBy(fn ($s) => $s->item->marketSection() ?? ''))
                    @if ($variants->keys()->diff([''])->isEmpty())
                        @include('market._cards', ['shelves' => $shelves])
                    @else
                        {{-- RAM by its sticks, CPUs by their sockets, gear by what it is:
                             a kind with sections is split under them. --}}
                        <div class="stack" style="--gap:var(--s-3)">
                            @foreach ($variants->sortBy(fn ($v, $k) => [$k === '', $k]) as $variant => $sub)
                                <details class="panel" data-filter-group>
                                    <summary class="panel__hd">
                                        <span class="micro">{{ $variant !== '' ? $variant : Text::get('market.sections.other') }}</span>
                                        <div class="spacer"></div>
                                        <span class="micro num" data-group-count>{{ count($sub) }}</span>
                                    </summary>
                                    <div class="panel__bd">
                                        @include('market._cards', ['shelves' => $sub])
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @endif
                </div>
            </details>
        @endforeach

        <p class="muted" data-filter-empty hidden style="font-size:var(--fs-sm);margin:0">{{ Text::get('market.no_match') }}</p>
    </div>

    {{-- THE OTHER MARKET, and a panel of its own rather than a group inside the
         shelf: what is on offer here is one particular ROW out of one
         particular stash, so it carries a seller and no count, and the shelf's
         search filters the catalogue rather than this.

         Below the shelf because the shop is the same every day and this is
         whatever anybody happens to be holding. --}}
    <div class="panel" style="margin-top:var(--s-4)">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('market.offers.heading') }}</span>
            <div class="spacer"></div>
            <span class="micro num">{{ count($offers) }}</span>
        </div>

        <div class="panel__bd">
            @if (count($offers) === 0)
                <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('market.offers.empty') }}</p>
            @else
                <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                    @foreach ($offers as $offer)
                        @php($row = $offer->row)
                        @php($item = $row->item)
                        @php($mine = $offer->seller_character_id === $character->id)

                        {{-- Priced for the no-reload shelf purchase: spending
                             money there can put this out of reach. Not on your
                             own listing, whose only button is Withdraw. --}}
                        <div class="card ticked {{ $item->tierClass() }}"
                             @unless ($mine) data-price="{{ $offer->unit_price }}" data-left="{{ $row->quantity }}" @endunless>
                            <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()" />
                            <x-owned-tick :item="$item" :owned="$owned" :injected="$injected" />

                            <x-market-card-head :item="$item" :detail-id="'offer-skill-'.$offer->id"
                                                :owned="$owned" :injected="$injected" :max-level="$maxLevel" />

                            <div class="card__bd">
                                {{-- What is written ON the row, which is the
                                     whole reason an offer points at a row and
                                     not at a catalogue entry. --}}
                                @if ($row->scanFileSummary())
                                    <div class="micro" style="color:var(--ink-3)">{{ $row->scanFileSummary() }}</div>
                                @elseif ($row->extractedFileSummary())
                                    <div class="micro" style="color:var(--ink-3)">{{ $row->extractedFileSummary() }}</div>
                                @endif

                                <x-program-spec :item="$item" />

                                <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
                                    <span>{{ Text::get('market.price', ['price' => Number::format($offer->unit_price)]) }}</span>
                                    @if ($row->quantity > 1)
                                        <span>{{ Text::plural('market.offers.available', $row->quantity) }}</span>
                                    @endif
                                    <span>{{ Text::get("market.offers.seller", ["name" => $offer->seller?->handle]) }} <x-supporter-badge :character="$offer->seller" /></span>
                                </span>
                            </div>

                            <div class="card__ft">
                                @if ($mine)
                                    {{-- Your own listing is shown rather than
                                         hidden: the stash is where a row lives
                                         and this is where it is, so leaving it
                                         out would be a shop that does not
                                         stock what you gave it. --}}
                                    <span class="tag">{{ Text::get('market.offers.yours') }}</span>
                                    <div class="spacer"></div>
                                    <form method="POST" action="{{ route('market.withdraw', $offer) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn--sm btn--ghost cut">
                                            {{ Text::get('market.offers.withdraw') }}
                                        </button>
                                    </form>
                                @else
                                    {{-- HOW MANY, when there is more than one
                                         to want. Capped by what is on offer
                                         and by what the money reaches, the way
                                         the shelf's field is — a courtesy, and
                                         the writer refuses it anyway. --}}
                                    @php($affordable = $offer->unit_price > 0 ? intdiv($creds, $offer->unit_price) : $row->quantity)
                                    @php($most = min($row->quantity, max($affordable, 1)))

                                    <form method="POST" action="{{ route('market.take', $offer) }}"
                                          class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                                        @csrf
                                        @if ($row->quantity > 1)
                                            <label class="field" style="flex:0 0 auto;margin:0">
                                                <span class="sr-only">{{ Text::get('market.offers.quantity') }}</span>
                                                <input class="input num" type="number" name="quantity"
                                                       value="1" min="1" max="{{ $most }}"
                                                       style="width:72px"
                                                       aria-label="{{ Text::get('market.offers.quantity') }}">
                                            </label>
                                        @endif

                                        <button type="submit" class="btn btn--sm cut"
                                                @disabled($offer->unit_price > $creds)>
                                            {{ Text::get('market.offers.buy') }}
                                        </button>

                                        <span class="tag" data-too-dear @if ($offer->unit_price <= $creds) hidden @endif>{{ Text::get('market.too_dear') }}</span>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
