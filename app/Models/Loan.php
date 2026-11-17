<?php

declare(strict_types=1);

namespace App\Models;

use App\Bank\LoanState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loan from Kronos Capital. Only `App\Bank\Loans` writes it.
 *
 * @property int $character_id
 * @property int $borrowed
 * @property int $principal
 * @property int $interest
 * @property int $payment
 * @property LoanState $state
 * @property CarbonImmutable|null $next_due_at
 * @property CarbonImmutable|null $missed_at
 * @property CarbonImmutable|null $defaulted_at
 * @property int|null $settlement
 * @property CarbonImmutable|null $refused_at
 * @property int $seizure_nights
 */
#[Fillable(['character_id', 'borrowed', 'principal', 'interest', 'payment', 'state', 'next_due_at', 'missed_at', 'defaulted_at', 'settlement', 'refused_at', 'seizure_nights', 'repaid_at'])]
class Loan extends Model
{
    protected function casts(): array
    {
        return [
            'borrowed' => 'integer', 'principal' => 'integer', 'interest' => 'integer', 'payment' => 'integer',
            'state' => LoanState::class, 'settlement' => 'integer', 'seizure_nights' => 'integer',
            'next_due_at' => 'immutable_datetime', 'missed_at' => 'immutable_datetime', 'defaulted_at' => 'immutable_datetime',
            'refused_at' => 'immutable_datetime', 'repaid_at' => 'immutable_datetime',
        ];
    }

    /** Everything still owed. */
    public function owed(): int
    {
        return $this->principal + $this->interest;
    }

    /** What the next instalment is: the fixed payment, or what is left when that is more. */
    public function due(): int
    {
        return min($this->payment, $this->owed());
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('state', [LoanState::Active->value, LoanState::Defaulted->value]);
    }

    /** @return HasMany<LoanPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class);
    }
}
