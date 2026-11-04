<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fullness, and what a meal at a safehouse is worth (docs/FATIGUE.md, *Food and fullness*). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('current_fullness', 8, 2)->default(0)->after('current_fatigue')
                ->comment('How full they are, 0 to config fatigue.fullness.max, to two decimals. Starts empty and drains by the minute. Not shown; it only limits how much they can eat. Written only by App\\Characters\\Fullness.');
        });

        Schema::table('safehouses', function (Blueprint $table): void {
            $table->decimal('eat_fullness', 6, 2)->default(0)->after('rest_rate')
                ->comment('Fullness a meal here adds. Read by the Eat action.');
            $table->decimal('eat_fatigue', 6, 2)->default(0)->after('eat_fullness')
                ->comment('Fatigue a meal here removes. Read by the Eat action.');
        });
    }

    public function down(): void
    {
        Schema::table('safehouses', function (Blueprint $table): void {
            $table->dropColumn(['eat_fullness', 'eat_fatigue']);
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('current_fullness');
        });
    }
};
