<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Chunk;
use App\Cyberspace\Position;
use App\Cyberspace\Terrain;
use App\Models\Character;
use App\Models\TerrainChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Swept ground: the half of discovery that is permanent.
 *
 * The load-bearing assertion here is the one about binary surviving the round
 * trip. A 512-byte blob of mostly zero bytes is exactly the value a database
 * layer truncates quietly, and a map that lost its far half at byte 3 would
 * look like an exploration bug for a month.
 */
final class TerrainTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        // The archetype is named rather than rolled: nothing here depends on
        // which role a character is, and a rolled one is a test that reads
        // differently on a run where the roster grows.
        return Character::factory()->create(['archetype' => 3]);
    }

    public function test_a_character_who_has_looked_at_nothing_holds_nothing(): void
    {
        $terrain = new Terrain($this->character());

        $this->assertFalse($terrain->hasSwept(new Position(0, 0)));
        $this->assertSame(0, $terrain->sweptCount());
        $this->assertSame(0, $terrain->chunksEntered());
        $this->assertSame(0, TerrainChunk::query()->count());
    }

    public function test_sweeping_marks_the_positions_and_nothing_else(): void
    {
        $terrain = new Terrain($this->character());

        $new = $terrain->sweep([new Position(3, 4), new Position(5, 6)]);

        $this->assertSame(2, $new);
        $this->assertTrue($terrain->hasSwept(new Position(3, 4)));
        $this->assertTrue($terrain->hasSwept(new Position(5, 6)));
        $this->assertFalse($terrain->hasSwept(new Position(4, 4)));
        $this->assertSame(2, $terrain->sweptCount());
    }

    /** Looking at ground twice is ordinary, and the second look is not news. */
    public function test_re_sweeping_reports_nothing_new(): void
    {
        $terrain = new Terrain($this->character());

        $this->assertSame(3, $terrain->sweep([
            new Position(1, 1), new Position(2, 1), new Position(3, 1),
        ]));

        $this->assertSame(1, $terrain->sweep([
            new Position(1, 1), new Position(2, 1), new Position(3, 1), new Position(4, 1),
        ]), 'only the fourth position was new');

        $this->assertSame(4, $terrain->sweptCount());
    }

    /**
     * The blob is written and read back byte for byte. Almost all 512 of them
     * are NUL, which is the case a text-shaped column silently cuts short.
     */
    public function test_a_blob_survives_the_round_trip_intact(): void
    {
        $character = $this->character();

        // Deliberately the far corner of the chunk: its bit is the last one of
        // the last byte, so a blob truncated anywhere at all loses it.
        (new Terrain($character))->sweep([new Position(0, 0), new Position(63, 63)]);

        $row = TerrainChunk::query()->firstOrFail();

        $this->assertSame(Chunk::BYTES, strlen($row->bits));
        $this->assertSame("\x80", $row->bits[0]);
        $this->assertSame("\x01", $row->bits[511]);

        // And through the reader, on a fresh instance with no warm cache.
        $terrain = new Terrain($character->fresh());

        $this->assertTrue($terrain->hasSwept(new Position(63, 63)));
        $this->assertSame(2, $terrain->sweptCount());
    }

    public function test_a_row_exists_per_chunk_entered_and_no_others(): void
    {
        $character = $this->character();
        $terrain = new Terrain($character);

        // One position in each of four chunks, straddling both boundaries the
        // way a window at a chunk corner does.
        $terrain->sweep([
            new Position(63, 63), new Position(64, 63),
            new Position(63, 64), new Position(64, 64),
        ]);

        $this->assertSame(4, $terrain->chunksEntered());
        $this->assertSame(4, $terrain->sweptCount());

        $this->assertSame(
            [[0, 0], [0, 1], [1, 0], [1, 1]],
            TerrainChunk::query()
                ->orderBy('chunk_x')->orderBy('chunk_y')
                ->get()
                ->map(static fn (TerrainChunk $row): array => [$row->chunk_x, $row->chunk_y])
                ->all(),
        );
    }

    /** One character's map is not another's. */
    public function test_terrain_belongs_to_the_character_who_swept_it(): void
    {
        $mine = $this->character();
        $theirs = $this->character();

        (new Terrain($mine))->sweep([new Position(9, 9)]);

        $this->assertTrue((new Terrain($mine))->hasSwept(new Position(9, 9)));
        $this->assertFalse((new Terrain($theirs))->hasSwept(new Position(9, 9)));
        $this->assertSame(0, (new Terrain($theirs))->sweptCount());
    }

    /**
     * The set a drawing loop asks for, keyed the way it looks positions up.
     */
    public function test_swept_answers_for_a_whole_window_at_once(): void
    {
        $terrain = new Terrain($this->character());
        $terrain->sweep([new Position(2, 2), new Position(4, 4)]);

        $swept = $terrain->swept([
            new Position(2, 2), new Position(3, 3), new Position(4, 4),
        ]);

        $this->assertSame(['2,2' => true, '4,4' => true], $swept);
    }

    public function test_sweeping_nothing_writes_nothing(): void
    {
        $this->assertSame(0, (new Terrain($this->character()))->sweep([]));
        $this->assertSame(0, TerrainChunk::query()->count());
    }
}
