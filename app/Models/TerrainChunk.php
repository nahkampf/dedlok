<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\Chunk;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One character's memory of one 64 x 64 square of the grid: 512 bytes of bits,
 * one per position, set when they have looked at it.
 *
 * A row exists because the character has ENTERED that chunk. There is no row
 * for ground nobody has been near, which is what keeps a fresh character's
 * discovery record empty rather than four million zeroes.
 *
 * TERRAIN NEVER GOES STALE. A position cannot move and cannot change, so a bit
 * set here is true forever — which is exactly what a contact is not, and why
 * the two are separate records. See App\Models\SystemContact.
 *
 * Nothing outside App\Cyberspace\Terrain should read or write `bits`. The
 * blob's meaning is Chunk's arithmetic, and a second place that knew which bit
 * was which would be a second place to get the bit order wrong.
 */
#[Fillable(['character_id', 'grid_id', 'chunk_x', 'chunk_y', 'bits'])]
class TerrainChunk extends Model
{
    protected $table = 'character_terrain';

    protected function casts(): array
    {
        return [
            'chunk_x' => 'integer',
            'chunk_y' => 'integer',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** Which square of the grid this is. */
    public function chunk(): Chunk
    {
        return new Chunk($this->chunk_x, $this->chunk_y, (int) $this->grid_id);
    }

    /** @param  Builder<self>  $query */
    public function scopeForChunk(Builder $query, Chunk $chunk): void
    {
        $query->where('grid_id', $chunk->grid)->where('chunk_x', $chunk->x)->where('chunk_y', $chunk->y);
    }
}
