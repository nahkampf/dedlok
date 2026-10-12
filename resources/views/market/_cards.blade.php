@use('App\Support\Text')
@use('Illuminate\Support\Number')
{{-- One grid of shelf cards: a whole kind, or one variant of it. Reads $shelves, $creds, $owned, $injected, $maxLevel. --}}
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
        @foreach ($shelves as $shelf)
            @php($item = $shelf->item)
            @php($price = (int) $item->base_cost)
            @php($affordable = $price > 0 ? intdiv($creds, $price) : $shelf->quantity)
            @php($most = min($shelf->quantity, max($affordable, 1)))

            {{-- data-price and data-left are what a purchase
                 that does not reload the page re-reads, to say
                 whether this card can still be afforded. --}}
            <div class="card ticked {{ $item->tierClass() }}"
                 data-price="{{ $price }}" data-left="{{ $shelf->quantity }}"
                 data-filter-text="{{ $item->displayName() }} {{ $kind }} {{ $item->useSkill?->title }} {{ $item->flavor }}">
                <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()" />
                {{-- After the picture: both are positioned, so
                     the later one paints on top. --}}
                <x-owned-tick :item="$item" :owned="$owned" :injected="$injected" />

                <x-market-card-head :item="$item" :detail-id="'shelf-skill-'.$shelf->id"
                                    :owned="$owned" :injected="$injected" :max-level="$maxLevel" />

                <div class="card__bd stack" style="--gap:var(--s-2)">
                    @if ($item->flavor)
                        <span class="micro" style="color:var(--ink-3)">{{ $item->flavor }}</span>
                    @endif

                    <x-program-spec :item="$item" />

                    <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
                        <span>{{ Text::get('market.price', ['price' => Number::format($price)]) }}</span>
                        <span data-left-text>{{ Text::plural('market.on_shelf', $shelf->quantity) }}</span>
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
                    <form method="POST" action="{{ route('market.buy', $item) }}" data-buy
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

                        {{-- Always rendered, hidden while it is
                             not true: spending money here can
                             make it true without a reload. --}}
                        <span class="tag" data-too-dear @if ($price <= $creds) hidden @endif>{{ Text::get('market.too_dear') }}</span>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
