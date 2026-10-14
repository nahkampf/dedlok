<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\SystemContact;

/** The nearest match: the sighting, how many steps away, which of eight directions, and the grade if a live scan gave one. */
final readonly class FindResult
{
    public function __construct(
        public ?SystemContact $contact,
        public int $steps,
        /** A compass key: n, ne, e, se, s, sw, w or nw. */
        public string $direction,
        public ?int $grade,
        /** True when the character was not on the grid, so the distance is from the portal. */
        public bool $fromPortal,
        /** The cell, for a coordinate search (`$contact` is then null): nothing need be there. */
        public ?Position $target = null,
    ) {}

    public function x(): int
    {
        return $this->target?->x ?? $this->contact->x;
    }

    public function y(): int
    {
        return $this->target?->y ?? $this->contact->y;
    }

    public function arrow(): string
    {
        return Finder::arrow($this->direction);
    }
}
