<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A button on a node. */
#[Fillable(['node_id', 'position', 'label', 'requires_flag', 'cost', 'next_key', 'check_stat', 'difficulty', 'pass_key', 'fail_key', 'fight_npc_id', 'win_key', 'lose_key', 'flee_key'])]
class EncounterChoice extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['position' => 'integer', 'cost' => 'integer', 'difficulty' => 'float'];
    }

    public function hasCheck(): bool
    {
        return $this->check_stat !== null;
    }

    /** A fight choice opens a fight with an opponent and goes on by how it ends. */
    public function isFight(): bool
    {
        return $this->fight_npc_id !== null;
    }

    /** @return BelongsTo<Npc, $this> */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Npc::class, 'fight_npc_id');
    }

    /** @return BelongsTo<EncounterNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(EncounterNode::class, 'node_id');
    }

    /** @return HasMany<EncounterOutcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(EncounterOutcome::class, 'choice_id')->orderBy('position')->orderBy('id');
    }
}
