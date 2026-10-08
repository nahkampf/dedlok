<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file waiting to be decrypted. Position is a priority: a free Decryptor takes the first waiting file it can break.
 * `worker_id` is the running decryptor working it, and only a worked entry carries a cycle clock.
 *
 * Written only by App\Decryption\DecryptionQueue.
 *
 * @property int $character_id
 * @property int $inventory_item_id
 * @property int $position
 * @property int|null $worker_id
 * @property CarbonImmutable|null $cycle_started_at
 */
#[Fillable(['character_id', 'inventory_item_id', 'position', 'worker_id', 'cycle_started_at'])]
class DecryptionEntry extends Model
{
    protected $table = 'decryption_queue';

    protected function casts(): array
    {
        return ['position' => 'integer', 'cycle_started_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
