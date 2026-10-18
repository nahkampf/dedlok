<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatigue, the third resource. It runs the other way from hit points and Synaptic Integrity: everybody starts at
 * nothing, actions raise it and a clock lowers it. FATIGUE.md is the design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('current_fatigue', 8, 2)->default(0)
                ->comment('Fatigue now, to two decimals. 0 is rested; reaching fatigue_max is a collapse. Written only by App\\Characters\\Fatigue; clamped to [0, fatigue_max].');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('current_fatigue');
        });
    }
};
