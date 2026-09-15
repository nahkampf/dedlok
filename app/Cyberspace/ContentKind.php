<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Support\Text;

/**
 * The three things a machine can be holding.
 *
 * Three rather than one, because they behave differently the moment anybody
 * takes them: creds are a number, an item is a thing that goes in a stash, and
 * a FILE has a size that costs turns on the way out and an encryption that
 * decides what it is worth. A single "loot" row would have to pretend those are
 * the same.
 */
enum ContentKind: string
{
    /** Money. The floor of any haul: always something, rarely much. */
    case Creds = 'creds';

    /** Something from the catalogue, by the row that defines it. */
    case Item = 'item';

    /** A file. Generated rather than authored — there is no catalogue of them. */
    case Data = 'data';

    public function label(): string
    {
        return Text::get("cyberspace.contents.{$this->value}");
    }
}
