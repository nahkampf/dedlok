<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\SystemType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The filter and the pass clock of one running Tracker. Written only by `App\Cyberspace\Tracker`; the row goes with
 * the program's running copy. NULL in a filter column means "any".
 *
 * @property int $id
 * @property int $inventory_item_id
 * @property int $character_id
 * @property int|null $owner_id
 * @property string|null $type
 * @property int|null $grade
 * @property CarbonImmutable|null $pass_started_at
 */
#[Fillable(['inventory_item_id', 'character_id', 'owner_id', 'type', 'grade', 'pass_started_at'])]
class TrackerSearch extends Model
{
    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'grade' => 'integer', 'pass_started_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Entity, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'owner_id');
    }

    /** Has anything been chosen to look for? A search with no terms would match the first thing it saw. */
    public function hasFilter(): bool
    {
        return $this->owner_id !== null || $this->type !== null || $this->grade !== null;
    }

    public function isReleased(): bool
    {
        return $this->pass_started_at !== null;
    }

    public function systemType(): ?SystemType
    {
        return $this->type === null ? null : SystemType::tryFrom($this->type);
    }
}
