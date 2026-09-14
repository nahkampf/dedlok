<?php

declare(strict_types=1);

namespace Tests\Unit\Cyberspace;

use App\Cyberspace\Chunk;
use App\Cyberspace\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic that turns a position into one bit, and back.
 *
 * Pure, so this is a plain PHPUnit test with no application under it. What it
 * is really guarding is the BIT ORDER: every stored blob in the game means
 * what this file says it means, and a change here silently re-reads every
 * character's map as somebody else's.
 */
final class ChunkTest extends TestCase
{
    public function test_a_chunk_is_512_bytes_of_bits(): void
    {
        $this->assertSame(64, Chunk::SIZE);
        $this->assertSame(4096, Chunk::BITS);
        $this->assertSame(512, Chunk::BYTES);
        $this->assertSame(512, strlen(Chunk::blank()));
    }

    public function test_a_position_falls_in_the_chunk_that_covers_it(): void
    {
        $this->assertTrue(Chunk::of(new Position(0, 0))->equals(new Chunk(0, 0)));
        $this->assertTrue(Chunk::of(new Position(63, 63))->equals(new Chunk(0, 0)));
        $this->assertTrue(Chunk::of(new Position(64, 0))->equals(new Chunk(1, 0)));
        $this->assertTrue(Chunk::of(new Position(0, 64))->equals(new Chunk(0, 1)));

        // Full size: 2048 is 32 chunks across, so the far corner is (31, 31).
        $this->assertTrue(Chunk::of(new Position(2047, 2047))->equals(new Chunk(31, 31)));
    }

    /**
     * Reading order, and the high bit first — so a chunk dumped as hex reads
     * left to right and top to bottom the way the map does.
     */
    public function test_the_first_position_of_a_chunk_is_the_high_bit_of_the_first_byte(): void
    {
        $chunk = new Chunk(0, 0);

        $this->assertSame([0, 0b10000000], $chunk->locate(new Position(0, 0)));
        $this->assertSame([0, 0b01000000], $chunk->locate(new Position(1, 0)));
        $this->assertSame([1, 0b10000000], $chunk->locate(new Position(8, 0)));

        // The second row starts one full row of 64 bits — 8 bytes — in.
        $this->assertSame([8, 0b10000000], $chunk->locate(new Position(0, 1)));

        // The last position of the chunk is the low bit of the last byte.
        $this->assertSame([511, 0b00000001], $chunk->locate(new Position(63, 63)));
    }

    /**
     * A position is located within ITS OWN chunk, so the same offsets come out
     * for the equivalent position of any chunk. This is what lets a blob be
     * 512 bytes regardless of where on the grid it sits.
     */
    public function test_a_position_is_located_relative_to_its_own_chunk(): void
    {
        $far = new Position(64 * 5 + 3, 64 * 7 + 2);

        $this->assertSame(
            (new Chunk(0, 0))->locate(new Position(3, 2)),
            Chunk::of($far)->locate($far),
        );
    }

    public function test_every_position_in_a_chunk_gets_its_own_bit(): void
    {
        $chunk = new Chunk(0, 0);
        $seen = [];

        for ($y = 0; $y < Chunk::SIZE; $y++) {
            for ($x = 0; $x < Chunk::SIZE; $x++) {
                [$byte, $mask] = $chunk->locate(new Position($x, $y));
                $seen["{$byte}:{$mask}"] = true;
            }
        }

        $this->assertCount(Chunk::BITS, $seen, 'no two positions may share a bit');
    }

    /**
     * PHP's modulo keeps the sign, so a negative position would locate a
     * NEGATIVE byte offset — and writing to one lights up the far corner of
     * the chunk instead. Refused rather than located.
     */
    public function test_a_position_off_the_grid_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Chunk(0, 0))->locate(new Position(-1, 0));
    }

    public function test_counting_a_blob_counts_its_set_bits(): void
    {
        $this->assertSame(0, Chunk::count(Chunk::blank()));
        $this->assertSame(Chunk::BITS, Chunk::count(str_repeat("\xFF", Chunk::BYTES)));

        $bits = Chunk::blank();
        $bits[0] = "\x81";
        $bits[7] = "\x0F";

        $this->assertSame(6, Chunk::count($bits));
    }
}
