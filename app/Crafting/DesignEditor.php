<?php

declare(strict_types=1);

namespace App\Crafting;

use App\Models\Design;
use App\Models\Item;

/**
 * THE ONLY WRITER of `designs` and `design_components`. An item stops being a design the moment its product is cleared, and the
 * recipe's lines are replaced wholesale on every save: nothing outside a recipe points at a line, and a build already under way
 * carries its own snapshot.
 */
final class DesignEditor
{
    /**
     * @param  array<string, mixed>  $columns  craft_seconds, single_use, skill_id, skill_level
     * @param  list<array<string, mixed>>  $lines
     */
    public static function apply(Item $item, ?int $productId, array $columns = [], array $lines = []): void
    {
        if ($productId === null) {
            self::clear($item);

            return;
        }

        $design = Design::query()->updateOrCreate(['item_id' => $item->getKey()], $columns + ['product_item_id' => $productId]);

        $design->components()->delete();

        foreach (array_values($lines) as $index => $line) {
            $design->components()->create($line + ['position' => $index + 1]);
        }

        $item->unsetRelation('design');
    }

    public static function clear(Item $item): void
    {
        $item->design()->delete();
        $item->unsetRelation('design');
    }
}
