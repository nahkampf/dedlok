<?php

declare(strict_types=1);

use App\Cyberspace\SystemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let a system BE an Exchange.
 *
 * `chk_systems_type` names the vocabulary in SQL, which is exactly what it is
 * for — the type is read back as an enum and a row outside the set would be a
 * value nothing could match. A fifth case therefore arrives as a migration
 * rather than as PHP alone, which is the constraint doing its job rather than
 * getting in the way.
 *
 * Rebuilt from `SystemType::values()` so the set is written down once: the
 * enum is the vocabulary and the CHECK is its guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `systems` DROP CONSTRAINT `chk_systems_type`');
        DB::statement(
            'ALTER TABLE `systems` ADD CONSTRAINT `chk_systems_type` CHECK ('.
            "`type` IN ('".implode("','", SystemType::values())."'))"
        );
    }

    /**
     * Back to the four, and only if no desk is standing — an Exchange row
     * would violate the constraint being put back, and a migration that
     * deleted somebody's front desk to make its own SQL valid would be doing
     * something nobody asked it to.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('systems')->where('type', SystemType::Exchange->value)->exists()) {
            throw new RuntimeException('There are exchanges on the grid; remove them before narrowing the type vocabulary.');
        }

        $types = array_values(array_filter(
            SystemType::values(),
            static fn (string $type): bool => $type !== SystemType::Exchange->value,
        ));

        DB::statement('ALTER TABLE `systems` DROP CONSTRAINT `chk_systems_type`');
        DB::statement(
            'ALTER TABLE `systems` ADD CONSTRAINT `chk_systems_type` CHECK ('.
            "`type` IN ('".implode("','", $types)."'))"
        );
    }
};
