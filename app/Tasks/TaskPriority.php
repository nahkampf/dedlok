<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Support\Text;

/** How much a player's own task matters to them. Stored as the number, so a sort by it is a sort by importance. */
enum TaskPriority: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;

    public function label(): string
    {
        return Text::get('tasks.priorities.'.$this->name);
    }

    /** The kit tag modifier: grey, yellow, ember. */
    public function tag(): string
    {
        return match ($this) {
            self::Low => 'tag--quiet',
            self::Medium => 'tag--warn',
            self::High => 'tag--ember',
        };
    }
}
