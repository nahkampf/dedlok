@use('App\Support\Text')
@use('Illuminate\Support\Number')
@php($offer = $entry)
                        @php($item = $offer->item)
                        @php($affordable = $loyalty >= $offer->loyalty_price)
                        @php($trusted = $offer->willPartWithIt($standing))
                        @php($service = $offer->service)
                        {{-- A service is something they DO, so it has no
                             picture, no tier and no kind: what it would do for
                             this character stands where those would. --}}
                        @php($wanted = $service?->wantedBy($character) ?? true)

                        <div class="card ticked {{ $item?->tierClass() }}" data-filter-text="{{ $offer->displayName() }} {{ $kind }} {{ $item?->useSkill?->title }}">
                            @if ($item)
                                <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()" />
                                <x-owned-tick :item="$item" :owned="$owned" :injected="$injected" />
                            @endif

                            @if ($item && ! $service)
                                <x-market-card-head :item="$item" :detail-id="'offer-skill-'.$offer->id"
                                                    :owned="$owned" :injected="$injected" :max-level="$maxLevel" />
                            @else
                                <div class="card__hd">
                                    <div class="card__title">{{ $offer->displayName() }}</div>
                                    <div class="card__meta num">
                                        {{ $service ? Text::get('exchange.store_heat', ['heat' => number_format((float) $character->heat, 1)]) : '' }}
                                    </div>
                                </div>
                            @endif

                            <div class="card__bd stack" style="--gap:var(--s-2)">
                                @if ($service)
                                    <span class="micro" style="color:var(--ink-3)">{{ $service->describe((float) $offer->service_amount) }}</span>
                                @elseif ($item->displayFlavor())
                                    <span class="micro" style="color:var(--ink-3)">{{ $item->displayFlavor() }}</span>
                                @endif
                                @if ($item)
                                    <x-program-spec :item="$item" />
                                @endif

                                <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
                                    <span>{{ Text::get('exchange.store_price', ['price' => Number::format($offer->loyalty_price)]) }}</span>
                                    {{-- The SECOND gate, and only shown when it
                                         is actually in the way: almost every row
                                         asks for nothing beyond the desk's own
                                         line. --}}
                                    @unless ($trusted)
                                        <span style="color:var(--warn)">
                                            {{ Text::get('exchange.store_locked', ['standing' => number_format($offer->min_standing, 2)]) }}
                                        </span>
                                    @endunless
                                </span>
                            </div>

                            <div class="card__ft">
                                <form method="POST" action="{{ route('exchange.redeem', $offer) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--sm cut" @disabled(! $affordable || ! $trusted || ! $wanted)>
                                        {{ Text::get('exchange.store_buy') }}
                                    </button>
                                </form>

                                @if ($trusted && ! $affordable)
                                    <span class="tag">{{ Text::get('market.too_dear') }}</span>
                                @endif
                            </div>
                        </div>
