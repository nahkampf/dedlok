@use('App\Support\Text')
@props(['entry'])

@php($item = $entry->item)

{{-- The confirmation behind an item card's Destroy button.

     Rendered beside whatever lists the item rather than inside its card: the
     card is cloned into a shared modal, and a dialog cloned along with it
     would be a second copy of this one in the top layer. --}}
<dialog class="modal" id="destroy-{{ $entry->id }}">
    <div class="panel__hd">
        <span class="micro">{{ Text::get('inventory.destroy.title', ['item' => $item->displayName()]) }}</span>
        <div class="spacer"></div>
        <button class="btn btn--sm btn--ghost" data-close aria-label="{{ Text::get('inventory.destroy.cancel') }}">✕</button>
    </div>
    <div class="panel__bd stack" style="--gap:var(--s-4)">
        <p class="item-card__note" style="padding:0">
            {{ Text::get('inventory.destroy.warning', ['item' => $item->displayName()]) }}
        </p>

        {{-- What the delete reaches. A description and never a guard — the
             same call DeletionImpact makes in the admin editor. --}}
        @if ($item->isContainer() && $entry->children->isNotEmpty())
            <p style="font-size:var(--fs-sm);margin:0">
                {{ Text::get('inventory.destroy.contents', ['count' => $entry->children->count()]) }}
            </p>
        @endif

        @if ($item->isCyberdeck())
            <p style="font-size:var(--fs-sm);margin:0">{{ Text::get('inventory.destroy.fitted') }}</p>

            @if ($entry->equippedBy)
                <p style="font-size:var(--fs-sm);margin:0">{{ Text::get('inventory.destroy.equipped') }}</p>
            @endif
        @endif

        <div class="row row--wrap" style="--gap:var(--s-3)">
            <form method="POST" action="{{ route('inventory.destroy', $entry) }}">
                @csrf
                <button type="submit" class="btn btn--danger cut">
                    {{ Text::get('inventory.destroy.confirm') }}
                </button>
            </form>
            <button type="button" class="btn btn--ghost" data-close>
                {{ Text::get('inventory.destroy.cancel') }}
            </button>
        </div>
    </div>
</dialog>
