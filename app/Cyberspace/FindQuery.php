<?php

declare(strict_types=1);

namespace App\Cyberspace;

/** What the Locator was asked for. Every part is optional; none at all asks for the nearest system of any kind. */
final readonly class FindQuery
{
    public function __construct(
        public ?SystemType $type = null,
        public ?string $owner = null,
        /** One of `>`, `>=`, `<`, `<=`, `=`, with $grade. */
        public ?string $gradeOp = null,
        public ?int $grade = null,
        /** An exact address (8 hex digits), upper case. Only a system you have met can be found by it. */
        public ?string $address = null,
        /** A grid position, which only a Pathfinder takes: the target is the cell, whether or not anything is there. */
        public ?Position $position = null,
    ) {}

    public function gradeFits(int $known): bool
    {
        return match ($this->gradeOp) {
            '>' => $known > $this->grade,
            '>=' => $known >= $this->grade,
            '<' => $known < $this->grade,
            '<=' => $known <= $this->grade,
            default => $known === $this->grade,
        };
    }

    /** The terms as understood, for saying back. */
    public function describe(): string
    {
        return implode(', ', array_filter([
            $this->type?->label(),
            $this->position === null ? null : $this->position->x.','.$this->position->y,
            $this->address,
            $this->owner,
            $this->grade === null ? null : 'grade '.($this->gradeOp === '=' ? '' : $this->gradeOp).$this->grade,
        ]));
    }
}
