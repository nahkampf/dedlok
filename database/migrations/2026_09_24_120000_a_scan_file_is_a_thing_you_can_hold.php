<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A finished read, turned into something somebody else can buy.
 *
 * ## Why a scan has to stop being a scan
 *
 * `system_scans` is keyed to a character. It cannot be handed anywhere, which
 * is fine while an agent is the only buyer and fatal the moment a scanner is
 * meant to make a living selling intel to other runners. A file somebody else
 * can own has to be a row in an inventory, so this is the second use of the
 * shape `extracted_file` already took: one generic catalogue item, and the
 * particular file's numbers as JSON on the inventory row.
 *
 * ## Exporting SPENDS the read
 *
 * `system_scans.used_at` has read *"Nothing spends one yet"* since the table
 * was created. This is what spends one — not the hand-in, and not the sale.
 * Stamping it at the door is what makes one read exactly one object for ever:
 * without it a scanner mints ten files off a single pass and sells all ten.
 * It also means selling what you know costs you knowing it, since `scopeLive`
 * already honours the column and the map and the briefing already read through
 * it. Both of those fall out of a column that was already here.
 *
 * ## No position, deliberately
 *
 * The file carries the ADDRESS and never x/y. An address is meaningless by
 * design and trading one is expected; coordinates are what a bookmark may only
 * be set on swept ground to keep from becoming tradeable. A file that carried
 * its position would hand that rule to anybody with creds.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $itemId = DB::table('items')->insertGetId([
            'slug' => 'scan-file',
            'name' => 'Scan File',
            'flavor' => 'Everything one instrument could get a machine to admit, in a block small enough to hand over. Worth what the machine is worth to somebody else, and worth nothing at all the day after tomorrow.',
            'container' => 'data',
            'tier' => 1,
            'rarity' => 'common',
            // Priced by whoever is selling it. Nothing stocks one and nothing
            // rolls for one: every scan file in the world was made by a player.
            'base_cost' => 0,
            'on_market' => false,
            'seed_quantity' => 0,
            // Two files are two different machines. Merging them into a
            // quantity would destroy the only part of a file that matters.
            'stackable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('inventory_items', function (Blueprint $table) use ($itemId): void {
            $table->json('scan_file')->nullable()->after('extracted_file')
                ->comment("Non-null only on a row of item {$itemId} (Scan File): the address, the composed name and every fact a completed system_scans row had found out, frozen at export. JSON for extracted_file's reason - see App\\Cyberspace\\ScanFile, the only author and reader. No position: an address is tradeable by design and coordinates are not.");
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn('scan_file');
        });

        DB::table('items')->where('slug', 'scan-file')->delete();
    }
};
