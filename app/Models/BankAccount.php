<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A character's account at Kronos Capital. Only `App\Bank\Bank` writes it.
 *
 * @property int $character_id
 * @property int $balance
 */
#[Fillable(['character_id', 'balance'])]
class BankAccount extends Model
{
    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    /** @return HasMany<BankEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(BankEntry::class);
    }
}
