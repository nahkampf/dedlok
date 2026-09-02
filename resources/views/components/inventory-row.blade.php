@use('App\Support\Text')
@props(['entry', 'fits'])

@php($item = $entry->item)

{{-- One row, in either inventory and at either depth. A row inside a
     container differs only in what it can be asked to do: it can come out,
     and it cannot go anywhere else while it is in there. --}}
<div class="item-row {{ $item->tierClass() }}">
    {{-- Both glyphs are from the set the gallery already proves renders in
         the kit's font stack. U+2317 does not. --}}
    <span class="slot__icon">{{ $item->isSkillbook() ? '◈' : '▤' }}</span>

    <span class="stack" style="--gap:2px;min-width:0">
        <span class="item-row__name">{{ $item->displayName() }}</span>
        @if ($item->isSkillbook() && $item->useSkill)
            <span class="micro" style="color:var(--ink-3)">
                {{ Text::get('inventory.teaches', ['skill' => $item->useSkill->title]) }}
            </span>
        @endif
    </span>

    {{-- Meta and actions wrap together: at 320px the name column keeps its
         minmax(0,1fr) and this stacks instead of pushing the row sideways. --}}
    <span class="row row--wrap" style="--gap:var(--s-2);justify-content:flex-end">
        @if ($entry->quantity > 1)
            <span class="micro num" style="color:var(--ink-2)">
                {{ Text::get('inventory.quantity', ['count' => $entry->quantity]) }}
            </span>
        @endif

        <span class="item-row__meta">{{ $item->tierName() }}</span>

        @if ($entry->parent_id !== null)
            <form method="POST" action="{{ route('inventory.place', $entry) }}">
                @csrf
                <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('inventory.eject') }}</button>
            </form>
        @elseif ($fits->isNotEmpty())
            <button type="button" class="btn btn--sm cut" data-open="#store-{{ $entry->id }}">
                {{ Text::get('inventory.store') }}
            </button>
        @endif

        @if ($item->isSkillbook() && $item->useSkill)
            <button type="button" class="btn btn--sm cut" data-open="#inject-{{ $entry->id }}">
                {{ Text::get('inventory.inject') }}
            </button>
        @endif
    </span>
</div>

@if ($entry->parent_id === null && $fits->isNotEmpty())
    {{-- Which container, when there is more than one. A modal even for a
         single choice, so the answer to "where did it go" is always read
         before the click rather than after it. --}}
    <dialog class="modal" id="store-{{ $entry->id }}">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('inventory.store') }}</span>
            <div class="spacer"></div>
            <button class="btn btn--sm btn--ghost" data-close>✕</button>
        </div>
        <div class="panel__bd stack" style="--gap:var(--s-3)">
            @foreach ($fits as $container)
                <form method="POST" action="{{ route('inventory.place', $entry) }}">
                    @csrf
                    <input type="hidden" name="parent" value="{{ $container->id }}">
                    <button type="submit" class="btn btn--block cut">
                        {{ Text::get('inventory.store_in', ['container' => $container->item->displayName()]) }}
                    </button>
                </form>
            @endforeach
        </div>
    </dialog>
@endif

@if ($item->isSkillbook() && $item->useSkill)
    {{-- Spending a book is destructive and cannot be undone, so it gets a
         confirmation rather than a bare submit. --}}
    <dialog class="modal" id="inject-{{ $entry->id }}">
        <div class="panel__hd">
            <span class="micro">{{ Text::get('inventory.inject') }}</span>
            <div class="spacer"></div>
            <button class="btn btn--sm btn--ghost" data-close>✕</button>
        </div>
        <div class="panel__bd stack" style="--gap:var(--s-4)">
            <p style="font-size:var(--fs-sm);margin:0">
                {{ Text::get('inventory.inject_confirm', ['skill' => $item->useSkill->title]) }}
            </p>
            <form method="POST" action="{{ route('inventory.inject', $entry) }}">
                @csrf
                <button type="submit" class="btn btn--primary btn--block cut">
                    {{ Text::get('inventory.inject') }}
                </button>
            </form>
        </div>
    </dialog>
@endif
