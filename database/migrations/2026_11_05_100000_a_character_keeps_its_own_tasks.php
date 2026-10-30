<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tasks a player sets for themselves, on the Tasks & Missions screen. A reminder and nothing else: no game system reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_tasks', function (Blueprint $table): void {
            $table->comment('A task a player wrote for themselves on the Tasks & Missions screen. Nothing in the game reads it.');
            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')->comment('The character whose task it is.')->constrained()->cascadeOnDelete();
            $table->string('title', 255)->comment('What the player wrote.');
            $table->unsignedTinyInteger('priority')->comment('An App\Tasks\TaskPriority case: 1 low, 2 medium, 3 high.');
            $table->timestamp('completed_at')->nullable()->comment('When it was ticked off; NULL while it is still to do. Reintroducing a task clears it.');
            $table->timestamp('created_at')->nullable()->comment('When the task was written.');
            $table->timestamp('updated_at')->nullable()->comment('When the task last changed.');

            $table->index(['character_id', 'completed_at'], 'idx_player_tasks_character_done');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_tasks');
    }
};
