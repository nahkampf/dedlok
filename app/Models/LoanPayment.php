<?php

declare(strict_types=1);

namespace App\Models;

use App\Bank\PaymentSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** What one payment on a loan was made of. Immutable. */
#[Fillable(['loan_id', 'interest', 'principal', 'fee', 'source'])]
class LoanPayment extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['interest' => 'integer', 'principal' => 'integer', 'fee' => 'integer', 'source' => PaymentSource::class, 'created_at' => 'immutable_datetime'];
    }
}
