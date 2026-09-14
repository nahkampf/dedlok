<?php

declare(strict_types=1);

use App\Implants\BodyRegion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implants: things installed in a body rather than carried in a bag.
 *
 * TWO COLUMNS AND NO NEW TABLE, which is the whole shape of this migration.
 *
 * `items.implant_region` says what a definition IS, the way `items.hardware`
 * says a definition is a CPU. It is the only place the region is written down,
 * so an installed row can never disagree with its own definition about where
 * it went.
 *
 * `inventory_items.installed_at` says a particular object is IN somebody. It
 * is a column and not a table for the same reason `running_on_deck_id` is one:
 * installing names one thing — this row is in this body — and a table would be
 * a row that exists only to say a boolean. Where a cyberdeck needed
 * `deck_fittings`, it was because a fitting names THREE things and only the
 * pair of them is a place.
 *
 * There is no `implant_slots` table either, and that asymmetry with
 * `deck_slots` is deliberate: every character has the same body. Sockets are
 * rows because decks differ; regions are an enum because bodies do not.
 * Capacity is enforced by App\Implants\ImplantFitter, which is the only writer.
 *
 * `items.modifiers` arrives here too, because an implant that changes nothing
 * is not an implant. MECHANICS is explicit that a thing with a row carries its
 * modifiers ON that row — `archetype_perks.modifiers` and `programs.modifiers`
 * already do — which is why there is no `config/implants.php` and never will
 * be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->string('implant_region', 20)->nullable()->after('hardware')->index()
                ->comment('Where in a body this implant goes, or NULL for anything that is not an implant. The only place the region is written down.');

            $table->json('modifiers')->nullable()->after('implant_region')
                ->comment('What this item does to its owner while it is installed or carried, as JSON. Same shape as archetype_perks.modifiers and programs.modifiers. NULL means it changes nothing.');
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->timestamp('installed_at')->nullable()->after('started_at')
                ->comment('When this object was installed in its owner. NULL means it is not: in the stash, in a container, or fitted into a deck. Only implants are ever installed.');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // The region vocabulary, said once in SQL. An implant filed under a
        // region nothing draws is an implant nobody can reach.
        DB::statement(
            'ALTER TABLE `items` ADD CONSTRAINT `chk_items_implant_region` CHECK ('.
            '`implant_region` IS NULL OR `implant_region` IN ('.
            "'".implode("','", BodyRegion::values())."'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `items` DROP CONSTRAINT `chk_items_implant_region`');
        }

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn('installed_at');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn(['implant_region', 'modifiers']);
        });
    }
};
