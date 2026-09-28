<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\Storage;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Support\Facades\DB;

/**
 * The one door between a read and a thing somebody can carry.
 *
 * A SCAN IS NOT A POSSESSION. `system_scans` is keyed to a character: it can
 * be looked at, gone stale and spent, but it cannot be handed to anybody. This
 * is what turns a finished one into an inventory row — the object a player
 * sells to another player, and the object a mission takes as evidence when
 * missions exist.
 *
 * ## Exporting spends the read, and three rules fall out of that
 *
 * `used_at` is stamped here, in the same transaction as the row that replaces
 * it. That single write buys:
 *
 * - **One read is one object, for ever.** Without it a scanner mints ten files
 *   off one pass and sells all ten. The read is the scarce thing; the file is
 *   its only representation.
 * - **Selling what you know costs you knowing it.** `scopeLive` already skips
 *   a spent row, so the machine stops being read on the map and on the run
 *   briefing the moment its file is made. A real decision, and free.
 * - **Nothing reaches two buyers.** The file is one unstackable row: selling
 *   transfers it, spending it destroys it.
 *
 * ## The result is a union rather than a Result object
 *
 * `FittingResult` and `InjectionResult` carry a refusal beside things a caller
 * needs either way — what was displaced, what was consumed. There is nothing
 * of that kind here: an export either refuses or hands back the row it made,
 * and a wrapper holding one nullable of each would be the union with more
 * files.
 */
final readonly class ScanExporter
{
    /** The item every exported read becomes. Looked up by slug, like the dump — this runs once a press at most. */
    private const SLUG = 'scan-file';

    public function export(Character $character, string $address): ScanExportRefusal|InventoryItem
    {
        $address = strtoupper($address);

        return DB::transaction(function () use ($character, $address): ScanExportRefusal|InventoryItem {
            // Locked for the same reason the market locks a shelf: the press
            // and the settle that finishes a last pass can arrive together,
            // and a read exported twice is the one thing this class exists to
            // make impossible.
            $scan = SystemScan::query()
                ->where('character_id', $character->id)
                ->where('address', $address)
                ->live()
                ->lockForUpdate()
                ->first();

            if ($scan === null) {
                return ScanExportRefusal::Gone;
            }

            if (! $scan->isComplete()) {
                return ScanExportRefusal::Incomplete;
            }

            $item = Item::query()->where('slug', self::SLUG)->first();

            if ($item === null) {
                // The catalogue row arrives by migration. Refusing as though
                // the file had gone is the honest answer to a machine that is
                // mid-deploy, and it is the one case a player cannot cause.
                return ScanExportRefusal::Gone;
            }

            // The machine's name is COPIED, not referenced. It may burn or
            // relocate the minute after, and a file that reached through to a
            // gone row would describe nothing — see ScanFile.
            if (! Storage::fits($character, Storage::sizeOf($item))) {
                return ScanExportRefusal::NoStorage;
            }

            $name = System::query()->where('address', $address)->first()?->displayName();

            $scan->forceFill(['used_at' => now()])->save();

            return $character->inventory()->create([
                'item_id' => $item->id,
                'parent_id' => null,
                'quantity' => 1,
                'scan_file' => ScanFile::fromScan($scan, $name)->toArray(),
            ]);
        });
    }
}
