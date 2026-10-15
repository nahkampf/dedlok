<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file offered on a fortress's market. Written only by App\Cyberspace\FortressMarket. */
class FortressListing extends Model
{
    protected $table = 'fortress_listings';

    protected $guarded = [];
}
