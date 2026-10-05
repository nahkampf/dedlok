<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\Fight\NpcMitigation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property float|null $flees_at
 * @property array<string, int>|null $resistances
 * @property array<string, array<string, int>>|null $slot_resistances
 */
#[Fillable(['slug', 'name', 'flavor', 'grade', 'hitpoints', 'aim', 'defence', 'pursuit', 'flees_at', 'ranged_weapon_id', 'melee_weapon_id', 'ammo_item_id', 'resistances', 'slot_resistances'])]
class Npc extends Model
{
    /** The picture kind: `public/images/npcs/<id>.jpg`, 3:4, like any other person. */
    public const IMAGE_KIND = 'npcs';

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'hitpoints' => 'integer',
            'aim' => 'float',
            'defence' => 'float',
            'pursuit' => 'float',
            'flees_at' => 'float',
            'resistances' => 'array',
            'slot_resistances' => 'array',
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

    /** What it resists, for the engine. */
    public function mitigation(): NpcMitigation
    {
        return new NpcMitigation((array) $this->resistances, (array) $this->slot_resistances);
    }
}
