<?php

declare(strict_types=1);

namespace App\Models;

use App\Wallet\Reason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of creds. Immutable: no `updated_at`, written only by `App\Wallet\Wallet`.
 *
 * @property int $id
 * @property int $character_id
 * @property int $amount
 * @property int $balance
 * @property Reason $reason
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
#[Fillable(['character_id', 'amount', 'balance', 'reason', 'note'])]
class WalletEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance' => 'integer',
            'reason' => Reason::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
