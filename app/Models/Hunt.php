<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hunter, chasing one character.
 *
 * AN ICE, ON THE FLY — `ice_id` is a real foreign key into the twelve-row
 * catalogue, drawn at random from the level range this hunt's `grade` allows.
 * That is what makes every existing ICE resistance and Ensnare reach a hunter
 * for free: fighting one goes through `App\Cyberspace\HunterCombat`, which
 * deals damage as `damage.synaptic_integrity.ice`, because it is that damage.
 *
 * Written by `App\Cyberspace\Hunters` (spawning) and `App\Cyberspace\
 * HunterCombat` (fighting it) and nothing else.
 */
#[Fillable(['character_id', 'ice_id', 'ice_integrity', 'grade', 'heat_at_spawn', 'started_at', 'resolved_at', 'won', 'log'])]
class Hunt extends Model
{
    protected function casts(): array
    {
        return [
            'ice_integrity' => 'integer',
            'grade' => 'integer',
            'heat_at_spawn' => 'float',
            'started_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'won' => 'boolean',
            'log' => 'array',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<Ice, $this> */
    public function ice(): BelongsTo
    {
        return $this->belongsTo(Ice::class);
    }

    /** Still being fought. */
    public function isLive(): bool
    {
        return $this->resolved_at === null;
    }
}
