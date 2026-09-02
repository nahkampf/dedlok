<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The training queue: an ordered list of (skill, target level) pairs.
 *
 * This replaces the queue_position column on character_skills, which could not
 * express a real queue. That column allowed one position per character+skill,
 * so "Hacking I, Hacking II, Hacking III" was unrepresentable, and so was any
 * queue that interleaves skills:
 *
 *     1. Hacking I      4. Cryptography IV
 *     2. Hacking II     5. Cryptography V
 *     3. Hacking III    6. Codebreaking I
 *
 * character_skills keeps the PROGRESS (one row per skill, forever). This table
 * holds the INTENT (many rows per skill, one per level).
 *
 * The training clock lives on the queue entry rather than the skill, because it
 * belongs to the training session: when an entry completes it is deleted and
 * the next inherits the clock at the exact completion moment, so no accrual
 * time is lost in the handover.
 */
return new class extends Migration
{
    private const MAX_LEVEL = 5;

    public function up(): void
    {
        Schema::create('training_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('target_level');

            // 1 is what is training now. Unique per character, so ordering is
            // guaranteed by the index rather than by code.
            $table->unsignedTinyInteger('position');

            $table->timestamp('last_deposited_at')->nullable();
            $table->decimal('sp_per_minute', 8, 2)->nullable();

            $table->timestamps();

            $table->unique(['character_id', 'position']);
            // The same level of the same skill cannot be queued twice.
            $table->unique(['character_id', 'skill_id', 'target_level']);
        });

        Schema::table('character_skills', function (Blueprint $table) {
            $table->dropUnique(['character_id', 'queue_position']);
            $table->dropColumn(['queue_position', 'last_deposited_at', 'sp_per_minute']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $max = self::MAX_LEVEL;

        DB::statement("ALTER TABLE `training_queue` ADD CONSTRAINT `chk_queue_target_level` CHECK (`target_level` BETWEEN 1 AND {$max})");
        DB::statement('ALTER TABLE `training_queue` ADD CONSTRAINT `chk_queue_position` CHECK (`position` >= 1)');
    }

    public function down(): void
    {
        Schema::table('character_skills', function (Blueprint $table) {
            $table->unsignedTinyInteger('queue_position')->nullable()->after('attained_level');
            $table->timestamp('last_deposited_at')->nullable()->after('queue_position');
            $table->decimal('sp_per_minute', 8, 2)->nullable()->after('last_deposited_at');
            $table->unique(['character_id', 'queue_position']);
        });

        Schema::dropIfExists('training_queue');
    }
};
