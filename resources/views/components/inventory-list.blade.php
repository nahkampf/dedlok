@use('App\Support\Text')
@props(['rows', 'hint', 'containers' => null])

@php($containers = $containers ?? collect())

{{-- One inventory's rows. Identical markup for both containers on purpose:
     they differ in what they hold, not in how they read. --}}
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
        <div>
            @foreach ($rows as $entry)
                @php($item = $entry->item)

                {{-- Where this row could be put away. Filtered in memory: the
                     controller loaded every container with its contents, so
                     asking each one what fits costs no queries. --}}
                @php($fits = $containers->filter(
                    fn ($c) => $c->id !== $entry->id
                        && $c->item->canHold($item)
                        && $c->remainingCapacity() >= $item->size
                ))

                <x-inventory-row :entry="$entry" :fits="$fits" />

                @if ($item->isContainer())
                    {{-- What is on it. A step up in background rather than a
                         border or a shadow: inside is elevation, and the kit
                         spells elevation --bg-0 through --bg-4. --}}
                    <div style="background:var(--bg-1);padding-inline-start:var(--s-4)">
                        <div class="row row--wrap" style="--gap:var(--s-2);padding:var(--s-2) var(--s-3)">
                            <span class="micro" style="color:var(--ink-3)">{{ Text::get('inventory.contents') }}</span>
                            <div class="spacer"></div>
                            <span class="micro num" style="color:var(--ink-3)">
                                {{ Text::get('inventory.contents_free', [
                                    'free' => $entry->remainingCapacity(),
                                    'capacity' => $item->capacity,
                                ]) }}
                            </span>
                        </div>

                        @forelse ($entry->children as $child)
                            <x-inventory-row :entry="$child" :fits="collect()" />
                        @empty
                            <p class="muted" style="font-size:var(--fs-sm);padding:0 var(--s-3) var(--s-2);margin:0">
                                {{ Text::get('inventory.empty') }}
                            </p>
                        @endforelse
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</div>
