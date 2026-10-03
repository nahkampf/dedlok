<?php

declare(strict_types=1);

namespace App\City;

use App\Support\Text;

/** What is done on the table. Stored on `city_activities.operation`. */
enum SurgeryOperation: string
{
    case Install = 'install';
    case Remove = 'remove';

    public function label(): string
    {
        return Text::get("city.surgery.ops.{$this->value}");
    }
}
