@use('App\Models\Item')
@use('App\Support\Text')
@props([
    'entry',
    'fits' => null,
    // Set only when this card is opened from a deck's board: the deck object
    // and the socket the module is in, which is what "take it out" needs.
    // `socket` and not `slot` — $slot is reserved in a Blade component.
    'deck' => null,
    'socket' => null,
])

@php($item = $entry->item)
@php($fits = $fits ?? collect())

{{-- The whole of what is known about one row, written once.
     ui-kit.js clones this into the floating inspector on hover and into a
     modal on click, so the two presentations cannot drift. Anything marked
     [data-act] is hidden in the floating copy: a hover layer is not somewhere
     a click can land, because the pointer has to cross the gap to reach a
     button and the layer is gone by then. --}}
<div class="item-card {{ $item->tierClass() }}">
    <div class="item-card__hd">
        <div class="row" style="--gap:var(--s-2);align-items:flex-start">
            <span class="stack" style="--gap:5px;min-width:0">
                <span class="item-card__name">{{ $item->displayName() }}</span>
                {{-- Kind first: what the thing IS identifies it, and the tier
                     qualifies that. "RAM, Tier One" is how you would say it.

                     The kind is boxed and the tier is not: a tag reads as a
                     classification, and two chips would compete to be the
                     label.

                     The tag is --warn rather than --ok so that it cannot
                     collide with the tier beside it: --tier is jade on a Tier
                     Two item, which is the same colour --ok is, and the two
                     ran together into one green blob. Amber against jade or
                     against grey stays two things, so the tier keeps its own
                     colour. --}}
                <span class="row row--wrap" style="--gap:var(--s-2)">
                    <span class="tag tag--warn">{{ $item->kindName() }}</span>
                    <span class="item-card__tier">{{ $item->tierName() }}</span>
                </span>
            </span>
            <div class="spacer"></div>
            <button type="button" class="btn btn--sm btn--ghost" data-act data-close
                aria-label="{{ Text::get('inventory.close') }}">✕</button>
        </div>

        @if ($item->isSkillbook() && $item->useSkill)
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ Text::get('inventory.teaches', ['skill' => $item->useSkill->title]) }}
            </div>
        @elseif ($item->hardwareSummary())
            {{-- A deck's sockets or a module's numbers. It is what the item IS,
                 so it reads where the skillbook's skill does. --}}
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ $item->hardwareSummary() }}
            </div>
        @endif
    </div>

    @php($stats = collect([
        Text::get('inventory.stats.quantity') => $entry->quantity > 1 ? (string) $entry->quantity : null,
        Text::get('inventory.stats.weight') => $item->weight > 0 ? rtrim(rtrim((string) $item->weight, '0'), '.') : null,
        Text::get('inventory.stats.size') => $item->size > 0 ? (string) $item->size : null,
        Text::get('inventory.stats.capacity') => $item->isContainer()
            ? Text::get('inventory.capacity_used', [
                'used' => $entry->usedCapacity(),
                'capacity' => $item->capacity,
            ])
            : null,
    ])->filter())

    @if ($stats->isNotEmpty())
        <div class="item-card__stats">
            @foreach ($stats as $label => $value)
                <div class="item-card__stat"><span>{{ $label }}</span><b>{{ $value }}</b></div>
            @endforeach
        </div>
    @endif

    @if ($item->displayFlavor())
        <div class="item-card__flavor">{{ $item->displayFlavor() }}</div>
    @endif

    @if ($item->isContainer())
        {{-- What is on it. The grid shows one slot per loose row, so a card is
             the only place the contents of a container are visible at all. --}}
        <div class="item-card__contents">
            <span class="micro">
                {{ Text::get('inventory.contents') }} ·
                {{ Text::get('inventory.contents_free', [
                    'free' => $entry->remainingCapacity(),
                    'capacity' => $item->capacity,
                ]) }}
            </span>

            @forelse ($entry->children as $child)
                {{-- A row inside a container has no slot of its own, so this
                     is the only place its two actions can be offered. --}}
                <div class="item-row {{ $child->item->tierClass() }}">
                    <span class="slot__icon">{{ $child->item->glyph() }}</span>
                    <span class="item-row__name">{{ $child->item->displayName() }}</span>
                    <span class="row row--wrap" style="--gap:var(--s-2);justify-content:flex-end">
                        <span class="item-row__meta">
                            @if ($child->quantity > 1)
                                {{ Text::get('inventory.quantity', ['count' => $child->quantity]) }}
                            @else
                                {{ $child->item->tierName() }}
                            @endif
                        </span>
                        @if ($child->item->isSkillbook() && $child->item->useSkill)
                            <form method="POST" action="{{ route('inventory.inject', $child) }}" data-act>
                                @csrf
                                <button type="submit" class="btn btn--sm cut">
                                    {{ Text::get('inventory.inject') }}
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('inventory.place', $child) }}" data-act>
                            @csrf
                            <button type="submit" class="btn btn--sm btn--ghost cut">
                                {{ Text::get('inventory.eject') }}
                            </button>
                        </form>
                    </span>
                </div>
            @empty
                <p class="muted" style="font-size:var(--fs-xs);padding:0 var(--s-3) var(--s-3);margin:0">
                    {{ Text::get('inventory.empty') }}
                </p>
            @endforelse

            @if ($entry->children->contains(fn ($child) => $child->item->isSkillbook()))
                {{-- Once for the whole list rather than once per book: the
                     sentence is the same one every time, and a card holding
                     four books would otherwise say it four times. --}}
                <p class="item-card__note" data-act>{{ Text::get('inventory.inject_confirm_any') }}</p>
            @endif
        </div>
    @endif

    @if ($item->isSkillbook() && $item->useSkill)
        {{-- Spending a book cannot be undone, so the sentence that says so is
             part of the card rather than a second dialog on top of this one. --}}
        <p class="item-card__note" data-act>
            {{ Text::get('inventory.inject_confirm', ['skill' => $item->useSkill->title]) }}
        </p>
    @endif

    {{-- Everything you can do to this item, in the one place you already have
         it open. There was a right-click menu here instead; it had no mobile
         story and nothing on screen said it existed. --}}
    <div class="item-card__actions" data-act>
            @if ($item->isCyberdeck())
                {{-- The deck has a screen of its own: a board rather than a
                     spec line. It is reached from the object you own, which is
                     the only thing that knows WHICH deck. --}}
                <a class="btn btn--sm cut" href="{{ route('deck.show', $entry) }}">
                    {{ Text::get('decks.board.open') }}
                </a>
            @endif

            @if ($item->isSkillbook() && $item->useSkill)
                <form method="POST" action="{{ route('inventory.inject', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--primary cut">
                        {{ Text::get('inventory.inject') }}
                    </button>
                </form>
            @endif

            @if ($entry->parent_id !== null)
                <form method="POST" action="{{ route('inventory.place', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--ghost cut">
                        {{ Text::get('inventory.eject') }}
                    </button>
                </form>
            @else
                {{-- One button per container it fits, because "where did it go"
                     should be answered before the click rather than after it. --}}
                @foreach ($fits as $container)
                    <form method="POST" action="{{ route('inventory.place', $entry) }}">
                        @csrf
                        <input type="hidden" name="parent" value="{{ $container->id }}">
                        <button type="submit" class="btn btn--sm cut">
                            {{ Text::get('inventory.store_in', ['container' => $container->item->displayName()]) }}
                        </button>
                    </form>
                @endforeach
            @endif

            @if ($deck && $socket)
                {{-- Fitted, and this card was opened from the board. --}}
                <form method="POST" action="{{ route('deck.fit', [$deck, $socket]) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--ghost cut">
                        {{ Text::get('inventory.actions.unfit') }}
                    </button>
                </form>
            @elseif ($item->isModule())
                {{-- Which socket is the fitter's to choose. Every way this can
                     fail is a refusal with a sentence attached, so it is
                     offered rather than hidden: "your deck has no socket for
                     this" teaches more than an absent button. --}}
                <form method="POST" action="{{ route('inventory.fit', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm cut">
                        {{ Text::get('inventory.actions.fit') }}
                    </button>
                </form>
            @endif

            {{-- Doors with nothing behind them yet, shown tagged rather than
                 hidden: a card that grows buttons as features land is a card
                 nobody learns. --}}
            <button type="button" class="btn btn--sm btn--ghost" disabled>
                {{ Text::get('inventory.actions.market') }}
                <span class="tag">{{ Text::get('inventory.actions.not_yet') }}</span>
            </button>

            <button type="button" class="btn btn--sm btn--ghost" disabled>
                {{ Text::get('inventory.actions.disassemble') }}
                <span class="tag">{{ Text::get('inventory.actions.not_yet') }}</span>
            </button>

            {{-- Destructive, which is one of the three things the accent is
                 for. The confirmation lives outside this card, because the
                 card is cloned into a modal and a dialog cloned with it would
                 go wherever the clone goes. --}}
            <button type="button" class="btn btn--sm btn--danger cut"
                    data-open="#destroy-{{ $entry->id }}">
                {{ Text::get('inventory.actions.destroy') }}
            </button>
        </div>
</div>
