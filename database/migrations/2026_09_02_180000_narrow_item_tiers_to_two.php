<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Five item tiers become two.
 *
 * The five-step scale (Standard, Tuned, Milspec, Prototype, Anomalous) named
 * distinctions the game does not make yet: nothing reads `tier` except the
 * card accent and the landing page's ordering, and no mechanic has ever asked
 * which of the five a thing is. Two — Tier One and Tier Two — is what the
 * content actually distinguishes, and a scale can be widened again when
 * something needs the steps. The names went with it: they were flavour for a
 * hierarchy that did not exist.
 *
 * Existing rows are CLAMPED, not rescaled: 1 stays 1, everything above becomes
 * 2. The old scale carried no mechanics, so there is no number to preserve —
 * only the constraint to satisfy. This is one-way, which is why `down()` can
 * restore the constraint but not the rows.
 *
 * The check constraint is MySQL-only, matching the table's original migration:
 * SQLite (the test connection) never had one, so there is nothing to drop.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the model. */
    private const MAX_TIER = 2;

    private const OLD_MAX_TIER = 5;

    public function up(): void
    {
        $this->dropTierCheck();

        DB::table('items')->where('tier', '>', self::MAX_TIER)->update(['tier' => self::MAX_TIER]);

        $this->addTierCheck(self::MAX_TIER);
    }

    /**
     * Widens the constraint back. The rows that were clamped are gone for
     * good — a tier 4 that became a 2 is indistinguishable from a 2.
     */
    public function down(): void
    {
        $this->dropTierCheck();
        $this->addTierCheck(self::OLD_MAX_TIER);
    }

    private function dropTierCheck(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `items` DROP CHECK `chk_items_tier`');
    }

    private function addTierCheck(int $max): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_tier` CHECK (`tier` BETWEEN 1 AND {$max})");
    }
};
