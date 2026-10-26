<?php

declare(strict_types=1);

namespace App\Models;

use App\Insurance\InsuranceKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One term of cover.
 *
 * @property int $character_id
 * @property InsuranceKind $kind
 * @property int $share
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $lapsed_at
 */
#[Fillable(['character_id', 'kind', 'share', 'starts_at', 'ends_at', 'reminded_at', 'lapsed_at'])]
class InsurancePolicy extends Model
{
    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => InsuranceKind::class,
            'share' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'lapsed_at' => 'immutable_datetime',
        ];
    }
}
