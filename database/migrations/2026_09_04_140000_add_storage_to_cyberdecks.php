<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage: the sixth measure, and the socket that carries it.
 *
 * MECHANICS.md has asked for this since software landed. A program costs three
 * different things at three different times — compute and memory only while it
 * RUNS, and storage simply by being owned — and the third had nowhere to be
 * measured against. `programs.storage_size` was a number nothing summed.
 *
 * WHY IT IS NOT JUST MORE QUANTABITS, which is the obvious shortcut and the
 * wrong one: `DeckTotals` sums by MEASURE rather than by socket, which is what
 * makes a CPU and an NPU add up to one compute figure. Give storage the same
 * measure as RAM and the same rule silently merges working memory with bulk
 * storage into one number that means neither. They share a unit — quantabits —
 * the way draw and supply share watts, and watts is two columns for exactly
 * this reason.
 *
 * `chk_deck_modules_one_measure` grows by one term. A storage module carries
 * storage and nothing else, like every other kind.
 *
 * Three CHECK constraints name the vocabulary and all three grow by one word:
 * `chk_items_hardware`, `chk_deck_slots_kind`, and `chk_deck_slots_variant` —
 * the last because a storage socket has NO variant, and that clause lists the
 * variant-less kinds explicitly rather than defaulting.
 */
return new class extends Migration
{
    private const HARDWARE = ['deck', 'cpu', 'npu', 'ram', 'nic', 'expansion', 'psu', 'thermal', 'storage'];

    public function up(): void
    {
        Schema::table('deck_modules', function (Blueprint $table): void {
            // A measure like the other five: nullable, and null on every module
            // that is not one of these.
            $table->unsignedInteger('storage')->nullable()->after('cooling');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_one_measure`');
        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_one_measure` CHECK ('.
            '(`qflops` IS NOT NULL) + (`quantabits` IS NOT NULL) + '.
            '(`bandwidth` IS NOT NULL) + (`watts` IS NOT NULL) + '.
            '(`cooling` IS NOT NULL) + (`storage` IS NOT NULL) <= 1)'
        );

        DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_hardware`');
        DB::statement(
            'ALTER TABLE `items` ADD CONSTRAINT `chk_items_hardware` CHECK ('.
            "`hardware` IS NULL OR `hardware` IN ('".implode("','", self::HARDWARE)."'))"
        );

        DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_kind`');
        DB::statement(
            'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_kind` CHECK ('.
            "`kind` IN ('cpu','npu','ram','nic','expansion','psu','io','thermal','storage'))"
        );

        // A storage socket takes no variant, so it joins the list of kinds
        // whose variant must be NULL — the clause names them rather than
        // defaulting, which is why adding a kind means editing it.
        DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_variant`');
        DB::statement(self::variantCheck("'npu','nic','psu','thermal','storage'"));
    }

    private static function variantCheck(string $variantless): string
    {
        return 'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_variant` CHECK ('.
            "(`kind` = 'cpu' AND `variant` IS NOT NULL AND `variant` IN ('socket_g','socket_k','socket_z')) OR ".
            "(`kind` = 'ram' AND `variant` IS NOT NULL AND `variant` IN ('opto','qdram','universal')) OR ".
            "(`kind` = 'expansion' AND `variant` IS NOT NULL AND `variant` IN ('xgate','pei','horizon')) OR ".
            "(`kind` = 'io' AND `variant` IS NOT NULL AND `variant` IN ('electrode','neuralink')) OR ".
            "(`kind` IN ({$variantless}) AND `variant` IS NULL))";
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_one_measure`');
            DB::statement(
                'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_one_measure` CHECK ('.
                '(`qflops` IS NOT NULL) + (`quantabits` IS NOT NULL) + '.
                '(`bandwidth` IS NOT NULL) + (`watts` IS NOT NULL) + (`cooling` IS NOT NULL) <= 1)'
            );

            DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_hardware`');
            DB::statement(
                'ALTER TABLE `items` ADD CONSTRAINT `chk_items_hardware` CHECK ('.
                "`hardware` IS NULL OR `hardware` IN ('deck','cpu','npu','ram','nic','expansion','psu','thermal'))"
            );

            DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_kind`');
            DB::statement(
                'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_kind` CHECK ('.
                "`kind` IN ('cpu','npu','ram','nic','expansion','psu','io','thermal'))"
            );

            DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_variant`');
            DB::statement(self::variantCheck("'npu','nic','psu','thermal'"));
        }

        Schema::table('deck_modules', function (Blueprint $table): void {
            $table->dropColumn('storage');
        });
    }
};
