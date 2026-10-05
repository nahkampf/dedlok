<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\ArmourSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The armour half of an item: which slots of the body it covers.
 *
 * Resistances are NOT here. They are `items.modifiers`, like an implant's, and
 * `App\Stats\Sources\WornArmour` makes them count only for the slots listed.
 */
#[Fillable(['item_id', 'slots'])]
class Armour extends Model
{
    protected $table = 'armour';

    protected function casts(): array
    {
        return ['slots' => 'array'];
    }

    /** @return list<ArmourSlot> the slots covered, in body order, ignoring anything no longer a slot */
    public function covers(): array
    {
        $wanted = (array) $this->slots;

        return array_values(array_filter(ArmourSlot::cases(), static fn (ArmourSlot $slot): bool => in_array($slot->value, $wanted, true)));
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
