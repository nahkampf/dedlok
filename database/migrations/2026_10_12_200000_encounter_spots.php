<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The spot door: an encounter pinned to one SYSTEM and offered as a button in the grid screen's Interact box.
 *
 * - `encounters.spot_system_id` is the pinned system's id, a plain column. The editor is told an address and resolves
 *   it, so a spot FOLLOWS its machine when the machine relocates (a new address and position, the same row). A system
 *   that is burned leaves the spot dormant rather than deleted: it is authored content, and the id simply finds
 *   nothing. A foreign key would delete the encounter with the machine.
 * - `encounters.cooldown_hours` is how long a character waits to press it again. NULL is no cooldown.
 * - `encounter_cooldowns` remembers, per character and encounter, when it last opened. A flag has no expiry, and the
 *   row of an open encounter is deleted when it closes, so neither can say "within the last six hours".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->unsignedBigInteger('spot_system_id')->nullable()->after('run_purpose')
                ->comment('For the spot door: the system it is offered at. Not a foreign key: it follows the machine when it relocates, and goes dormant when it is burned.');
            $table->unsignedSmallInteger('cooldown_hours')->nullable()->after('spot_system_id')
                ->comment('For the spot door: hours a character waits before they may open it again. NULL is no cooldown.');
        });

        Schema::create('encounter_cooldowns', function (Blueprint $table): void {
            $table->comment('When a character last opened a spot encounter, for its cooldown. One row per character and encounter, rewritten each time.');

            $table->id()->comment('Primary key.');
            $table->foreignId('character_id')->comment('Who. Cascades.')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->comment('Which. Cascades: a deleted encounter has no cooldown to keep.')->constrained()->cascadeOnDelete();
            $table->timestamp('last_opened_at')->comment('The last time it opened for them.');

            $table->unique(['character_id', 'encounter_id']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
            DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place','grid_step','system','run','spot'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::table('encounters')->where('starts_on', 'spot')->delete();
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_starts_on');
            DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_starts_on CHECK (starts_on IN ('travel','explore','place','grid_step','system','run'))");
        }

        Schema::dropIfExists('encounter_cooldowns');
        Schema::table('encounters', fn (Blueprint $t) => $t->dropColumn(['spot_system_id', 'cooldown_hours']));
    }
};
