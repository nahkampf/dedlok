@use('App\Support\Text')
{{-- A shop's listings as the market draws them: a search box over accordions by
     kind, split by section where a kind has them. Reads $entries, $itemOf (entry
     => ?Item), $card (the view drawing one) and $id (unique on the page); the
     card view gets $entry and the parent's variables. --}}
@php($other = Text::get('market.sections.other'))
@php($groups = $entries->groupBy(fn ($e) => $itemOf($e)?->marketCategory() ?? $other)->sortKeys())

<div class="stack" style="--gap:var(--s-3)">
    <label class="field" style="margin:0;max-width:min(360px,100%)">
        <span class="sr-only">{{ Text::get('market.search') }}</span>
        <input class="input" type="search" data-filter="#{{ $id }}"
               placeholder="{{ Text::get('market.search_placeholder') }}"
               aria-label="{{ Text::get('market.search') }}">
    </label>

    <div id="{{ $id }}" class="stack" style="--gap:var(--s-3)">
        @foreach ($groups as $kind => $list)
            <details class="panel" data-filter-group>
                <summary class="panel__hd">
                    <span class="micro">{{ $kind }}</span>
                    <div class="spacer"></div>
                    <span class="micro num" data-group-count>{{ count($list) }}</span>
                </summary>
                <div class="panel__bd">
                    @php($variants = $list->groupBy(fn ($e) => $itemOf($e)?->marketSection() ?? ''))
                    @foreach ($variants->sortBy(fn ($v, $k) => [$k === '', $k]) as $variant => $sub)
                        @if ($variants->keys()->diff([''])->isEmpty())
                            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                                @foreach ($sub as $entry) @include($card, ['entry' => $entry, 'kind' => $kind]) @endforeach
                            </div>
                        @else
                            <details class="panel" data-filter-group style="margin-top:var(--s-3)">
                                <summary class="panel__hd">
                                    <span class="micro">{{ $variant !== '' ? $variant : $other }}</span>
                                    <div class="spacer"></div>
                                    <span class="micro num" data-group-count>{{ count($sub) }}</span>
                                </summary>
                                <div class="panel__bd">
                                    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr))">
                                        @foreach ($sub as $entry) @include($card, ['entry' => $entry, 'kind' => $kind]) @endforeach
                                    </div>
                                </div>
                            </details>
                        @endif
                    @endforeach
                </div>
            </details>
        @endforeach

        <p class="muted" data-filter-empty hidden style="font-size:var(--fs-sm);margin:0">{{ Text::get('market.no_match') }}</p>
    </div>
</div>
