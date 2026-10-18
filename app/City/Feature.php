<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/** What a location can do. It has the feature when it has the rows. */
enum Feature: string
{
    case Shop = 'shop';
    case Clinic = 'clinic';
    case Food = 'food';

    public function label(): string
    {
        return Text::get("city.features.{$this->value}");
    }
}
