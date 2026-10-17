<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What one hosted Assembler is set to make. Written only by App\Cyberspace\FortressProduction. */
class FortressAssembly extends Model
{
    protected $table = 'fortress_assemblies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['runs' => 'integer', 'runs_done' => 'integer', 'started' => 'boolean', 'work_minutes' => 'float', 'progress_minutes' => 'float'];
    }
}
