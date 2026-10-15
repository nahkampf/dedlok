<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One installed Leecher. Written only by App\Cyberspace\Leeches. */
class Leech extends Model
{
    protected $table = 'leeches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float', 'installed_at' => 'datetime'];
    }
}
