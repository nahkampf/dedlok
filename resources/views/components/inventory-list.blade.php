@props(['rows', 'hint'])

{{-- One inventory's rows. Identical markup for both containers on purpose:
     they differ in what they hold, not in how they read. --}}
<div class="panel">
    <div class="panel__hd">
        <span class="micro">{{ $hint }}</span>
        <div class="spacer"></div>
        <span class="micro num">{{ trans_choice('inventory.count', $rows->sum('quantity')) }}</span>
    </div>

    @if ($rows->isEmpty())
        <div class="panel__bd">
            <p class="muted" style="font-size:var(--fs-sm);margin:0">{{ __('inventory.empty') }}</p>
        </div>
    @else
        <div>
            @foreach ($rows as $entry)
                @php($item = $entry->item)
                <div class="item-row {{ $item->tierClass() }}">
                    {{-- Both glyphs are from the set the gallery already proves
                         renders in the kit's font stack. U+2317 does not. --}}
                    <span class="slot__icon">{{ $item->isSkillbook() ? '◈' : '▤' }}</span>

                    <span class="stack" style="--gap:2px;min-width:0">
                        <span class="item-row__name">{{ $item->displayName() }}</span>
                        @if ($item->isSkillbook() && $item->useSkill)
                            <span class="micro" style="color:var(--ink-3)">
                                {{ __('inventory.teaches', ['skill' => $item->useSkill->title]) }}
                            </span>
                        @endif
                    </span>

                    {{-- Meta and action wrap together: at 320px the name column
                         keeps its minmax(0,1fr) and this stacks instead of
                         pushing the row sideways. --}}
                    <span class="row row--wrap" style="--gap:var(--s-2);justify-content:flex-end">
                        @if ($entry->quantity > 1)
                            <span class="micro num" style="color:var(--ink-2)">
                                {{ __('inventory.quantity', ['count' => $entry->quantity]) }}
                            </span>
                        @endif

                        <span class="item-row__meta">{{ $item->tierName() }}</span>

                        @if ($item->isSkillbook() && $item->useSkill)
                            <button type="button" class="btn btn--sm cut"
                                    data-open="#inject-{{ $entry->id }}">
                                {{ __('inventory.inject') }}
                            </button>
                        @endif
                    </span>
                </div>

                @if ($item->isSkillbook() && $item->useSkill)
                    {{-- Spending a book is destructive and cannot be undone, so
                         it gets a confirmation rather than a bare submit. --}}
                    <dialog class="modal" id="inject-{{ $entry->id }}">
                        <div class="panel__hd">
                            <span class="micro">{{ __('inventory.inject') }}</span>
                            <div class="spacer"></div>
                            <button class="btn btn--sm btn--ghost" data-close>✕</button>
                        </div>
                        <div class="panel__bd stack" style="--gap:var(--s-4)">
                            <p style="font-size:var(--fs-sm);margin:0">
                                {{ __('inventory.inject_confirm', ['skill' => $item->useSkill->title]) }}
                            </p>
                            <form method="POST" action="{{ route('inventory.inject', $entry) }}">
                                @csrf
                                <button type="submit" class="btn btn--primary btn--block cut">
                                    {{ __('inventory.inject') }}
                                </button>
                            </form>
                        </div>
                    </dialog>
                @endif
            @endforeach
        </div>
    @endif
</div>
