<?php

declare(strict_types=1);

namespace App\Models;

use App\Drugs\Family;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One character's books for one family.
 *
 * `App\Drugs\DrugLedger` is the only thing that writes one, and a missing row
 * means clean — absent and zero are the same answer, which is why nothing
 * creates these in advance.
 */
#[Fillable(['character_id', 'family', 'addiction', 'tolerance'])]
class DrugUse extends Model
{
    protected $table = 'character_drug_use';

    protected function casts(): array
    {
        return [
            'family' => Family::class,
            'addiction' => 'float',
            'tolerance' => 'float',
        ];
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
