<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One invitation, accepted when `joined_at` is set. Written only by App\Cyberspace\Party\Parties. */
class RunPartyMember extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'loadout' => 'array'];
    }

    /** @return BelongsTo<RunParty, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(RunParty::class, 'run_party_id');
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
