<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\TerrainChunk;
use Illuminate\Support\Facades\DB;

/**
 * One character's swept ground, and the ONE place that knows what a bit means.
 *
 * *I have looked at this position.* That is the whole of what terrain records,
 * and it is permanent: a position never moves and never changes, so a bit set
 * here is true forever. What was STANDING there is the other record and it is
 * allowed to be wrong — see App\Models\SystemContact.
 *
 * The bits live in 512-byte blobs, one per 64 x 64 chunk the character has
 * entered, and Chunk is where a position becomes a byte and a mask. Nothing
 * else may touch `character_terrain.bits`: the blob has no meaning without
 * that arithmetic, and a second copy of it is a second chance to get the bit
 * order wrong.
 *
 * A chunk is loaded at most once per instance and mutated in place, because
 * the two things that happen in a request — sweep a window, then draw it — are
 * the same handful of chunks twice.
 */
final class Terrain
{
    /** @var array<string, ?TerrainChunk> Keyed "cx,cy"; null means looked for and absent. */
    private array $loaded = [];

    public function __construct(
        private readonly Character $character,
    ) {}

    public function hasSwept(Position $position): bool
    {
        $chunk = Chunk::of($position);
        $bits = $this->bitsOf($chunk);

        if ($bits === null) {
            return false;
        }

        [$byte, $mask] = $chunk->locate($position);

        return (ord($bits[$byte]) & $mask) !== 0;
    }

    /**
     * Which of these positions have been swept, as a set keyed "x,y".
     *
     * A set rather than a list because every caller is a drawing loop asking
     * one position at a time whether it is lit, and `isset()` is the answer
     * that does not cost a search. The same shape Grid::freePosition() takes
     * for the positions a batch has spoken for.
     *
     * @param  iterable<Position>  $positions
     * @return array<string, true>
     */
    public function swept(iterable $positions): array
    {
        $found = [];

        foreach ($positions as $position) {
            if ($this->hasSwept($position)) {
                $found["{$position->x},{$position->y}"] = true;
            }
        }

        return $found;
    }

    /**
     * Mark these positions as looked at, and say how many were new.
     *
     * New is what the caller wants to hear about — walking one step reveals a
     * handful of positions and re-reveals the rest, and "you swept 5 new
     * positions" is the sentence a player understands. Sweeping ground twice
     * is not an error and writes nothing the second time.
     *
     * Positions off the grid are the caller's business and not filtered here:
     * Grid::contains() is the test, and every caller has a Grid because it
     * needed one to work out the window.
     *
     * @param  iterable<Position>  $positions
     */
    public function sweep(iterable $positions): int
    {
        /** @var array<string, array{Chunk, list<Position>}> $byChunk */
        $byChunk = [];

        foreach ($positions as $position) {
            $chunk = Chunk::of($position);
            $key = (string) $chunk;

            $byChunk[$key] ??= [$chunk, []];
            $byChunk[$key][1][] = $position;
        }

        if ($byChunk === []) {
            return 0;
        }

        $this->warm(array_map(static fn (array $pair): Chunk => $pair[0], $byChunk));

        $new = 0;

        DB::transaction(function () use ($byChunk, &$new): void {
            foreach ($byChunk as $key => [$chunk, $inChunk]) {
                $row = $this->loaded[$key] ?? null;
                $bits = $row?->bits ?? Chunk::blank();
                $touched = 0;

                foreach ($inChunk as $position) {
                    [$byte, $mask] = $chunk->locate($position);
                    $current = ord($bits[$byte]);

                    if (($current & $mask) !== 0) {
                        continue;
                    }

                    $bits[$byte] = chr($current | $mask);
                    $touched++;
                }

                if ($touched === 0) {
                    continue;
                }

                $new += $touched;

                // A chunk row is created by the first look into it, which is
                // what keeps a character who has explored nothing holding
                // nothing at all.
                if ($row === null) {
                    $this->loaded[$key] = TerrainChunk::query()->create([
                        'character_id' => $this->character->id,
                        'grid_id' => $chunk->grid,
                        'chunk_x' => $chunk->x,
                        'chunk_y' => $chunk->y,
                        'bits' => $bits,
                    ]);

                    continue;
                }

                $row->update(['bits' => $bits]);
            }
        });

        return $new;
    }

    /**
     * How many positions this character has swept altogether.
     *
     * Every chunk they hold, which is bounded by where they have been rather
     * than by the size of the grid — a thorough player at full size holds a
     * thousand blobs and somebody who has walked one street holds one.
     */
    public function sweptCount(): int
    {
        return TerrainChunk::query()
            ->where('character_id', $this->character->id)
            ->get()
            ->sum(static fn (TerrainChunk $row): int => Chunk::count($row->bits));
    }

    /** How many chunks this character has ever entered. */
    public function chunksEntered(): int
    {
        return TerrainChunk::query()->where('character_id', $this->character->id)->count();
    }

    private function bitsOf(Chunk $chunk): ?string
    {
        $this->warm([$chunk]);

        return $this->loaded[(string) $chunk]?->bits;
    }

    /**
     * Load the rows for these chunks in one query, remembering the misses.
     *
     * The `whereIn` pair is a SUPERSET of the chunks asked for — the product
     * of the distinct x's and y's rather than the pairs themselves — and that
     * is deliberate. A window straddles at most four chunks, so the superset
     * is at most four rows, and one query with two `IN`s beats a chain of
     * `OR (chunk_x = ? AND chunk_y = ?)` that no index reads as well.
     *
     * @param  array<int|string, Chunk>  $chunks
     */
    private function warm(array $chunks): void
    {
        $wanted = [];

        foreach ($chunks as $chunk) {
            $key = (string) $chunk;

            if (! array_key_exists($key, $this->loaded)) {
                $wanted[$key] = $chunk;
            }
        }

        if ($wanted === []) {
            return;
        }

        // Missing until proved otherwise, so a chunk nobody has entered is
        // asked about once and answered from memory afterwards.
        foreach ($wanted as $key => $chunk) {
            $this->loaded[$key] = null;
        }

        $rows = TerrainChunk::query()
            ->where('character_id', $this->character->id)
            ->whereIn('grid_id', array_values(array_unique(array_map(
                static fn (Chunk $chunk): int => $chunk->grid, $wanted
            ))))
            ->whereIn('chunk_x', array_values(array_unique(array_map(
                static fn (Chunk $chunk): int => $chunk->x, $wanted
            ))))
            ->whereIn('chunk_y', array_values(array_unique(array_map(
                static fn (Chunk $chunk): int => $chunk->y, $wanted
            ))))
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->chunk();

            if (array_key_exists($key, $wanted)) {
                $this->loaded[$key] = $row;
            }
        }
    }
}
