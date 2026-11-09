<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An opponent may escape from nearer the meeting point (or only from farther out) than the fight's default. NULL is the default, which is every opponent that exists. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npcs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('flee_beyond')->nullable()->after('flees_at')
                ->comment('How many steps out from the meeting point it must stand before it can Disengage; it may from MORE than this. NULL is combat.distance.flee_beyond.');
        });
    }

    public function down(): void
    {
        Schema::table('npcs', function (Blueprint $table): void {
            $table->dropColumn('flee_beyond');
        });
    }
};
