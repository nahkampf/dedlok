<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wotan: a Utility that swarms (`programs.swarms`).
 *
 * While it runs on the equipped deck it takes a random system on the main grid that the owner has no complete scan of, reads
 * it to completion at triple the time, and moves on to the next. Tier 1, uncommon, off the market and not craftable until the
 * item editor says otherwise. An existing item with the slug is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->boolean('swarms')->default(false)->after('jumps')
                ->comment('A Utility that scans on its own: while it runs it reads random main-grid systems the owner has no complete scan of, one after another.');
        });

        // Game content for the live world: the suite builds its own rows.
        if (app()->runningUnitTests() || DB::table('items')->where('slug', 'wotan')->exists()) {
            return;
        }

        $now = now();

        $item = DB::table('items')->insertGetId([
            'slug' => 'wotan', 'name' => 'Wotan',
            'flavor' => 'An autonomous scanner that traverses the cyberspace grid and performs system fingerprinting scans on nodes. It is much slower than performing this manually, but at least it\'s automatic and leaves you free to do other things.',
            'container' => 'data', 'tier' => 1, 'rarity' => 'uncommon', 'base_cost' => 0, 'on_market' => false, 'seed_quantity' => 0,
            'stackable' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('programs')->insert([
            'item_id' => $item, 'kind' => 'utility', 'qflops_cost' => 2, 'memory_cost' => 1, 'storage_size' => 0.5,
            'background_process' => true, 'multithreaded' => true, 'swarms' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $ids = DB::table('items')->where('slug', 'wotan')->pluck('id');

        DB::table('programs')->whereIn('item_id', $ids)->delete();
        DB::table('items')->whereIn('id', $ids)->delete();

        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn('swarms'));
    }
};
