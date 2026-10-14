<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A Pathfinder walks the grid at its own pace; the one that exists is brought to 8 seconds a move (the default is 10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('traverse_seconds')->nullable()->comment('Pathfinders only: seconds per move of Autotraverse. NULL is the default (ten). Read by App\\Cyberspace\\Finder::stepSeconds().');
        });

        DB::table('programs')->where('kind', 'pathfinder')->update(['traverse_seconds' => 8]);
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('traverse_seconds');
        });
    }
};
