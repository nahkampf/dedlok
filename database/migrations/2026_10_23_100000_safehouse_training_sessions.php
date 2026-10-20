<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Safehouse Work out, Study and Simstim: two progress meters and a daily simstim allowance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('workout_progress', 8, 3)->default(0)->comment('Work out meter, 0 to 100. Grows while working out in a safehouse; at 100 a Meat, Motor or Muscle point is gained and it resets to 0. Written only by HomeActions.');
            $table->decimal('study_progress', 8, 3)->default(0)->comment('Study meter, 0 to 100. Grows while studying in a safehouse; at 100 an Intellect, Memory or Focus point is gained and it resets to 0. Written only by HomeActions.');
            $table->unsignedInteger('simstim_seconds')->default(0)->comment('Seconds of simstim spent on simstim_day. Only meaningful for that day: a different day reads as zero.');
            $table->date('simstim_day')->nullable()->comment('The (UTC) day simstim_seconds counts. NULL: never done.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', fn (Blueprint $t) => $t->dropColumn(['workout_progress', 'study_progress', 'simstim_seconds', 'simstim_day']));
    }
};
