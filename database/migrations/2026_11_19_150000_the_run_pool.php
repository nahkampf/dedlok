<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The run pool: items a runner puts up for the others of a co-op run to claim for that run only. An offered item LEAVES the
 * offerer's stash (one copy) and sits here; a claim hands a copy to the claimant. What is still unused when the run ends goes
 * back to the offerer, and is destroyed when the run ends badly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_pool_items', function (Blueprint $table): void {
            $table->comment('One copy of an item put up for a co-op run. Gone with the party; App\\Cyberspace\\Party\\RunPool is its only writer, and refunds before the party goes.');
            $table->id()->comment('Primary key.');
            $table->foreignId('run_party_id')->comment('The party this was offered to. Cascades, so RunPool hands everything back BEFORE a party is deleted.')->constrained('run_parties')->cascadeOnDelete();
            $table->foreignId('item_id')->comment('What was offered. One copy per row.')->constrained('items')->cascadeOnDelete();
            $table->foreignId('offered_by_character_id')->comment('Who put it up, and who gets it back.')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('claimed_by_character_id')->nullable()->comment('The runner who claimed it, who holds a copy in their stash for the run. NULL is still in the pool.')->constrained('characters')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('When it was offered.');
            $table->timestamp('updated_at')->nullable()->comment('When it was last claimed or returned.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_pool_items');
    }
};
