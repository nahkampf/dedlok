<?php

declare(strict_types=1);

namespace App\Safehouses;

use App\Support\Text;

/** What a safehouse can offer. A type lists the slugs it has (`safehouses.actions`). */
enum Action: string
{
    case Eat = 'eat';
    case Rest = 'rest';
    case Patch = 'patch';
    case Meditate = 'meditate';
    case Shower = 'shower';
    case Workout = 'workout';
    case Study = 'study';
    case Simstim = 'simstim';

    /** A session runs for as long as somebody keeps at it (a city activity) instead of happening at once. */
    public function isSession(): bool
    {
        return in_array($this, [self::Rest, self::Workout, self::Study, self::Simstim], true);
    }

    public function label(): string
    {
        return Text::get("safehouse.actions.{$this->value}");
    }
}
