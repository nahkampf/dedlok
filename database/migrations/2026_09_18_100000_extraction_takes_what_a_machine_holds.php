<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What extraction actually does with a machine's contents, once it can.
 *
 * ## One generic item for every file, for now
 *
 * Files are generated rather than authored — `system_contents` says so, and
 * there is deliberately no catalogue of them. But `inventory_items` points at
 * an `items` row, and a stash slot is drawn from that row, so a file becoming a
 * possession needs SOMETHING in the catalogue to point at. "Encrypted Data
 * Dump" is that something: one Tier One item, UNSTACKABLE because two files
 * with different sizes and different values are not the same object twice.
 *
 * ## `extracted_file` is where the real numbers live
 *
 * NOT ON THE ITEM — every dump looks alike on the shelf, the way a memory
 * shard's contents are not the shard. The specific file's label, size,
 * encryption and two values are per-INVENTORY-ROW facts, and they are JSON for
 * the same reason `characters.waiting_cause` is: decryption and selling are not
 * designed yet, and columns modelled against an unwritten mechanic get
 * modelled wrong. `App\Cyberspace\ExtractedFile` is the one author and reader.
 *
 * ## `runs.extracted` closes `RunEnding::Completed`
 *
 * That case has existed since the enum did — "they did what they came to do" —
 * with nothing to trigger it. A flag set the moment an extraction actually
 * takes something is what lets Egress tell the two apart: leaving because you
 * chose to, and leaving because there was nothing left to stay for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $itemId = DB::table('items')->insertGetId([
            'slug' => 'encrypted-data-dump',
            'name' => 'Encrypted Data Dump',
            'flavor' => 'Whatever it was on the way out, it is a block of ciphertext now. Somebody buys these encrypted, or sits on one until they can break it.',
            'container' => 'data',
            'tier' => 1,
            'rarity' => 'common',
            'base_cost' => 0,
            // Not stocked and not for sale — this is never bought, only found.
            // seed_quantity's ordinary default is exactly the right answer.
            'on_market' => false,
            'seed_quantity' => 0,
            'stackable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('inventory_items', function (Blueprint $table) use ($itemId): void {
            $table->json('extracted_file')->nullable()->after('installed_at')
                ->comment("Non-null only on a row of item {$itemId} (Encrypted Data Dump): the label, size in QB, encryption skill id and the two values a real file carried out of a system_contents row. JSON because decryption and selling are not designed - see App\\Cyberspace\\ExtractedFile, the only author and reader.");
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('extracted')->default(false)->after('purpose')
                ->comment('Has this run taken anything off the machine yet? Set the moment an extraction actually transfers something, so Egress can tell RunEnding::Completed - they did what they came to do - apart from leaving empty-handed.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('extracted');
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn('extracted_file');
        });

        DB::table('items')->where('slug', 'encrypted-data-dump')->delete();
    }
};
