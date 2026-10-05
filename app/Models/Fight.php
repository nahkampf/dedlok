<?php

declare(strict_types=1);

namespace App\Models;

use App\Combat\Fight\FightState;
use App\Combat\Fight\Result;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fight, live or finished. Written only by `App\Combat\Fights`.
 *
 * @property int $id
 * @property int $character_id
 * @property int|null $npc_id
 * @property string $opponent_name
 * @property int $grade
 * @property array<string, int>|null $resistances
 * @property array<string, array<string, int>>|null $slot_resistances
 * @property int|null $entity_id
 * @property list<string>|null $tags
 * @property list<array{item_id: int, chance: int, min: int, max: int}>|null $loot
 * @property int|null $ranged_row_id
 * @property int|null $melee_row_id
 * @property array<string, mixed> $state
 * @property Result|null $result
 * @property Carbon|null $resolved_at
 */
#[Fillable(['character_id', 'npc_id', 'opponent_name', 'grade', 'entity_id', 'tags', 'loot', 'resistances', 'slot_resistances', 'ranged_row_id', 'melee_row_id', 'encounter_row_id', 'win_key', 'lose_key', 'flee_key', 'state', 'result', 'resolved_at'])]
class Fight extends Model
{
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'resistances' => 'array',
            'slot_resistances' => 'array',
            'tags' => 'array',
            'loot' => 'array',
            'state' => 'array',
            'result' => Result::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->resolved_at === null;
    }

    /** The fight as the engine plays it. */
    public function snapshot(): FightState
    {
        return FightState::fromArray($this->state);
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** @return BelongsTo<Npc, $this> */
    public function npc(): BelongsTo
    {
        return $this->belongsTo(Npc::class);
    }
}
