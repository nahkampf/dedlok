<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A meal is two numbers wherever it lives (`meal_fullness`, `meal_fatigue`: App\Characters\Meal), and a safehouse can
 * keep a character's things (docs/FATIGUE.md, *Safehouses*). Stored rows are ordinary inventory rows tagged with the
 * safehouse; a lost home gives them 48 hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safehouses', function (Blueprint $table): void {
            $table->renameColumn('eat_fullness', 'meal_fullness');
            $table->renameColumn('eat_fatigue', 'meal_fatigue');
            $table->unsignedSmallInteger('storage_slots')->default(0)->after('stealth')
                ->comment('How many rows a character may keep stored here (a stack is one). 0 offers no storage.');
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->foreignId('safehouse_id')->nullable()
                ->comment('Stored at this safehouse: the row is out of the stash (scopeLoose), safe from muggings, and has no stack key. NULL is ordinary. RESTRICT: a safehouse holding things cannot be deleted. Written only by App\\Safehouses\\SafehouseStorage.')
                ->constrained()->restrictOnDelete();
            $table->timestamp('storage_expires_at')->nullable()
                ->comment('Set when the owner lost the home these are stored at: until then they may Collect them; after it the row is deleted. NULL while the home is theirs.');
            $table->index('storage_expires_at');
        });

        DB::table('safehouses')->where('slug', 'cardboard-box')->update(['storage_slots' => 3]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropIndex(['storage_expires_at']);
            $table->dropColumn('storage_expires_at');
            $table->dropConstrainedForeignId('safehouse_id');
        });

        Schema::table('safehouses', function (Blueprint $table): void {
            $table->dropColumn('storage_slots');
            $table->renameColumn('meal_fullness', 'eat_fullness');
            $table->renameColumn('meal_fatigue', 'eat_fatigue');
        });
    }
};
