<?php

declare(strict_types=1);

use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Item;
use App\Stats\InjuryLedger;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off: Noodle's defeat on run 1297 (2026-10-23) was resolved four times by overlapping requests, so ICE XII's Surge
 * took six modules and its Skim took creds more than once. The Erase took everything on the deck before the later
 * resolutions, so what it destroyed was a single legitimate Erase and is not restored.
 *
 * The six modules go back to the stash (which fitting each sat in is not recorded), and the creds are the one extra
 * skim the balance actually lost: three of them read the same balance and wrote it back once. Does nothing if there is
 * no Noodle.
 *
 * His max SI took four Flatlines (-21 points) where one was owed. Which of the four rolls was the real one is not
 * recorded; the arithmetic allows 6 to 8 points, so he keeps 6 and 15 come back.
 */
return new class extends Migration
{
    private const MODULES = [
        'CirCUT ICEMate II',
        'Hengdian S-Vault',
        'Aegis Compute 14',
        'Kurogane LNK-1',
        'Kurogane BLK-16X',
        'Kurogane PWR-300',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $character = Character::where('handle', 'Noodle')->lockForUpdate()->first();

            if ($character === null) {
                return;
            }

            foreach (self::MODULES as $name) {
                $item = Item::where('name', $name)->first();

                if ($item !== null) {
                    ItemGranter::grant($character, $item);
                }
            }

            app(InjuryLedger::class)->heal($character, InjuryLedger::NEURAL, 15);
            $character->save();

            Wallet::move($character, 1934, Reason::Granted, 'Restored: a defeat resolved more than once');
        });
    }

    // A grant is not taken back by rolling a migration back: the ledger line stays true.
    public function down(): void {}
};
