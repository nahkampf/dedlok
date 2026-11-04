<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many explore passes a character has spent in each district: the familiarity a safehouse will ask for (docs/FATIGUE.md).
 * Counted from the day this exists; nothing is back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_district_passes', function (Blueprint $table): void {
            $table->comment('Explore passes a character has finished in a district. Written only by App\\City\\Explorer when a pass matures; counts passes, not discoveries.');

            $table->foreignId('character_id')
                ->comment('Whose passes these are.')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')
                ->comment('The district they were spent in.')
                ->constrained()->cascadeOnDelete();
            $table->unsignedInteger('passes')->default(0)
                ->comment('Finished explore passes there, from the day this table was made. Never read by a check yet.');

            $table->primary(['character_id', 'district_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_district_passes');
    }
};
