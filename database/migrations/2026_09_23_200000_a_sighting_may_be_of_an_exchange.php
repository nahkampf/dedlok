<?php

declare(strict_types=1);

use App\Cyberspace\SystemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A contact may be an Exchange.
 *
 * `chk_systems_type` was widened when the type arrived; `chk_contacts_type`
 * was not, and a sighting is written for everything standing in the swept
 * window — so the first desk placed near the portal made JACKING IN a 500.
 * Every player's first act, broken by a constraint two tables away from the
 * change that caused it.
 *
 * THE LESSON IS THAT THE VOCABULARY IS WRITTEN DOWN IN MORE THAN ONE PLACE.
 * Three tables name the system types in SQL: `systems`, `system_contacts` and
 * `loot_tables`. A new case has to visit all three and decide, rather than
 * being widened everywhere by reflex.
 *
 * `loot_tables` is deliberately LEFT ALONE: a table of what a type of system
 * holds has nothing to say about a desk, which holds nothing and is never
 * raided. A row naming `exchange` there would be a weighting for a draw that
 * can never happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `system_contacts` DROP CONSTRAINT `chk_contacts_type`');
        DB::statement(
            'ALTER TABLE `system_contacts` ADD CONSTRAINT `chk_contacts_type` CHECK ('.
            "`type` IN ('".implode("','", SystemType::values())."'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('system_contacts')->where('type', SystemType::Exchange->value)->exists()) {
            throw new RuntimeException('Somebody has seen an exchange; forget those sightings before narrowing the vocabulary.');
        }

        $types = array_values(array_filter(
            SystemType::values(),
            static fn (string $type): bool => $type !== SystemType::Exchange->value,
        ));

        DB::statement('ALTER TABLE `system_contacts` DROP CONSTRAINT `chk_contacts_type`');
        DB::statement(
            'ALTER TABLE `system_contacts` ADD CONSTRAINT `chk_contacts_type` CHECK ('.
            "`type` IN ('".implode("','", $types)."'))"
        );
    }
};
