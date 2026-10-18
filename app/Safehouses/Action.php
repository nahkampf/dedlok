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

    public function label(): string
    {
        return Text::get("safehouse.actions.{$this->value}");
    }
}
