@use('App\Support\Text')
@use('Illuminate\Support\Number')
{{-- One player offer as a card. Reads $offer, $character, $creds, $owned, $injected, $maxLevel. --}}
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
                @if ($offer->recipient_character_id !== null)
                    <span class="tag">{{ $mine ? Text::get('market.offers.contract_for', ['name' => $offer->recipient?->handle]) : Text::get('market.offers.contract_to_you') }}</span>
                @endif
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

    {{-- Copies "market:<id>", which a chat line or a bio turns into a button that opens this card. --}}
    <button type="button" class="btn btn--sm btn--ghost cut" style="margin-left:auto"
            data-copy="market:{{ $offer->id }}"
            data-copied="{{ Text::get('cyberspace.reading.copied') }}"
            data-copy-failed="{{ Text::get('cyberspace.reading.copy_failed') }}"
            title="{{ Text::get('market.offers.copy_link') }}">#</button>
        </div>
    </div>
