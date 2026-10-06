<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\ArmourSlot;
use App\Combat\WearSlot;
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

    /**
     * The hit locations covered, in body order — WHOLE WEAR SLOTS: a piece stored as covering one arm covers both,
     * because armour is worn on Arms and not on an arm (`WearSlot`). Anything no longer a slot is ignored.
     *
     * @return list<ArmourSlot>
     */
    public function covers(): array
    {
        $groups = WearSlot::groupsOf((array) $this->slots);

        return array_values(array_filter(ArmourSlot::cases(), static fn (ArmourSlot $slot): bool => in_array(WearSlot::of($slot), $groups, true)));
    }

    /** @return list<WearSlot> the places this piece is worn */
    public function wornOn(): array
    {
        return WearSlot::groupsOf((array) $this->slots);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
