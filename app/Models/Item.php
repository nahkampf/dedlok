<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item definition.
 *
 * In the database rather than config, for the reasons the migration gives.
 * Mechanical values live on the row; every player-visible word is looked up by
 * slug from lang/<locale>/items.php.
 */
#[Fillable([
    'slug', 'container', 'tier', 'stackable', 'use_op', 'use_skill_id',
    'weight', 'size', 'base_cost', 'on_market',
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

    public const MAX_TIER = 5;

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'stackable' => 'boolean',
            'weight' => 'decimal:2',
            'size' => 'integer',
            'base_cost' => 'integer',
            'on_market' => 'boolean',
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
     * The name a player sees.
     *
     * A skillbook's name is built from the skill it teaches rather than stored
     * per slug, because there is one book per skill: a lang entry each would
     * mean adding a skill also means editing every locale. Skills carry their
     * own title in the database — the documented i18n exception — so the name
     * composes without a second lookup.
     */
    public function displayName(): string
    {
        return $this->isSkillbook()
            ? __('items.skillbook.name', ['skill' => $this->useSkill?->title ?? $this->slug])
            : __("items.{$this->slug}.name");
    }

    public function displayFlavor(): ?string
    {
        if ($this->isSkillbook()) {
            return __('items.skillbook.flavor');
        }

        $key = "items.{$this->slug}.flavor";

        // A missing key renders as the key itself, which is worse than nothing
        // in a flavour line that is decoration.
        return __($key) === $key ? null : __($key);
    }

    public function tierName(): string
    {
        return __("items.tiers.{$this->tier}");
    }

    /** The UI kit's rarity class: t1..t5. */
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
}
