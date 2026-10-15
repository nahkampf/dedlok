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
                        @include('market._offer', ['offer' => $offer])
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
