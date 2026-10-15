<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One queued production job. Written only by App\Cyberspace\FortressProduction. */
class FortressJob extends Model
{
    protected $table = 'fortress_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work' => 'float', 'progress' => 'float', 'started' => 'boolean'];
    }
}
