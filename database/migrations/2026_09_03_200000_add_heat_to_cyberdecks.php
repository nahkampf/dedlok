<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Heat: what a deck makes by running, and what it can stand.
 *
 * The second budget, and deliberately NOT the same shape as the first. Power
 * either adds up or it does not — a deck that draws more than its supplies
 * deliver will not run, and readiness says so. Heat is a risk instead: a deck
 * over its thermal budget runs perfectly well and may cook a module while it
 * does. Nothing here refuses anything.
 *
 * Three columns and a new kind of hardware:
 *
 * - `items.heat_capacity` — what the CHASSIS can stand. On `items` beside
 *   `board_layout`, because it belongs to the deck and decks have no numbers
 *   table of their own; `deck_modules` is for modules and the editor clears it
 *   for a deck.
 * - `deck_modules.heat` — what a module MAKES. Every module has one and it may
 *   be zero, so it is a default rather than a nullable: the parallel of
 *   `power_draw`, not of a measure.
 * - `deck_modules.cooling` — what a THERMAL module takes away. A measure like
 *   the other four, so it joins the "at most one" CHECK: a module that both
 *   computed and cooled would be two modules.
 *
 * A thermal module cools and heats none, which is the exact mirror of a supply
 * giving power and drawing none, and is a CHECK for the same reason — both
 * halves are on one row.
 *
 * Four constraints have to be reopened because they enumerate a vocabulary
 * that just grew: the hardware kinds, the socket kinds, the kind/variant
 * pairing, and the measure count. A CHECK that lists values is a CHECK that
 * has to be rewritten when the list changes; that is the price of the database
 * knowing what the words are.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the enums. */
    private const HARDWARE_BEFORE = ['deck', 'cpu', 'npu', 'ram', 'nic', 'expansion', 'psu'];

    private const HARDWARE_AFTER = ['deck', 'cpu', 'npu', 'ram', 'nic', 'expansion', 'psu', 'thermal'];

    private const SLOT_KINDS_BEFORE = ['cpu', 'npu', 'ram', 'nic', 'expansion', 'psu', 'io'];

    private const SLOT_KINDS_AFTER = ['cpu', 'npu', 'ram', 'nic', 'expansion', 'psu', 'io', 'thermal'];

    private const SLOT_VARIANTS = [
        'cpu' => ['socket_g', 'socket_k', 'socket_z'],
        'ram' => ['opto', 'qdram', 'universal'],
        'expansion' => ['xgate', 'pei', 'horizon'],
        'io' => ['electrode', 'neuralink'],
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            // What the chassis can stand before it starts cooking what is in
            // it. Zero on everything that is not a deck, and on a deck that
            // nobody has given a rating yet.
            $table->unsignedInteger('heat_capacity')->default(0)->after('board_layout');
        });

        Schema::table('deck_modules', function (Blueprint $table): void {
            // What it makes by running. Every module has one and it may be
            // zero — the parallel of power_draw, not of a measure.
            $table->unsignedInteger('heat')->default(0)->after('power_draw');

            // What a thermal module takes away. A measure like the other four.
            $table->unsignedInteger('cooling')->nullable()->after('watts');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->rebuildVocabulary(self::HARDWARE_AFTER, self::SLOT_KINDS_AFTER);

        DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_one_measure`');
        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_one_measure` CHECK ('.
            '(`qflops` IS NOT NULL) + (`quantabits` IS NOT NULL) + '.
            '(`bandwidth` IS NOT NULL) + (`watts` IS NOT NULL) + (`cooling` IS NOT NULL) <= 1)'
        );

        // The mirror of chk_deck_modules_supply: what takes heat away does not
        // also make it.
        DB::statement('ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_thermal` CHECK (`cooling` IS NULL OR `heat` = 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_thermal`');

            DB::statement('ALTER TABLE `deck_modules` DROP CONSTRAINT `chk_deck_modules_one_measure`');
            DB::statement(
                'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_one_measure` CHECK ('.
                '(`qflops` IS NOT NULL) + (`quantabits` IS NOT NULL) + '.
                '(`bandwidth` IS NOT NULL) + (`watts` IS NOT NULL) <= 1)'
            );

            $this->rebuildVocabulary(self::HARDWARE_BEFORE, self::SLOT_KINDS_BEFORE);
        }

        Schema::table('deck_modules', function (Blueprint $table): void {
            $table->dropColumn(['heat', 'cooling']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('heat_capacity');
        });
    }

    /**
     * The three constraints that spell out what the words are.
     *
     * @param  list<string>  $hardware
     * @param  list<string>  $kinds
     */
    private function rebuildVocabulary(array $hardware, array $kinds): void
    {
        DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_hardware`');
        DB::statement(
            'ALTER TABLE `items` ADD CONSTRAINT `chk_items_hardware` CHECK ('.
            '`hardware` IS NULL OR `hardware` IN ('.$this->quoted($hardware).'))'
        );

        DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_kind`');
        DB::statement(
            'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_kind` CHECK ('.
            '`kind` IN ('.$this->quoted($kinds).'))'
        );

        // Thermal takes no variant, so it lands in the plain list below and
        // this only has to be regenerated rather than rewritten.
        DB::statement('ALTER TABLE `deck_slots` DROP CONSTRAINT `chk_deck_slots_variant`');
        DB::statement(
            'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_variant` CHECK ('.$this->variantPairing($kinds).')'
        );
    }

    /** @param  list<string>  $kinds */
    private function variantPairing(array $kinds): string
    {
        $clauses = [];

        // `variant IS NOT NULL` is not redundant. A CHECK fails only on FALSE,
        // and `NULL IN ('a','b')` is NULL.
        foreach (self::SLOT_VARIANTS as $kind => $variants) {
            $clauses[] = "(`kind` = '{$kind}' AND `variant` IS NOT NULL AND `variant` IN ({$this->quoted($variants)}))";
        }

        $plain = array_values(array_diff($kinds, array_keys(self::SLOT_VARIANTS)));
        $clauses[] = "(`kind` IN ({$this->quoted($plain)}) AND `variant` IS NULL)";

        return implode(' OR ', $clauses);
    }

    /** @param  list<string>  $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }
};
