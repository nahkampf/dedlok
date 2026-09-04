<?php

declare(strict_types=1);

namespace App\Decks;

/**
 * One box on a deck's board: every socket of one type, and where it sits.
 *
 * A node is a TYPE and not a socket. Four QDRAM sockets are one node holding
 * four slots, because that is what the board draws — one box, one wire back to
 * the CPU. A count cannot be pointed at, which is why `deck_slots` is rows;
 * a coordinate cannot be pointed at either, which is why this is not.
 */
final class BoardNode
{
    public function __construct(
        public readonly SlotType $type,
        public readonly int $count,
        public readonly float $x,
        public readonly float $y,
    ) {}

    public function key(): string
    {
        return $this->type->key();
    }

    public function label(): string
    {
        return $this->type->boardLabel();
    }

    /** The hub every wire runs to. A deck with no CPU socket is not a deck. */
    public function isCpu(): bool
    {
        return $this->type->kind === SlotKind::Cpu;
    }

    /**
     * How many sockets wide the box is.
     *
     * A bank of memory is a rectangle, not a line that wraps wherever it runs
     * out of room: four across is four across, and six is three and three.
     * Up to four stays on one row because that is what a deck looks like;
     * past that it halves, so the box stays a block rather than a ribbon.
     */
    public function columns(): int
    {
        return $this->count <= 4 ? max(1, $this->count) : (int) ceil($this->count / 2);
    }

    /**
     * Does this node draw a box around its slots?
     *
     * One socket is drawn as the socket. More than one gets the box, and the
     * box is what the wire attaches to — otherwise four sticks of RAM are four
     * wires saying the same thing.
     */
    public function isGrouped(): bool
    {
        return $this->count > 1;
    }
}
