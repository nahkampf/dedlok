<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Tracker: a Utility (`programs.tracks`) that, once given a filter and released, rolls a pass a minute at a random
 * place on the grid and stops, with a message, when the place or its eight neighbours hold a system that fits. The
 * filter and the pass clock live in `tracker_searches`, one row per running copy. An item whose slug already exists is
 * left exactly as it is, so a copy authored on the live site first is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->boolean('tracks')->default(false)->comment('A Utility that searches the grid for a system fitting a filter, a pass at a time, once released (App\\Cyberspace\\Tracker).');
        });

        Schema::create('tracker_searches', function (Blueprint $table): void {
            $table->comment('The filter and the pass clock of one RUNNING Tracker. Written only by App\\Cyberspace\\Tracker; the row goes when the program stops.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('inventory_item_id')->unique()->comment('The running copy this search belongs to. Cascades: a copy that is gone has no search.')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('character_id')->comment('Whose Tracker it is.')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->comment('Filter: the entity that must own the system. NULL means any owner. Cascades: an owner that is deleted takes the filter with it.')->constrained('entities')->cascadeOnDelete();
            $table->string('type', 32)->nullable()->comment('Filter: a SystemType value. NULL means any type.');
            $table->unsignedTinyInteger('grade')->nullable()->comment('Filter: the grade a system must have (an Exchange\'s is the desk\'s own). NULL means any grade.');
            $table->timestamp('pass_started_at')->nullable()->comment('NULL while the Tracker is only filtered; once released, the start of the pass in progress. Settling credits whole passes from here.');
            $table->timestamps();
        });

        $now = now();

        if (! DB::table('items')->where('slug', 'tracker')->exists()) {
            $id = DB::table('items')->insertGetId([
                'slug' => 'tracker', 'name' => 'Tracker', 'container' => 'data', 'tier' => 2, 'rarity' => 'rare',
                'flavor' => 'Give it a description of what you are after and let it loose. It sniffs about the grid a pass at a time, and tells you the moment it finds something that fits.',
                'stackable' => true, 'base_cost' => 500, 'on_market' => false, 'seed_quantity' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('programs')->insert([
                'item_id' => $id, 'kind' => 'utility', 'tracks' => true, 'qflops_cost' => 0.5, 'memory_cost' => 0.5,
                'storage_size' => 1, 'background_process' => true, 'multithreaded' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('items')->where('slug', 'tracker')->delete();
        Schema::dropIfExists('tracker_searches');
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn('tracks'));
    }
};
