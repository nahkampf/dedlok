@use('App\Support\Qb')
@use('App\Support\Text')
@props(['entries'])

@php($first = $entries->first())
@php($files = $entries->sortBy(fn ($e) => [$e->extractedFile()?->grade ?? 0, $e->id])->values())

{{-- SEVERAL COMPONENTS OF ONE KIND AND ORIGIN AT ONCE (docs/CRAFTING.md). The rows stay separate — each is its own file with its own size,
     value and origin — and only the stash draws them as one slot. This card lists them, and each button opens that file's own card,
     which is where selling, destroying and putting away live. --}}
<div class="card" style="max-width:min(420px,100%)">
    <div class="card__hd">
        <div class="row" style="--gap:var(--s-2);align-items:flex-start">
            <span class="stack" style="--gap:5px;min-width:0">
                <span class="card__title">{{ $first->label() }}</span>
                <span class="micro" style="color:var(--ink-2)">{{ Text::get('inventory.group.summary', [
                    'count' => $entries->count(),
                    'qb' => Qb::format(Qb::sum($entries->map(fn ($e) => $e->extractedFile()?->sizeQb))),
                    'value' => number_format($entries->sum(fn ($e) => $e->stockPrice())),
                ]) }}</span>
            </span>
            <div class="spacer"></div>
            <button type="button" class="btn btn--sm btn--ghost" data-act data-close
                    aria-label="{{ Text::get('inventory.close') }}">✕</button>
        </div>
    </div>

    <div class="card__bd stack" style="--gap:var(--s-2)">
        @foreach ($files as $entry)
            @php($file = $entry->extractedFile())
            <button type="button" class="btn btn--ghost cut" data-act data-inspect-open="#detail-{{ $entry->id }}">
                {{ Text::get('inventory.group.row', [
                    'grade' => $file?->grade ?? '?',
                    'qb' => Qb::format($file?->sizeQb ?? 0),
                    'value' => number_format($entry->stockPrice()),
                ]) }}
            </button>
        @endforeach
    </div>
</div>
