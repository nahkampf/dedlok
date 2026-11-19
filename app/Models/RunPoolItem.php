<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One copy of an item put up for the others of a co-op run. Written only by App\Cyberspace\Party\RunPool. */
class RunPoolItem extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<RunParty, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(RunParty::class, 'run_party_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function offerer(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'offered_by_character_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'claimed_by_character_id');
    }
}
