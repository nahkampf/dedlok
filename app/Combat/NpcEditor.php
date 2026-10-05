<?php

declare(strict_types=1);

namespace App\Combat;

use App\Models\Npc;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of an opponent's drop list (`npc_loot`), alongside the row itself.
 *
 * The list is REPLACED wholesale on every save: a row carries no state worth keeping (a drop is a chance
 * and a range), and a fight has already copied what it needs onto itself, so a replace reaches nothing
 * running. The row and its list are written in one transaction.
 */
final class NpcEditor
{
    /**
     * @param  array<string, mixed>  $columns  the `npcs` columns
     * @param  list<array{item_id: int, chance: int, min_quantity: int, max_quantity: int}>  $loot
     */
    public function save(?Npc $npc, array $columns, array $loot): Npc
    {
        return DB::transaction(function () use ($npc, $columns, $loot): Npc {
            if ($npc === null) {
                $npc = Npc::create($columns);
            } else {
                $npc->update($columns);
            }

            $npc->loot()->delete();
            $npc->loot()->createMany($loot);

            return $npc;
        });
    }
}
