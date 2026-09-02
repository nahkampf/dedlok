<?php

declare(strict_types=1);

namespace App\Models;

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
    'weight', 'size', 'capacity', 'accepts', 'base_cost', 'on_market', 'seed_quantity',
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
