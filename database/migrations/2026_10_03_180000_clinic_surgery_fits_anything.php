<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A clinic fits any implant; how risky it is lies in its rating alone. The
 * highest-tier limit and the regions it would work on are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('clinic_surgery_regions');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE clinic_surgeries DROP CONSTRAINT chk_clinic_surgeries_tier');
        }

        Schema::table('clinic_surgeries', function (Blueprint $table): void {
            $table->dropColumn('max_tier');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_surgeries', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_tier')->default(1)->after('rating')->comment('The highest implant tier it will touch: 1 or 2.');
        });

        Schema::create('clinic_surgery_regions', function (Blueprint $table): void {
            $table->comment('Which body regions a clinic will operate on.');
            $table->foreignId('location_id')->comment('The clinic.')->constrained()->cascadeOnDelete();
            $table->string('region', 10)->comment('App\\Implants\\BodyRegion value.');
            $table->primary(['location_id', 'region']);
        });
    }
};
