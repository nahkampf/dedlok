<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Party\ShareMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A party forming at a system's door. Written only by App\Cyberspace\Party\Parties. */
class RunParty extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'started_at' => 'datetime',
            'round_started_at' => 'datetime',
            'share_credits' => ShareMode::class,
            'share_extraction' => ShareMode::class,
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'owner_character_id');
    }

    /** @return HasMany<RunPartyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(RunPartyMember::class);
    }
}
