<?php

declare(strict_types=1);

namespace App\Models;

use App\Bank\EntryKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable line of an account's statement.
 *
 * @property int $amount
 * @property int $balance
 * @property EntryKind $kind
 */
#[Fillable(['bank_account_id', 'amount', 'balance', 'kind', 'note'])]
class BankEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance' => 'integer', 'kind' => EntryKind::class, 'created_at' => 'immutable_datetime'];
    }
}
