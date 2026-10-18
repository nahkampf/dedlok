<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A collapse waits for a safe moment. Reaching the maximum only flags it; the next request (or the minute sweep)
 * collapses the character, so it never fires from inside a travel loop, a run turn or a fight round. FATIGUE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->boolean('collapse_pending')->default(false)->after('current_fatigue')
                ->comment('Fatigue reached its maximum and the collapse has not happened yet. Set by App\\Characters\\Fatigue, cleared by App\\Characters\\Collapse, read on every request by EnsureCharacterIsStanding.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('collapse_pending');
        });
    }
};
