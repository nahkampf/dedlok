<?php

declare(strict_types=1);

namespace App\Models;

use App\Items\UseEffect;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The consumable half of an item: what using one copy does, once. Written only by `App\Items\ConsumableEditor`.
 */
#[Fillable(['item_id', 'effects', 'usable_in_fight'])]
class Consumable extends Model
{
    protected function casts(): array
    {
        return ['effects' => 'array', 'usable_in_fight' => 'boolean'];
    }

    /**
     * The effects as kinds and amounts, skipping anything that is no longer a kind.
     *
     * @return list<array{0: UseEffect, 1: int}>
     */
    public function kinds(): array
    {
        $out = [];

        foreach ((array) $this->effects as $effect) {
            $kind = UseEffect::tryFrom((string) ($effect['kind'] ?? ''));
            $amount = (int) ($effect['amount'] ?? 0);

            if ($kind !== null && $amount > 0) {
                $out[] = [$kind, $amount];
            }
        }

        return $out;
    }

    /** "Restores 20 hit points", for a card. */
    public function describe(): string
    {
        return implode(' · ', array_map(static fn (array $e): string => $e[0]->describe($e[1]), $this->kinds()));
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
