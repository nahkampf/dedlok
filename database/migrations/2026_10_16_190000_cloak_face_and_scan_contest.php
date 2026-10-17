<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedTinyInteger('cloak_ice_level')->nullable()->comment('The ICE level a running Cloak makes scans and runs report for this fortress. 0 is none. NULL is unset: the real ICE shows through. Display only.');
            $table->unsignedSmallInteger('cloak_ids_detection')->nullable()->comment('The IDS detection a running Cloak makes scans report. 0 is none. NULL is unset: the real figure shows through. Display only.');
            $table->unsignedSmallInteger('cloak_tracer_detection')->nullable()->comment('The tracer detection a running Cloak makes scans report. 0 is none. NULL is unset. Display only.');
            $table->unsignedSmallInteger('cloak_tracer_rate')->nullable()->comment('The tracer rate a running Cloak makes scans report. NULL is unset. Display only.');
            $table->unsignedBigInteger('cloak_os_skill_id')->nullable()->comment('The operating system (a skill) a running Cloak makes scans report. NULL is unset: the real one shows through. Display only; no foreign key, a deleted skill simply reads as unset.');
        });

        Schema::table('system_scans', function (Blueprint $table): void {
            $table->boolean('cloak_broken')->nullable()->comment('Whether this read overcame a Cloak: NULL is not tested (no cloak, or not yet rolled), 0 the disguise held and every fact above is the false face, 1 it was seen through and the facts are real.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['cloak_ice_level', 'cloak_ids_detection', 'cloak_tracer_detection', 'cloak_tracer_rate', 'cloak_os_skill_id']));
        Schema::table('system_scans', fn (Blueprint $t) => $t->dropColumn('cloak_broken'));
    }
};
