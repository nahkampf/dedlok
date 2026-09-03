<?php

declare(strict_types=1);

namespace App\Models;

use App\Decks\BoardLayout;
use App\Decks\Hardware;
use App\Decks\SlotLayout;
use App\Market\Rarity;
use App\Support\Text;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An item definition.
 *
 * In the database rather than config, for the reasons the migration gives.
 * The row carries the whole definition — mechanical values and the words both.
 * It did not always: the words were looked up by slug from lang/en/items.php
 * until translation was removed, and a row that only held half of itself was
 * the price of being translatable.
 */
#[Fillable([
    'slug', 'name', 'flavor', 'container', 'tier', 'rarity', 'stackable', 'use_op', 'use_skill_id',
    'weight', 'size', 'capacity', 'accepts', 'hardware', 'board_layout', 'base_cost', 'on_market', 'seed_quantity',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    public const CONTAINER_PHYSICAL = 'physical';

    public const CONTAINER_DATA = 'data';

    /** @var list<string> */
    public const CONTAINERS = [self::CONTAINER_PHYSICAL, self::CONTAINER_DATA];

    /** Teaching a skill. The only use there is so far. */
    public const USE_INJECT_SKILL = 'inject_skill';

    /** @var list<string> */
    public const USE_OPS = [self::USE_INJECT_SKILL];

    /**
     * Two tiers, and the scale is deliberately short.
     *
     * It was five, named Standard through Anomalous, which drew a
     * distinction no mechanic has ever asked about. Widen it when
     * something reads it for more than a colour.
     */
    public const MAX_TIER = 2;

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'rarity' => Rarity::class,
            'stackable' => 'boolean',
            'weight' => 'decimal:2',
            'size' => 'integer',
            'capacity' => 'integer',
            'hardware' => Hardware::class,
            'board_layout' => 'array',
            'base_cost' => 'integer',
            'on_market' => 'boolean',
            'seed_quantity' => 'integer',
        ];
    }

    /** For inject_skill items: what the book teaches. */
    public function useSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'use_skill_id');
    }

    public function inventoryEntries(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * The sockets this deck presents. Empty for everything that is not one.
     *
     * Ordered here rather than at every call site: a deck's sockets are a
     * list a player reads, and id order after an edit is not that list.
     */
    public function deckSlots(): HasMany
    {
        return $this->hasMany(DeckSlot::class)->orderBy('position');
    }

    /** What this module is worth. Null for everything that is not one. */
    public function deckModule(): HasOne
    {
        return $this->hasOne(DeckModule::class);
    }

    /** What the market is holding of this item. Absent means none. */
    public function marketStock(): HasOne
    {
        return $this->hasOne(MarketStock::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(MarketPurchase::class);
    }

    /** How many are on the shelf right now. A missing stock row is zero. */
    public function stockOnHand(): int
    {
        return (int) ($this->marketStock?->quantity ?? 0);
    }

    public function isUsable(): bool
    {
        return $this->use_op !== null;
    }

    public function isSkillbook(): bool
    {
        return $this->use_op === self::USE_INJECT_SKILL;
    }

    public function isCyberdeck(): bool
    {
        return $this->hardware === Hardware::Deck;
    }

    /** A module of some kind — a CPU, a stick of RAM, a power supply. */
    public function isModule(): bool
    {
        return $this->hardware?->isModule() ?? false;
    }

    /**
     * This deck's sockets, counted by type.
     *
     * The layout is the deck's specification; the rows are how it is stored.
     * A non-deck has an empty one rather than none, so a caller that asks
     * gets an answer it can use instead of a null to defend against.
     */
    public function slotLayout(): SlotLayout
    {
        return SlotLayout::fromTypes(
            $this->deckSlots->map(fn (DeckSlot $slot) => $slot->type())
        );
    }

    /**
     * This deck's sockets, arranged on its board.
     *
     * The stored coordinates are an override on top of an automatic ring, so
     * a deck nobody has arranged still draws — and a deck arranged before it
     * grew a NIC puts the NIC somewhere sensible rather than at the origin.
     * A non-deck has an empty board rather than none, like slotLayout().
     */
    public function board(): BoardLayout
    {
        return BoardLayout::for($this->slotLayout(), $this->board_layout);
    }

    /**
     * The one-line spec: a deck's sockets, or a module's numbers.
     *
     * Null for anything that is not deck hardware, which is most of the
     * catalogue — so a caller can render it or not without asking what kind
     * of thing it is holding.
     */
    public function hardwareSummary(): ?string
    {
        if ($this->hardware === null) {
            return null;
        }

        if ($this->hardware->isDeck()) {
            $layout = $this->slotLayout();

            return $layout->isEmpty() ? null : $layout->summary();
        }

        return $this->deckModule?->summary($this->hardware);
    }

    public function isData(): bool
    {
        return $this->container === self::CONTAINER_DATA;
    }

    /**
     * Does this item hold other items?
     *
     * A memory card does; a rifle does not. Being a container is the
     * exception, so this is a capacity above zero rather than a flag: the
     * number was going to have to exist anyway, and a second boolean agreeing
     * with it would only be a thing to get wrong.
     */
    public function isContainer(): bool
    {
        return $this->capacity > 0;
    }

    /**
     * May $item go inside this one?
     *
     * `accepts` is compared against the other item's `container` — the kind of
     * thing it is. That is the whole reason `container` survived the arrival
     * of nesting: a skillbook is data whether it is loose on a deck or written
     * to a card in someone's coat pocket.
     */
    public function canHold(Item $item): bool
    {
        return $this->isContainer() && $this->accepts === $item->container;
    }

    /**
     * The name a player sees.
     *
     * A skillbook has none of its own: there is exactly one book per skill, so
     * storing a name per book would be a second copy of the skill's title
     * waiting to disagree with it. The slug is the last resort — visible and
     * wrong beats blank and mysterious.
     */
    public function displayName(): string
    {
        if ($this->isSkillbook()) {
            return Text::get('items.skillbook.name', [
                'skill' => $this->useSkill?->title ?? $this->slug,
            ]);
        }

        return $this->name ?? $this->slug;
    }

    public function displayFlavor(): ?string
    {
        if ($this->isSkillbook()) {
            return Text::get('items.skillbook.flavor');
        }

        return $this->flavor;
    }

    public function rarityName(): string
    {
        return $this->rarity->label();
    }

    /**
     * What kind of thing this is, in the words the card uses.
     *
     * Deck hardware answers with the piece it is: a stick of RAM is RAM first
     * and a physical object second, and which of the two inventories it lives
     * in has already been answered by the tab it was found under. Everything
     * else falls back to that inventory, which is the only kind it has.
     */
    public function kindName(): string
    {
        return $this->hardware?->label()
            ?? Text::get("items.kinds.{$this->container}");
    }

    public function tierName(): string
    {
        return Text::get("items.tiers.{$this->tier}");
    }

    /**
     * The UI kit's tier class: t1 or t2. Tier, not rarity — see App\Market\Rarity.
     *
     * The kit's accent ramp still runs to .t5; the game only reaches the
     * first two steps of it.
     */
    public function tierClass(): string
    {
        return 't'.$this->tier;
    }

    /**
     * The glyph a slot shows. View mapping, like tierClass() beside it.
     *
     * Every one of these is from the set the kit's gallery already proves
     * renders in the font stack — U+2317, the obvious choice for a card,
     * does not. Adding a kind means proving its glyph there first.
     */
    public function glyph(): string
    {
        return match (true) {
            $this->isSkillbook() => "\u{25C8}",
            $this->isCyberdeck() => "\u{2394}",
            $this->isModule() => "\u{232C}",
            $this->isContainer() => "\u{25CD}",
            default => "\u{25A4}",
        };
    }

    /** @param  Builder<self>  $query */
    public function scopeInContainer(Builder $query, string $container): void
    {
        $query->where('container', $container);
    }

    /** @param  Builder<self>  $query */
    public function scopeOnMarket(Builder $query): void
    {
        $query->where('on_market', true);
    }

    /**
     * Items the market restocks: sold there, and with a shelf size set.
     *
     * `on_market` and `seed_quantity` answer different questions — the first
     * is whether the thing is sold at all, the second how many of it a full
     * shelf is — and a restock needs both to be true. An item with a shelf
     * size of zero is not a mistake: it is how something is listed without
     * ever being in stock.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRestockable(Builder $query): void
    {
        $query->onMarket()->where('seed_quantity', '>', 0);
    }
}
