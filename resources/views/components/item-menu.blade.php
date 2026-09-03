@use('App\Support\Text')
@props([
    'entry',
    // The deck this module is fitted into, when the menu is opened from a
    // board rather than from the stash. Changes one row: take it out.
    'deck' => null,
    'slot' => null,
])

@php($item = $entry->item)

{{-- The right-click menu for one row, and the dialog its one destructive
     entry opens.

     Rendered as a <template>: ui-kit.js clones it into the single floating
     .menu host, which is what lets a menu row carry a real form with a real
     CSRF token. The destroy dialog is NOT in the template — a modal cloned
     into a menu that closes the moment you click it would go with it. --}}
<template id="menu-{{ $entry->id }}">
    <div class="menu" role="menu" aria-label="{{ Text::get('inventory.menu.label') }}">
        {{-- The same card the slot itself opens, reached from the menu. --}}
        <button type="button" class="menu__item" role="menuitem"
                data-inspect-open="#detail-{{ $entry->id }}">
            {{ Text::get('inventory.menu.details') }}
        </button>

        {{-- There is no storefront. The market has a supply side and nothing
             that sells, so this is a door with nothing behind it — shown
             rather than hidden, because a menu that grows rows as features
             land is a menu nobody learns. --}}
        <button type="button" class="menu__item" role="menuitem" disabled>
            {{ Text::get('inventory.menu.market') }}
            <span class="tag">{{ Text::get('inventory.menu.not_yet') }}</span>
        </button>

        @if ($item->isModule())
            @if ($deck && $slot)
                <form method="POST" action="{{ route('deck.fit', [$deck, $slot]) }}">
                    @csrf
                    <button type="submit" class="menu__item" role="menuitem">
                        {{ Text::get('inventory.menu.unfit') }}
                    </button>
                </form>
            @else
                {{-- Which socket is the fitter's to choose. Every reason this
                     can fail is a refusal with a sentence attached, so it is
                     offered rather than disabled: "your deck has no socket for
                     this" teaches more than a greyed-out row. --}}
                <form method="POST" action="{{ route('inventory.fit', $entry) }}">
                    @csrf
                    <button type="submit" class="menu__item" role="menuitem">
                        {{ Text::get('inventory.menu.fit') }}
                    </button>
                </form>
            @endif
        @endif

        <div class="menu__sep"></div>

        <button type="button" class="menu__item" role="menuitem" disabled>
            {{ Text::get('inventory.menu.disassemble') }}
            <span class="tag">{{ Text::get('inventory.menu.not_yet') }}</span>
        </button>

        {{-- Destructive, which is one of the three things the accent is for. --}}
        <button type="button" class="menu__item menu__item--danger" role="menuitem"
                data-open="#destroy-{{ $entry->id }}">
            {{ Text::get('inventory.menu.destroy') }}
        </button>
    </div>
</template>

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
