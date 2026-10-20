<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Support\Facades\DB;

/**
 * Reading somebody else's file into your own records.
 *
 * THE MIRROR OF `ScanExporter`, and the half that makes a scan file worth
 * buying. Export turns knowledge into an object; this turns an object back
 * into knowledge, for whoever is holding it. Without it a bought file is a row
 * nothing reads — the seller has been paid for a keepsake.
 *
 * ## It spends the file, for the export's reason
 *
 * The row is deleted. One read stays one object: a file that could be read in
 * and still sold on would let one pass supply a district. What a buyer gets is
 * exactly what the scanner gave up.
 *
 * ## The clock does not restart
 *
 * `expires_at` is copied off the file rather than measured from now, so a file
 * bought at hour 47 is worth an hour. That is the whole price signal on a
 * second-hand file, and resetting it would make an old file as good as a fresh
 * one the moment it changed hands.
 *
 * ## The position is looked up, not carried
 *
 * `system_scans.x`/`y` are NOT NULL and a file deliberately carries neither —
 * see `ScanFile`. They are read off the machine the address resolves to now,
 * which is also why a gone address is a refusal: a scan row is where the map
 * hangs a grade, and hanging one at a remembered position would put it against
 * whatever has since moved in.
 */
final readonly class ScanImporter
{
    public function import(Character $character, InventoryItem $row): ScanImportRefusal|SystemScan
    {
        $file = $row->scanFile();

        if ($file === null) {
            return ScanImportRefusal::NotAFile;
        }

        if ($file->hasExpired()) {
            return ScanImportRefusal::Stale;
        }

        $system = System::query()->where('address', $file->address)->first();

        if ($system === null) {
            return ScanImportRefusal::Gone;
        }

        return DB::transaction(function () use ($character, $row, $file, $system): ScanImportRefusal|SystemScan {
            // One scan per character per machine, so an existing row is the
            // thing standing in the way rather than a second opinion. A live
            // and complete one has nothing to learn; anything else — spent,
            // stale, or a partial read of their own — is replaced, which is
            // the rule a stale file already follows.
            $existing = SystemScan::query()
                ->where('character_id', $character->id)
                ->where('address', $file->address)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $existing->isSpent() && $existing->isComplete()) {
                    return ScanImportRefusal::AlreadyKnown;
                }

                $existing->delete();
            }

            $row->delete();

            return SystemScan::query()->create([
                'character_id' => $character->id,
                'address' => $file->address,
                'grid_id' => $system->position()->grid,
                'x' => $system->x,
                'y' => $system->y,
                'grade' => $file->grade,
                'os_skill_id' => $file->osSkillId,
                'ids_detection' => $file->idsDetection,
                'tracer_detection' => $file->tracerDetection,
                'tracer_rate' => $file->tracerRate,
                'ice_level' => $file->iceLevel,
                'ice_bounty' => $file->iceBounty,
                // ONE, AND NOT THE NUMBER IT TOOK. Nobody here pointed an
                // instrument at anything; `passes` exists to cap further
                // rolling, and `chk_system_scans_passes` refuses zero beside a
                // grade. A complete file has nothing left to roll for anyway.
                'passes' => 1,
                'scanned_at' => $file->madeAt ?? now(),
                'expires_at' => $file->expiresAt,
            ]);
        });
    }
}
