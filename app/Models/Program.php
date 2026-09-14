<?php

declare(strict_types=1);

namespace App\Models;

use App\Programs\ProgramKind;
use App\Stats\Modifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The software half of an item, the way DeckModule is the hardware half.
 *
 * An item IS a program because one of these points at it — there is no column
 * on `items` saying so, for the same reason `deck_modules` carries the numbers
 * and not the word: a row cannot then disagree with its item about what it is.
 *
 * Three costs, and they are three because they are paid at different times:
 * storage by owning it, compute and memory only while it runs.
 */
#[Fillable([
    'item_id', 'kind', 'qflops_cost', 'memory_cost', 'storage_size',
    'background_process', 'modifiers',
])]
class Program extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => ProgramKind::class,
            'qflops_cost' => 'integer',
            'memory_cost' => 'integer',
            'storage_size' => 'integer',
            'background_process' => 'boolean',
            'modifiers' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * What running this does to its owner's stats.
     *
     * Built through Modifier::fromArray(), so a malformed definition throws
     * where it is read rather than quietly doing nothing — and so the resolver
     * never learns that programs exist.
     *
     * @return list<Modifier>
     */
    public function modifiers(?string $source = null): array
    {
        $modifiers = [];

        foreach ((array) ($this->modifiers ?? []) as $definition) {
            $modifiers[] = Modifier::fromArray(
                (array) $definition + ['source' => $source ?? $this->item?->name]
            );
        }

        return $modifiers;
    }

    /** A program that only gates. Perfectly ordinary — an icebreaker is one. */
    public function isGateOnly(): bool
    {
        return ($this->modifiers ?? []) === [];
    }
}
