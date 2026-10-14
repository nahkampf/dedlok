@use('App\Support\Text')
@php($ware = $entry)
                            @php($item = $ware->item)
                            @php($buyable = $here && ($ware->isUnlimited() || $ware->stock > 0))
                            <div class="card ticked {{ $item->tierClass() }}" data-filter-text="{{ $item->displayName() }} {{ $kind }} {{ $item->useSkill?->title }} {{ $item->flavor }}">
                                <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()" />
                                <x-owned-tick :item="$item" :owned="$owned" :injected="$injected" />

                                <x-market-card-head :item="$item" :detail-id="'ware-skill-'.$ware->id"
                                                    :owned="$owned" :injected="$injected" :max-level="$maxLevel" />

                                <div class="card__bd stack" style="--gap:var(--s-2)">
                                    @if ($item->flavor)
                                        <span class="micro" style="color:var(--ink-3)">{{ $item->flavor }}</span>
                                    @endif

                                    <x-program-spec :item="$item" />

                                    <span class="row row--wrap micro num" style="--gap:var(--s-3);color:var(--ink-3)">
                                        <span>{{ Text::get('market.price', ['price' => number_format($ware->unitPrice())]) }}</span>
                                        @unless ($ware->isUnlimited())
                                            <span>{{ $ware->stock > 0 ? Text::get('city.shop.in_stock', ['count' => $ware->stock]) : Text::get('city.shop.sold_out') }}</span>
                                        @endunless
                                    </span>
                                </div>

                                @if ($buyable)
                                    <div class="card__ft">
                                        <form method="POST" action="{{ route('city.buy', [$place->slug, $ware->item_id]) }}"
                                              class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                                            @csrf
                                            <label class="field" style="flex:0 0 auto;margin:0">
                                                <span class="sr-only">{{ Text::get('city.shop.quantity') }}</span>
                                                <input class="input num" type="number" name="quantity" min="1" max="99" value="1"
                                                       style="width:72px" aria-label="{{ Text::get('city.shop.quantity') }}">
                                            </label>
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
                                    </div>
                                @endif
                            </div>
