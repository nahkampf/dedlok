<?php

declare(strict_types=1);

use App\Models\Character;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** One-off: 3000 creds to the character ACiD, through the wallet so the ledger has the line. Does nothing if there is no ACiD. */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $character = Character::where('handle', 'ACiD')->lockForUpdate()->first();

            if ($character !== null) {
                Wallet::move($character, 3000, Reason::Granted, 'Granted by the game');
            }
        });
    }

    // A grant is not taken back by rolling a migration back: the ledger line stays true.
    public function down(): void {}
};
