<?php

declare(strict_types=1);

namespace App\Models;

use App\Standing\Band;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entity's front desk: what is on offer there, and who is behind it.
 *
 * A system with one of these rows is an Exchange. Nothing can be done TO it —
 * no run, no probe, no burn — and everything a player does there is done WITH
 * somebody: `SystemType::isExchange()` is what the screens ask.
 *
 * `grade` is 1-5 and belongs to this desk and its agent alike. It is not the
 * system's own grade, which means difficulty and stays at its default here.
 */
#[Fillable(['system_id', 'grade', 'agent', 'min_standing'])]
class Exchange extends Model
{
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'min_standing' => 'float',
        ];
    }

    /** @return BelongsTo<System, $this> */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /** Whose desk it is. Read through the system, which carries the owner. */
    public function entity(): ?Entity
    {
        return $this->system?->owner;
    }

    /**
     * Which systems this desk's work may name.
     *
     * The bands overlap, so each rung shares ground with the one above it: the
     * bottom desk is walkable for a bare character and the top one is not
     * survivable without the training and the hardware behind it.
     *
     * @return array{int, int}
     */
    public function targetGrades(): array
    {
        return match ($this->grade) {
            1 => [1, 3],
            2 => [3, 5],
            3 => [5, 7],
            4 => [6, 8],
            default => [7, 9],
        };
    }

    /** Will they deal with somebody who stands here? */
    public function willDealWith(float $standing): bool
    {
        return $standing >= (float) $this->min_standing;
    }

    /** The band somebody has to reach before the door opens. */
    public function bandRequired(): Band
    {
        return Band::for((float) $this->min_standing);
    }
}
