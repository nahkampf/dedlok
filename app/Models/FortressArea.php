<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A storage area in a Data Fortress. Written only by App\Cyberspace\FortressAreas. */
class FortressArea extends Model
{
    protected $table = 'fortress_areas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
