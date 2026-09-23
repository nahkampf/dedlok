<?php

declare(strict_types=1);

namespace App\Entities;

use App\Support\Text;

/**
 * What kind of organisation an entity is.
 *
 * A fixed vocabulary and not rows, the call `App\Drugs\Family`,
 * `App\Implants\BodyRegion` and `App\Decks\SlotType` all make: four kinds is
 * PHP, and a fifth is a case and a label. A table would invite somebody to add
 * one in an editor, and nothing would know what it meant.
 *
 * It carries no mechanics. What an entity DOES is decided by `interactable`,
 * by the exchanges it runs and by the missions its agents hand out — the
 * category is how a player reads it at a glance, and is the axis a screen
 * groups by.
 */
enum EntityCategory: string
{
    case Corporation = 'corporation';
    case Government = 'government';
    case Military = 'military';
    case Fringe = 'fringe';

    public function label(): string
    {
        return Text::get("entities.categories.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
