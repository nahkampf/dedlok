<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\DeckModule;
use App\Models\DeckSlot;
use App\Models\Item;

/**
 * Puts an item's hardware where it belongs: sockets for a deck, a spec row
 * for a module, neither for anything else.
 *
 * One class rather than three branches in the controller, because the rule
 * that matters is the one that spans them — an item is a deck or a module or
 * neither, never two at once, so writing one side always means clearing the
 * other. Leaving a stale `deck_modules` row behind on an item that has just
 * become a deck would be invisible until something asked what socket the deck
 * wants.
 *
 * Always call it inside a transaction: it writes two tables.
 */
final class HardwareEditor
{
    /**
     * Where surviving sockets wait while their positions are reassigned.
     *
     * `deck_slots` is unique on (item_id, position), so the new numbering
     * cannot be written over the old one a row at a time.
     */
    private const POSITION_PARK = 100;

    /**
     * @param  array<string, mixed>  $module  deck_modules columns; ignored unless $hardware is a module
     */
    public static function apply(Item $item, ?Hardware $hardware, SlotLayout $layout, array $module = []): void
    {
        if ($hardware === null) {
            self::clear($item);
        } elseif ($hardware->isDeck()) {
            $item->deckModule()->delete();
            self::syncSlots($item, $layout);
        } else {
            $item->deckSlots()->delete();

            DeckModule::updateOrCreate(['item_id' => $item->id], $module);
        }

        // The item was very likely read before this ran, and its relations
        // now describe the row as it was. Drop them rather than leave a
        // caller reading yesterday's sockets.
        $item->unsetRelation('deckSlots')->unsetRelation('deckModule');
    }

    public static function clear(Item $item): void
    {
        $item->deckSlots()->delete();
        $item->deckModule()->delete();
    }

    /**
     * Bring a deck's sockets in line with the layout, keeping what it can.
     *
     * This WAS delete-then-insert, for the reason skill prerequisites still
     * are: the form submits the set it wants and nothing referenced a slot's
     * id. `deck_fittings` references one now, so a wholesale replace would
     * empty every player's deck every time an admin touched an unrelated
     * field. That was written down as the thing that would have to change,
     * and this is it changing.
     *
     * The diff is by TYPE, because that is the only identity a socket has:
     * one Socket K is indistinguishable from another until something is in
     * it. So per type, keep as many rows as are wanted, and when there are
     * too many, drop the EMPTY ones first — a socket somebody has filled is
     * the last one to take away.
     *
     * Taking away an occupied socket is still possible, and it cascades: the
     * fitting goes and the module returns to its owner's hands, loose. An
     * admin who removes a RAM socket from a deck a hundred people are using
     * has decided that, and the alternative is a save the editor refuses for
     * reasons nobody on that screen can see.
     */
    private static function syncSlots(Item $item, SlotLayout $layout): void
    {
        $wanted = [];

        foreach ($layout->present() as [$type, $count]) {
            $wanted[$type->key()] = $count;
        }

        $existing = $item->deckSlots()
            ->withCount('fittings')
            ->get()
            ->groupBy(static fn (DeckSlot $slot): string => $slot->type()->key());

        $kept = [];

        foreach ($existing as $key => $rows) {
            // Occupied first, so the ones that survive are the ones in use.
            $survivors = $rows
                ->sortByDesc(static fn (DeckSlot $slot): int => (int) $slot->fittings_count)
                ->values();

            $keepCount = min($survivors->count(), $wanted[$key] ?? 0);

            $kept[$key] = $survivors->take($keepCount)->all();

            $doomed = $survivors->skip($keepCount)->pluck('id')->all();

            if ($doomed !== []) {
                DeckSlot::whereKey($doomed)->delete();
            }
        }

        /*
         * Positions are unique per item, so they cannot be reassigned in
         * place — row 3 becoming row 1 collides with the row 1 that has not
         * moved yet. Park the survivors out of the way first. A deck presents
         * at most a couple of dozen sockets and the column holds 255, so the
         * offset has room it will never need.
         */
        $item->deckSlots()->increment('position', self::POSITION_PARK);

        $position = 1;

        foreach ($layout->present() as [$type, $count]) {
            $key = $type->key();
            $reused = $kept[$key] ?? [];

            for ($n = 0; $n < $count; $n++) {
                $slot = $reused[$n] ?? null;

                if ($slot === null) {
                    $item->deckSlots()->create([
                        'kind' => $type->kind->value,
                        'variant' => $type->variant?->value,
                        'position' => $position,
                    ]);
                } else {
                    /*
                     * Written through a query and not the model, because the
                     * model was read BEFORE the parking increment and still
                     * believes it is at position 1. Assigning 1 to it would
                     * be no change as far as Eloquent is concerned, and the
                     * row would quietly stay parked at 101.
                     */
                    DeckSlot::whereKey($slot->getKey())->update(['position' => $position]);
                }

                $position++;
            }
        }
    }
}
