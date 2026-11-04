<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How much a dose keeps you awake, which decides whether you can rest (docs/FATIGUE.md, *Resting*). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drugs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('wakefulness')->default(0)->after('lethal_at')
                ->comment('How much one dose in effect keeps them from resting. The doses in effect add up; at config safehouse.rest.wakefulness_limit they cannot rest, unless an opiate or medical dose is also in effect. 0 is ordinary and means none.');
        });
    }

    public function down(): void
    {
        Schema::table('drugs', function (Blueprint $table): void {
            $table->dropColumn('wakefulness');
        });
    }
};
