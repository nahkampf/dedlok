@use('App\Support\Text')
@props(['rows', 'hint', 'containers' => null])

@php($containers = $containers ?? collect())

{{-- One inventory, as slots. Identical markup for both on purpose: they differ
     in what they hold, not in how they read.

     There are no empty or locked slots padding the grid out. Neither inventory
     has a capacity, so a run of empties would draw a limit that does not exist
     — .slots--fluid simply fits as many columns as the viewport holds. --}}
<div class="panel">
    <div class="panel__hd">
        <span class="micro">{{ $hint }}</span>
        <div class="spacer"></div>
        <span class="micro num">{{ Text::plural('inventory.count', $rows->sum('quantity')) }}</span>
    </div>

    @if ($rows->isEmpty())
        <div class="panel__bd">
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ Text::get('inventory.empty') }}</p>
        </div>
    @else
        <div class="panel__bd stack" style="--gap:var(--s-3)">
            <div class="slots slots--fluid" style="--slot-min:56px">
                @foreach ($rows as $entry)
                    @php($item = $entry->item)

                    {{-- Where this row could be put away. Filtered in memory:
                         the controller loaded every container with its
                         contents, so asking each one what fits costs no
                         queries. --}}
                    @php($fits = $containers->filter(
                        fn ($c) => $c->id !== $entry->id
                            && $c->item->canHold($item)
                            && $c->remainingCapacity() >= $item->size
                    ))

                    {{-- A <button>, not a <div>: the slot is the control that
                         opens the detail, so it has to be reachable by keyboard
                         and announce itself. The kit's .slot carries the button
                         reset that makes the two look identical. --}}
                    <button type="button"
                        class="slot slot--filled {{ $item->tierClass() }}"
                        data-inspect="#detail-{{ $entry->id }}"
                        aria-haspopup="dialog"
                        {{-- No title attribute: the browser's own tooltip
                             would sit on top of the inspector saying half of
                             what it already says. --}}
                        aria-label="{{ $item->displayName() }} — {{ Text::get('inventory.inspect') }}">
                        <span class="slot__icon" aria-hidden="true">{{ $item->glyph() }}</span>

                        @if ($entry->quantity > 1)
                            <span class="slot__qty">{{ $entry->quantity }}</span>
                        @endif

                        @if ($item->isContainer())
                            {{-- How full it is, as the bar the kit already puts
                                 inside a slot. Not data-low: a full card is a
                                 card doing its job, not a warning. --}}
                            <span class="slot__dur">
                                <i style="--v:{{ $item->capacity > 0 ? round($entry->usedCapacity() / $item->capacity, 3) : 0 }}"></i>
                            </span>
                        @endif
                    </button>

                    {{-- The detail, once. ui-kit.js clones it into the floating
                         inspector on hover and into a modal on click. --}}
                    <template id="detail-{{ $entry->id }}">
                        <x-item-detail :entry="$entry" :fits="$fits" />
                    </template>

                    <x-item-destroy :entry="$entry" />
                @endforeach
            </div>

            <p class="micro" style="color:var(--ink-3);margin:0">{{ Text::get('inventory.inspect_hint') }}</p>
        </div>
    @endif
</div>
