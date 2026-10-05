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
    {{-- What the thing looks like, found by convention: one subdirectory per
         type of item under public/images/items. The enlarge dialog is rendered
         beside the listing instead of here — <x-item-image> — because this card
         is cloned into the inspector and into a modal, and a <dialog> cloned
         with it would be a second copy of itself in the top layer. --}}
    <x-entity-image :kind="$item->imageKind()" :id="$item->id" :alt="$item->displayName()"
                    dialog="image-{{ $entry->id }}" />

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
                    {{-- Which deck you are carrying. It used to be said by the
                         stash's "in use" panel, and that panel existed only
                         because loose() took the deck out of the grid. The deck
                         is back in the grid, so the fact moves onto the card —
                         where every other fact about an item already is. --}}
                    @if ($entry->isEquipped())
                        <span class="tag">{{ Text::get('decks.equip.is_equipped') }}</span>
                    @endif
                    @if ($entry->isWorn())
                        <span class="tag">{{ Text::get('combat.armour.worn') }}</span>
                    @endif
                    @if ($entry->isWielded())
                        <span class="tag">{{ Text::get('combat.weapons.wielded') }}</span>
                    @elseif ($item->weapon && ($gate = app(\App\Combat\WeaponWielder::class)->gate($item->weapon->class)))
                        <span class="tag">{{ Text::get('combat.weapons.requires', ['skill' => $gate->title, 'level' => (int) config('combat.gate_level')]) }}</span>
                    @endif
                </span>
            </span>
            <div class="spacer"></div>
            <button type="button" class="btn btn--sm btn--ghost" data-act data-close
                aria-label="{{ Text::get('inventory.close') }}">✕</button>
        </div>

        @if ($item->isImprint() && $item->useSkill)
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ Text::get('inventory.teaches', ['skill' => $item->useSkill->title]) }}
            </div>
        @elseif ($item->hardwareSummary())
            {{-- A deck's sockets or a module's numbers. It is what the item IS,
                 so it reads where the imprint's skill does. --}}
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ $item->hardwareSummary() }}
            </div>
        @elseif ($item->programSummary())
            {{-- What running it costs, and whether it may run outside a run.
                 Same place, same voice as a module's spec line. --}}
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ $item->programSummary() }}
            </div>
        @elseif ($entry->scanFileSummary())
            {{-- What machine this read was of, how hard it is, and how long the
                 file is still true. Reads off $entry for the dump's reason:
                 every "Scan File" is one catalogue row and a different
                 machine. --}}
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ $entry->scanFileSummary() }}
            </div>
        @elseif ($entry->extractedFileSummary())
            {{-- A real file's own numbers, on the ROW rather than the item —
                 every "Encrypted Data Dump" is the same catalogue entry and a
                 different file, which is why this reads off $entry and not
                 $item like every other branch here. --}}
            <div class="micro" style="color:var(--ink-3);margin-top:var(--s-2)">
                {{ $entry->extractedFileSummary() }}
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
                        @if ($child->item->isImprint() && $child->item->useSkill)
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

            @if ($entry->children->contains(fn ($child) => $child->item->isImprint()))
                {{-- Once for the whole list rather than once per imprint: the
                     sentence is the same one every time, and a card holding
                     four imprints would otherwise say it four times. --}}
                <p class="item-card__note" data-act>{{ Text::get('inventory.inject_confirm_any') }}</p>
            @endif
        </div>
    @endif

    @if ($item->isImprint() && $item->useSkill)
        {{-- Spending an imprint cannot be undone, so the sentence that says so is
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

            {{-- Taking one is not confirmed. A dose is one copy of a thing
                 meant to be used, and what makes it dangerous is the overdose
                 rather than the click. --}}
            @if ($item->isDrug())
                <form method="POST" action="{{ route('inventory.take', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--primary cut">
                        {{ Text::get('drugs.take') }}
                    </button>
                </form>
            @endif

            {{-- Armour goes on and comes off here, in the one place you already have it open. Not
                 confirmed: nothing is destroyed. A set takes every slot it covers, and whatever was
                 in them comes off with it. --}}
            @if ($item->armour)
                <form method="POST" action="{{ route('inventory.wear', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm cut {{ $entry->isWorn() ? 'btn--ghost' : 'btn--primary' }}">
                        {{ Text::get($entry->isWorn() ? 'combat.armour.take_off' : 'combat.armour.wear') }}
                    </button>
                </form>
            @endif

            {{-- A weapon is picked up here. Which hand it fills is its class's business, so the player
                 picks the weapon and not the hand; whatever was in that hand is put down. --}}
            @if ($item->weapon)
                <form method="POST" action="{{ route('inventory.wield', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm cut {{ $entry->isWielded() ? 'btn--ghost' : 'btn--primary' }}">
                        {{ Text::get($entry->isWielded() ? 'combat.weapons.put_down' : 'combat.weapons.wield') }}
                    </button>
                </form>
            @endif

            {{-- A firearm's magazine: what is in it, a round to put in, and out again. Only the rounds in the
                 stash that fit this calibre are offered, so the choice is the one the writer would accept. --}}
            @if ($item->weapon?->calibre !== null)
                @php($fits = \App\Models\InventoryItem::query()->with('item')->where('character_id', $entry->character_id)->loose()
                    ->whereHas('item.ammunition', fn ($q) => $q->where('calibre', $item->weapon->calibre))->orderBy('id')->get())
                <div class="stack" style="--gap:var(--s-2)">
                    <span class="micro">
                        {{ $entry->rounds() > 0
                            ? Text::get('combat.loading.loaded').': '.$entry->loadedAmmo?->displayName().' '.Text::get('combat.loading.rounds', ['rounds' => $entry->rounds(), 'magazine' => $item->weapon->magazine])
                            : Text::get('combat.loading.empty') }}
                    </span>
                    @if ($fits->isNotEmpty())
                        <form method="POST" action="{{ route('inventory.load', $entry) }}" class="row row--wrap" style="--gap:var(--s-2)">
                            @csrf
                            <select class="select" name="ammo" aria-label="{{ Text::get('combat.loading.pick') }}" style="max-width:min(260px,100%)">
                                @foreach ($fits as $round)
                                    <option value="{{ $round->item_id }}" @selected($round->item_id === $entry->loaded_ammo_id)>{{ $round->item->displayName() }} × {{ $round->quantity }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn--sm btn--primary cut">{{ Text::get('combat.loading.load') }}</button>
                        </form>
                    @else
                        <span class="micro" style="color:var(--ink-3)">{{ Text::get('combat.loading.none_to_load', ['calibre' => $item->weapon->calibre]) }}</span>
                    @endif
                    @if ($entry->rounds() > 0)
                        <form method="POST" action="{{ route('inventory.unload', $entry) }}">
                            @csrf
                            <button type="submit" class="btn btn--sm btn--ghost cut">{{ Text::get('combat.loading.unload') }}</button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($item->isImprint() && $item->useSkill)
                <form method="POST" action="{{ route('inventory.inject', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--primary cut">
                        {{ Text::get('inventory.inject') }}
                    </button>
                </form>
            @endif

            {{-- A file is read in, which spends it. Offered even when it is
                 stale or describes a machine that has since gone, because
                 every way it can fail is a refusal with a sentence attached —
                 "nobody can get anything out of it now" teaches more than an
                 absent button, and nothing is spent by being told. --}}
            @if ($entry->scanFile())
                <form method="POST" action="{{ route('inventory.read', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm btn--primary cut">
                        {{ Text::get('cyberspace.reading.import') }}
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

            @if ($item->program)
                {{-- Running is a state of THIS row, so the button is a toggle
                     and the route is one. A running copy is a row of its own —
                     idle copies stack and running ones do not — so this is
                     never ambiguous about which one is meant.

                     Offered even for run-only software, because every way it
                     can fail is a refusal with a sentence attached: "the run
                     starts this, not you" teaches more than an absent button. --}}
                <form method="POST" action="{{ route('inventory.run', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn--sm cut {{ $entry->isRunning() ? 'btn--ghost' : '' }}">
                        {{ Text::get($entry->isRunning() ? 'programs.action.stop' : 'programs.action.start') }}
                    </button>
                </form>
            @endif

            {{-- SELLING IT. The price is asked for here rather than on a
                 screen of its own: the thing being sold is this row, and this
                 card is the one place anybody is looking at it.

                 A listed row is still yours and still in the stash — it is
                 simply spoken for, the way a fitted module is — so the card
                 keeps rendering and swaps the button for the way back. --}}
            @if ($entry->offer)
                <span class="tag tag--ember">
                    {{ Text::get('market.offers.on_market', ['price' => number_format($entry->offer->unit_price)]) }}
                </span>
                <form method="POST" action="{{ route('market.withdraw', $entry->offer) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn--sm btn--ghost cut">
                        {{ Text::get('market.offers.withdraw') }}
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('market.list', $entry) }}"
                      class="row row--wrap" style="--gap:var(--s-2);align-items:center">
                    @csrf
                    <label class="field" style="flex:0 0 auto;margin:0">
                        <span class="sr-only">{{ Text::get('market.offers.price_label') }}</span>
                        <input class="input num" type="number" name="price"
                               min="0" max="99999999" required
                               style="width:min(110px, 100%)"
                               value="{{ $item->base_cost > 0 ? $item->base_cost : '' }}"
                               aria-label="{{ Text::get('market.offers.price_label') }}">
                    </label>

                    <button type="submit" class="btn btn--sm cut">
                        {{ Text::get('market.offers.list') }}
                    </button>
                </form>
            @endif

            {{-- A door with nothing behind it yet, shown tagged rather than
                 hidden: a card that grows buttons as features land is a card
                 nobody learns. --}}
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
