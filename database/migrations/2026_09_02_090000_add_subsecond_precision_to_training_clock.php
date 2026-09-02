<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the training clock microsecond precision.
 *
 * The deposit advances last_deposited_at by exactly the time the points it
 * awarded cost. At whole-second precision that time has to be rounded, and the
 * rounding is not free: at 118.32 points a minute a deposit pays for 59.84
 * seconds and the clock moves a full 60, so a third of a point evaporates every
 * run. The scheduler runs 1,440 times a day, so a character training steadily
 * lost ~460 points a day against one who was offline and caught up in a single
 * pass — downtime paid better than uptime.
 *
 * Six digits reduces that residue to under a microsecond per deposit, which is
 * ~0.000002 points. See App\Skills\TrainingDeposit.
 *
 * MySQL only: SQLite has no date types and stores whatever string Eloquent
 * hands it, so the precision comes from the model's $dateFormat there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('training_queue', function (Blueprint $table) {
            $table->timestamp('last_deposited_at', 6)->nullable()->change();

            // The model writes every date through the same $dateFormat, so
            // these have to accept the fractional part too or MySQL rounds
            // them and a row's updated_at can land a second in the future.
            $table->timestamp('created_at', 6)->nullable()->change();
            $table->timestamp('updated_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('training_queue', function (Blueprint $table) {
            $table->timestamp('last_deposited_at')->nullable()->change();
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });
    }
};
