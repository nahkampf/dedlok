<?php

declare(strict_types=1);

namespace App\Cyberspace;

use InvalidArgumentException;
use Stringable;

/**
 * A 64 x 64 square of the grid, and the arithmetic that turns a position into
 * one bit inside it.
 *
 * DISCOVERY IS A BITMAP, NEVER A ROW PER POSITION. That is the decision this
 * class exists to make possible, and it is a decision about the grid at full
 * size rather than the one that exists today. A row per swept position is
 * perfectly comfortable at 4 096 positions and is 4.2 million rows per
 * thorough player at 2048 x 2048; a bit per position is 512 bytes per chunk
 * either way. A character stores a blob per chunk they have ENTERED, so
 * somebody who has explored five chunks holds 2.5 KB and somebody who has
 * explored nothing holds nothing at all.
 *
 * The size is a constant and not config, unlike the size of the grid. It is
 * structural: it decides the width of a stored blob, and a world whose chunks
 * changed size would be a world whose every stored blob means something else.
 * The grid may grow underneath it — 64 x 64 is one chunk today and 1 024 of
 * them at full size, and nothing here changes.
 *
 * Pure, and it knows nothing about a character or a database. Terrain is what
 * holds the bits; this is only where a bit lives.
 */
final readonly class Chunk implements Stringable
{
    /** The edge of a chunk, in positions. */
    public const SIZE = 64;

    /** Positions in a chunk, and therefore bits in its blob. */
    public const BITS = self::SIZE * self::SIZE;

    /** The width of a stored blob. 4 096 bits is 512 bytes. */
    public const BYTES = self::BITS / 8;

    public function __construct(
        public int $x,
        public int $y,
    ) {}

    /** Which chunk a position falls in. */
    public static function of(Position $position): self
    {
        return new self(
            intdiv($position->x, self::SIZE),
            intdiv($position->y, self::SIZE),
        );
    }

    public function equals(self $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y;
    }

    /**
     * Where in this chunk's blob a position's bit lives: which byte, and which
     * bit of that byte.
     *
     * Reading order — the row within the chunk, then the column — so a chunk
     * dumped as hex reads left to right and top to bottom the way the map
     * does. The bit inside a byte is counted from the HIGH end for the same
     * reason: the first position of a row is the first bit you see.
     *
     * The position is assumed to be in this chunk. Asking for one that is not
     * is a caller bug rather than a state to handle, and Terrain groups by
     * chunk before it ever asks.
     *
     * A NEGATIVE position is refused rather than located. PHP's modulo keeps
     * the sign, so (-1) % 64 is -1 and a negative byte offset writes to the
     * END of the blob — the far corner of the chunk quietly lighting up
     * because somebody walked off the west edge. The grid does not wrap and a
     * position off it is not a place; Grid::contains() is the test, and every
     * caller has a Grid because it needed one to work out the window at all.
     *
     * @return array{int, int} [byte offset, bit mask]
     */
    public function locate(Position $position): array
    {
        if ($position->x < 0 || $position->y < 0) {
            throw new InvalidArgumentException("Position {$position} is not on the grid.");
        }

        $index = ($position->y % self::SIZE) * self::SIZE + ($position->x % self::SIZE);

        return [intdiv($index, 8), 1 << (7 - $index % 8)];
    }

    /** A blob with nothing swept in it. What a chunk starts as. */
    public static function blank(): string
    {
        return str_repeat("\0", self::BYTES);
    }

    /**
     * How many positions a blob has swept.
     *
     * Counted through the byte's own popcount rather than a loop over 4 096
     * bits, because the map's "you have swept N positions" reads every chunk a
     * character holds.
     */
    public static function count(string $bits): int
    {
        $swept = 0;

        for ($byte = 0, $length = strlen($bits); $byte < $length; $byte++) {
            // A byte is 0-255 and its popcount is what decides the total, so
            // the conversion through decbin is one call per byte rather than
            // eight tests per byte.
            $swept += substr_count(decbin(ord($bits[$byte])), '1');
        }

        return $swept;
    }

    /** How a chunk is keyed wherever one is held in an array. */
    public function __toString(): string
    {
        return "{$this->x},{$this->y}";
    }
}
