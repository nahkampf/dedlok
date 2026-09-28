@props([
    'item',
    // Unique on the page: the same book can be on the shelf and on offer.
    'detailId',
    'owned' => [],
    // skill id => ['level' => int, 'points' => int], for what the character knows.
    'injected' => [],
    'maxLevel' => 0,
])
@php($skill = $item->useSkill)
{{-- A listing's name and kind. For a BOOK the header is also the way in to the
     skill it teaches: hover floats <x-skill-detail> beside it, a click or tap
     opens it as a modal — the same card and the same device as a row on the
     skills screen. The header and not the whole card, because the card holds a
     Buy button and pressing it must buy, not open a modal. --}}
@if ($skill !== null)
    <div class="card__hd" data-inspect="#{{ $detailId }}" tabindex="0" style="cursor:help">
        <div class="card__title">{{ $item->displayName() }}</div>
        <div class="card__meta">{{ $item->kindName() }}</div>
    </div>

    <template id="{{ $detailId }}">
        <x-skill-detail :skill="$skill" :max="$maxLevel"
                        :level="$injected[$skill->id]['level'] ?? null"
                        :points="$injected[$skill->id]['points'] ?? 0"
                        :owns-book="isset($owned[$item->id])"
                        :levels="array_map(fn (array $l): int => $l['level'], $injected)" />
    </template>
@else
    <div class="card__hd">
        <div class="card__title">{{ $item->displayName() }}</div>
        <div class="card__meta">{{ $item->kindName() }}</div>
    </div>
@endif
