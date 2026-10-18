<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safehouses become rentable and usable (FATIGUE.md, *Safehouses*): the actions a type offers, when the next rent falls
 * due, and a cooldown per action. Storage on eviction is not built: nothing can be stored in a safehouse yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safehouses', function (Blueprint $table): void {
            $table->json('actions')->nullable()->after('rest_rate')
                ->comment('App\\Safehouses\\Action slugs this type offers (rest, patch, meditate, shower, eat). NULL or empty offers none.');
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('rent_due_at')->nullable()->after('safehouse_id')
                ->comment('When the next rent on their safehouse falls due. NULL when they have none or it is free. Written only by App\\Safehouses\\Renter.');
        });

        Schema::create('character_safehouse_cooldowns', function (Blueprint $table): void {
            $table->comment('When a safehouse action may be used again, per character. A missing row means ready. Written only by App\\Safehouses\\HomeActions.');
            $table->foreignId('character_id')->comment('Whose cooldown.')->constrained()->cascadeOnDelete();
            $table->string('action', 20)->comment('App\\Safehouses\\Action slug.');
            $table->timestamp('ready_at')->comment('From this moment the action may be used again.');
            $table->primary(['character_id', 'action']);
        });

        DB::table('safehouses')->where('slug', 'cardboard-box')->update(['actions' => json_encode(['rest'])]);
    }

    public function down(): void
    {
        Schema::dropIfExists('character_safehouse_cooldowns');

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('rent_due_at');
        });

        Schema::table('safehouses', function (Blueprint $table): void {
            $table->dropColumn('actions');
        });
    }
};
