<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An encounter may set its own chance, overriding the door's default.
 *
 * NULL is the door's way (a flat roll for the door, then a weighted draw among what is eligible). A number is the
 * odds, in percent, that THIS encounter opens at one opening of its door (a move, an arrival, a turn, a matured pass),
 * rolled on its own and still adjusted by the resolved `encounter_chance` stat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->decimal('chance', 5, 2)->nullable()->after('weight')
                ->comment('Percent chance THIS encounter opens at each opening of its door, rolled on its own. NULL is the door\'s default and the weighted draw. Not for place or spot, which are buttons.');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE encounters ADD CONSTRAINT chk_encounters_chance CHECK (chance IS NULL OR (chance > 0 AND chance <= 100))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE encounters DROP CHECK chk_encounters_chance');
        }

        Schema::table('encounters', fn (Blueprint $t) => $t->dropColumn('chance'));
    }
};
