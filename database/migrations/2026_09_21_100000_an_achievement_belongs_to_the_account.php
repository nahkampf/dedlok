<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achievements, and the counters underneath them.
 *
 * THEY BELONG TO THE ACCOUNT, NOT THE CHARACTER. A character is a run of play;
 * an achievement is a thing the person did, and it has to survive whatever
 * happens to the character who did it. Every writer holds a Character and
 * reaches through `characters.user_id`.
 *
 * THE CATALOGUE IS CONFIG, SO THERE IS NO THIRD TABLE. An achievement is a
 * metric and a number, both of which only mean anything next to code that
 * already exists — there is nothing for an editor to author and nothing that
 * varies per environment. What IS durable is the id, which is why
 * `achievement_id` is an ordinary integer with no foreign key behind it and
 * why `public/images/achievements/<id>.jpg` can be found by convention like
 * every other picture in the game.
 *
 * TWO TABLES, BECAUSE A METRIC IS NOT AN ACHIEVEMENT. What is counted — creds
 * raided, ICE broken — outlives any one threshold hung off it: a second
 * achievement at a thousand ICE has to read the same running total rather than
 * start its own from zero the day it is added. So the counter is the durable
 * record and the unlock is a row that says a line was crossed.
 *
 * NEITHER TABLE IS DERIVABLE. Nothing in the schema records what a raid paid,
 * what a siphon paid, or that a system ever existed once it is burned, so
 * these counters are the only place those facts are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_progress', function (Blueprint $table): void {
            $table->comment('One running total per account per metric. Absent and zero are the same answer.');
            $table->id()->comment('Primary key.');
            $table->foreignId('user_id')
                ->comment('Whose total this is. Achievements attach to the account, never the character.')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('metric', 40)->comment('An App\Achievements\Metric case. Permanent: unlock definitions name it.');
            $table->unsignedBigInteger('value')->default(0)->comment('The total. Summed for a counter, high-water for a state like the longest routing chain.');

            $table->unique(['user_id', 'metric']);
        });

        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->comment('One row per achievement an account has unlocked. A missing row is locked; there is no other state.');
            $table->id()->comment('Primary key.');
            $table->foreignId('user_id')
                ->comment('Who unlocked it.')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('achievement_id')->comment('Which achievement, by the id config/achievements.php keys it under. Permanent once handed out; no foreign key, because the catalogue is config rather than a table.');
            $table->timestamp('unlocked_at')->comment('When the line was crossed. The row IS the unlock, so this never changes.');

            $table->unique(['user_id', 'achievement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievement_progress');
    }
};
