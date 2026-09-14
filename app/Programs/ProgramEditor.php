<?php

declare(strict_types=1);

namespace App\Programs;

use App\Models\Item;
use App\Models\Program;

/**
 * The only writer of the `programs` table.
 *
 * The software mirror of `App\Decks\HardwareEditor`, and it takes the same
 * decision that class takes: **it always clears the side an item stopped
 * being.** An item re-saved without a kind loses its program row, because a
 * key that is merely absent from an update leaves the old one sitting there —
 * and an item that is no longer software would otherwise still be running on
 * somebody's deck.
 *
 * Unlike sockets, there is nothing here to diff. A program row is one row of
 * numbers with nothing occupying it: a running process points at an INVENTORY
 * row, never at this definition, so replacing it wholesale cannot strand
 * anything. If that ever stops being true, this becomes a diff, and the
 * comment in HardwareEditor about occupied sockets is the one to read first.
 */
final class ProgramEditor
{
    /**
     * @param  array<string, mixed>  $columns  everything but item_id and kind
     */
    public static function apply(Item $item, ?ProgramKind $kind, array $columns = []): void
    {
        if ($kind === null) {
            self::clear($item);

            return;
        }

        Program::updateOrCreate(
            ['item_id' => $item->id],
            $columns + ['kind' => $kind],
        );

        $item->unsetRelation('program');
    }

    /** Stop being software. */
    public static function clear(Item $item): void
    {
        $item->program()->delete();
        $item->unsetRelation('program');
    }
}
