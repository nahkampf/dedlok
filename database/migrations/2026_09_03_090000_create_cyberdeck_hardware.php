<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cyberdecks, the modules that go in them, and the sockets in between.
 *
 * Three tables' worth of thinking, in one column and two tables:
 *
 * 1. `items.hardware` says what a definition IS, hardware-wise — a deck, or a
 *    module of one kind. NULL is everything else, which is most of the
 *    catalogue. It is the only place the kind is written down: `deck_modules`
 *    deliberately does not repeat it, because a row that could disagree with
 *    its item about being a CPU is a row someone has to defend against.
 * 2. `deck_slots` is what a deck PRESENTS: one row per socket, not a count,
 *    because a socket is the thing a module will eventually be fitted into and
 *    a count cannot be pointed at. A deck's whole specification is the set of
 *    these rows — see App\Decks\SlotLayout, which is that set as a value.
 * 3. `deck_modules` is what a module IS WORTH: its socket type and the one
 *    number that describes it. One row per module item, keyed on the item.
 *
 * Why the numbers are not columns on `items`: five of the six are meaningless
 * for a rifle, and `items` is the table every listing in the game already
 * reads. A sibling row keyed on the item costs a join nobody makes on a hot
 * path and keeps the catalogue's own table about the catalogue.
 *
 * The asymmetry between a slot's type and a module's is deliberate and is the
 * point of the whole design: a RAM slot may be `universal` and take either
 * kind of stick, but no stick is `universal` itself. That is why the two have
 * separate CHECK constraints over overlapping value sets, and why one class
 * (App\Decks\SlotType) owns the question of what fits what.
 */
return new class extends Migration
{
    /** Duplicated on purpose: a migration is historical and must not follow the model. */
    private const HARDWARE = ['deck', 'cpu', 'npu', 'ram', 'nic', 'expansion', 'psu'];

    private const SLOT_KINDS = ['cpu', 'npu', 'ram', 'nic', 'expansion', 'psu', 'io'];

    /** What each kind of slot may be. The kinds absent from this map take no variant at all. */
    private const SLOT_VARIANTS = [
        'cpu' => ['socket_g', 'socket_k', 'socket_z'],
        'ram' => ['opto', 'qdram', 'universal'],
        'expansion' => ['xgate', 'pei', 'horizon'],
        'io' => ['electrode', 'neuralink'],
    ];

    /** Every variant a MODULE may carry. `universal` is missing on purpose. */
    private const MODULE_VARIANTS = ['socket_g', 'socket_k', 'socket_z', 'opto', 'qdram', 'xgate', 'pei', 'horizon'];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Indexed: the deck screens filter on it, and so does every list
            // of "what could go in this socket".
            $table->string('hardware', 20)->nullable()->after('accepts')->index();
        });

        /*
         * One row per socket on a deck. Position orders them and nothing more
         * — it exists so a deck's sockets come back in the order they were
         * written rather than in id order after an edit.
         *
         * Cascading: a deck's sockets are part of the deck's definition, not
         * data of their own. Deleting the item takes them.
         */
        Schema::create('deck_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 20);

            // NULL for the kinds that have only one flavour — NPU, NIC, PSU.
            // Constrained below so a kind and its variant cannot disagree.
            $table->string('variant', 20)->nullable();

            $table->unsignedTinyInteger('position');

            $table->timestamps();

            $table->unique(['item_id', 'position']);
        });

        /*
         * What a module is worth. One row per module item.
         *
         * The measures are four nullable columns rather than one generic
         * `rating`, because they are not the same quantity: qFLOPS, QB, QB/s
         * and watts do not convert into each other and a single column would
         * need a unit column beside it to mean anything. Exactly which one a
         * module fills follows from `items.hardware`, and an expansion card
         * fills none of them — what an expansion card DOES is undecided, and
         * a made-up number now would be a number to migrate away from later.
         */
        Schema::create('deck_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();

            // Which socket it fits. NULL for NPU, NIC and PSU modules, which
            // fit their kind of socket and have no sub-type.
            $table->string('variant', 20)->nullable();

            // What it takes out of the deck's power budget. A PSU draws
            // nothing — it is the other side of that sum.
            $table->unsignedInteger('power_draw')->default(0);

            // CPU and NPU: raw compute.
            $table->unsignedInteger('qflops')->nullable();

            // RAM: quantabits.
            $table->unsignedInteger('quantabits')->nullable();

            // NIC: QB per second.
            $table->unsignedInteger('bandwidth')->nullable();

            // PSU: what it supplies.
            $table->unsignedInteger('watts')->nullable();

            $table->timestamps();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $hardware = $this->quoted(self::HARDWARE);
        $kinds = $this->quoted(self::SLOT_KINDS);
        $moduleVariants = $this->quoted(self::MODULE_VARIANTS);

        DB::statement("ALTER TABLE `items` ADD CONSTRAINT `chk_items_hardware` CHECK (`hardware` IS NULL OR `hardware` IN ({$hardware}))");

        DB::statement("ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_kind` CHECK (`kind` IN ({$kinds}))");

        // A kind and its variant have to agree. Spelled out per kind rather
        // than as one IN list, because "a CPU socket is one of three sockets"
        // is the actual rule and an IN list would let a RAM slot be a Socket Z.
        DB::statement(
            'ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_variant` CHECK ('.$this->variantPairing().')'
        );

        DB::statement('ALTER TABLE `deck_slots` ADD CONSTRAINT `chk_deck_slots_position` CHECK (`position` >= 1)');

        // No module is `universal`: that is a property of a socket that takes
        // either kind of stick, never of a stick.
        DB::statement("ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_variant` CHECK (`variant` IS NULL OR `variant` IN ({$moduleVariants}))");

        // At most one measure, because they are different quantities and a
        // module that claimed two would be two modules. At most rather than
        // exactly one: an expansion card has none.
        DB::statement(
            'ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_one_measure` CHECK ('.
            '(`qflops` IS NOT NULL) + (`quantabits` IS NOT NULL) + '.
            '(`bandwidth` IS NOT NULL) + (`watts` IS NOT NULL) <= 1)'
        );

        // Anything that supplies power does not also draw it.
        DB::statement('ALTER TABLE `deck_modules` ADD CONSTRAINT `chk_deck_modules_supply` CHECK (`watts` IS NULL OR `power_draw` = 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_modules');
        Schema::dropIfExists('deck_slots');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_hardware`');
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['hardware']);
            $table->dropColumn('hardware');
        });
    }

    private function variantPairing(): string
    {
        $clauses = [];

        // `variant IS NOT NULL` is not redundant. A CHECK fails only on FALSE,
        // and `NULL IN ('a','b')` is NULL — so without it a CPU socket with no
        // socket type at all would be accepted by a constraint whose whole job
        // is to say which socket types exist.
        foreach (self::SLOT_VARIANTS as $kind => $variants) {
            $clauses[] = "(`kind` = '{$kind}' AND `variant` IS NOT NULL AND `variant` IN ({$this->quoted($variants)}))";
        }

        $plain = array_values(array_diff(self::SLOT_KINDS, array_keys(self::SLOT_VARIANTS)));
        $clauses[] = "(`kind` IN ({$this->quoted($plain)}) AND `variant` IS NULL)";

        return implode(' OR ', $clauses);
    }

    /** @param  list<string>  $values */
    private function quoted(array $values): string
    {
        return "'".implode("', '", $values)."'";
    }
};
