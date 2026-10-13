<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hit points and Synaptic Integrity are carried to two decimal places, so a 3% resistance on a hit of 5 costs 4.85
 * and a regeneration of half a point an hour is real. Whole numbers fit unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('current_hitpoints', 8, 2)->nullable()
                ->comment('Hit points now, to two decimals. NULL until the first save sets it to the maximum; never above hitpoints_max.')->change();
            $table->decimal('current_synaptic_integrity', 8, 2)->nullable()
                ->comment('Synaptic Integrity now, to two decimals. NULL until the first save sets it to the maximum; never above synaptic_integrity_max.')->change();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('current_hitpoints')->nullable()->change();
            $table->unsignedSmallInteger('current_synaptic_integrity')->nullable()->change();
        });
    }
};
