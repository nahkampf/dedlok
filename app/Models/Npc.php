<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\CombatTag;
use App\Combat\Fight\NpcMitigation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An opponent in a fight. Authored content: the slug is permanent. Written only through the admin
 * editor (`Admin\NpcController`); a fight reads it once, into a snapshot
 * (`CombatantBuilder::forNpc()`), so editing one never touches a fight in progress.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $flavor
 * @property int $grade
 * @property int $hitpoints
 * @property float $aim
 * @property float $defence
 * @property float $pursuit
 * @property string|null $image_credit
 * @property string|null $image_link
 * @property float|null $flees_at
 * @property int|null $flee_beyond
 * @property int|null $entity_id
 * @property list<string>|null $tags
 * @property array<string, int>|null $resistances
 * @property array<string, array<string, int>>|null $slot_resistances
 */
#[Fillable(['slug', 'name', 'flavor', 'image_credit', 'image_link', 'grade', 'hitpoints', 'aim', 'defence', 'pursuit', 'entity_id', 'tags', 'flees_at', 'flee_beyond', 'ranged_weapon_id', 'melee_weapon_id', 'ammo_item_id', 'resistances', 'slot_resistances'])]
class Npc extends Model
{
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'hitpoints' => 'integer',
            'aim' => 'float',
            'defence' => 'float',
            'pursuit' => 'float',
            'flees_at' => 'float',
            'flee_beyond' => 'integer',
            'resistances' => 'array',
            'slot_resistances' => 'array',
            'tags' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Item, $this> */
    public function rangedWeapon(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'ranged_weapon_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function meleeWeapon(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'melee_weapon_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function ammo(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'ammo_item_id');
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** @return HasMany<NpcLoot, $this> */
    public function loot(): HasMany
    {
        return $this->hasMany(NpcLoot::class)->orderBy('id');
    }

    /** What it does to somebody it beats: NULL is the default, anything else replaces it. @return list<CombatTag> */
    public function combatTags(): array
    {
        return CombatTag::fromStored($this->tags);
    }

    /** What it resists, for the engine. */
    public function mitigation(): NpcMitigation
    {
        return new NpcMitigation((array) $this->resistances, (array) $this->slot_resistances);
    }
}
