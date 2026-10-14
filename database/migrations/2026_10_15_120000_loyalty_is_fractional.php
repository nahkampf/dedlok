<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty and a shelf's price in it carry three decimals, so a thing can cost 0.2. The ledger's delta was
 * DECIMAL(6,3), which tops out at 999.999 (a 1000-loyalty price could not have been written), so it widens too.
 * MySQL only: SQLite has no fixed precision to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE entity_books MODIFY loyalty DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Favours owed, to three decimals, earned only by missions and spent only at the entity store. Never decays: a grudge fades, a favour bank does not.'");
        DB::statement("ALTER TABLE entity_offers MODIFY loyalty_price DECIMAL(12,3) UNSIGNED NOT NULL COMMENT 'What it costs in loyalty, to three decimals (0.2 is a price). Spent out of the entity\\'s books.'");
        DB::statement("ALTER TABLE entity_ledger MODIFY delta DECIMAL(12,3) NOT NULL COMMENT 'What LANDED, not what was intended: already clamped, so summing the column reproduces the books exactly. Zero is an honest entry — it says the act changed nothing.'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE entity_books MODIFY loyalty INT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE entity_offers MODIFY loyalty_price INT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE entity_ledger MODIFY delta DECIMAL(6,3) NOT NULL');
    }
};
