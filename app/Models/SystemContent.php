<?php

declare(strict_types=1);

namespace App\Models;

use App\Cyberspace\ContentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a machine on the grid is holding.
 *
 * NOT an inventory row. What a node holds is raided rather than carried, is
 * safe from what ICE does to a person, and comes back on a clock — a different
 * set of rules from a stash, which is why it is a different table rather than a
 * flag on the same one.
 *
 * Three kinds share it because they answer one question, and which columns mean
 * anything depends on `kind`. The CHECK constraints are the guarantee; this
 * class is the convenience.
 *
 * Written only by App\Cyberspace\NodeStocker. Nothing takes anything out yet:
 * extraction is the run, and the run is phase 5.
 */
#[Fillable([
    'system_id', 'kind', 'amount', 'item_id', 'quantity',
    'label', 'size_qb', 'encryption_skill_id', 'value_sealed', 'value_broken',
])]
class SystemContent extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => ContentKind::class,
            'amount' => 'integer',
            'quantity' => 'integer',
            'size_qb' => 'integer',
            'value_sealed' => 'integer',
            'value_broken' => 'integer',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** What breaks this file, as the skill that does it. */
    public function encryption(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'encryption_skill_id');
    }

    /**
     * What breaking it is worth, over selling it sealed.
     *
     * The number a player is deciding about when they choose to sit on a file
     * rather than take the quick money.
     */
    public function decryptionPremium(): int
    {
        return (int) $this->value_broken - (int) $this->value_sealed;
    }

    /** @param  Builder<self>  $query */
    public function scopeOfKind(Builder $query, ContentKind $kind): void
    {
        $query->where('kind', $kind->value);
    }
}
