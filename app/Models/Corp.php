<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company that owns machines on the grid.
 *
 * Distinct from the eight hardware manufacturers in WORLDBUILDING.md, which
 * brand items rather than run nodes — who BUILT a thing and who RUNS it are
 * different questions and this answers the second.
 *
 * Owners arrive by migration, like the archetype roster, because their ids are
 * written into thousands of system rows. Nothing edits them yet, and a corp
 * that still runs machines cannot be deleted.
 */
#[Fillable(['name', 'business'])]
class Corp extends Model
{
    public function systems(): HasMany
    {
        return $this->hasMany(System::class, 'owner_id');
    }
}
