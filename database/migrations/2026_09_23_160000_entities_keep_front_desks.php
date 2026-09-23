<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An Exchange: the one kind of system nothing can be done TO.
 *
 * A system with an `exchanges` row is an Exchange, the shape `programs` and
 * `deck_modules` already have against `items` — nothing on the `systems` row
 * says which beyond its type, and the row is what carries the numbers.
 *
 * THE GRADE HERE IS NOT `systems.grade`. That column is 1-9, means difficulty,
 * and is what scans read, what the loot tables key off and what the operating
 * system ladder follows. An Exchange's grade is 1-5 and means what is on offer
 * at this desk. Putting the second meaning in the first column would have
 * every existing reader of grade quietly answering questions about a shopfront.
 *
 * The agent's grade IS the desk's grade — one number, so which agent and which
 * shelf can never disagree — which is why there is no second column for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table): void {
            $table->comment('An entity\'s front desk on the grid: missions and a loyalty store. One row per system of type exchange; the system row carries where it is and who owns it.');

            $table->id()->comment('Primary key.');

            $table->foreignId('system_id')
                ->comment('Which system is this desk. Unique: a system is one desk or none.')
                ->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('grade')
                ->comment('What is on offer here, 1-5. NOT systems.grade, which is 1-9 difficulty: this is the tier of the work and the shelf, and it is the agent\'s grade too.');

            $table->string('agent', 64)
                ->comment('Who sits at it. A person rather than a department, because a mission is handed over by somebody; tied to this desk and no other.');

            $table->decimal('min_standing', 6, 3)->default(0)
                ->comment('What the agent wants to think of you before they will deal at all: missions and the store read the same gate. Never negative — nobody trades with somebody they are in the red with.');

            $table->timestamps();

            $table->unique('system_id', 'uq_exchanges_system');
            $table->index(['grade'], 'idx_exchanges_grade');
        });

        // Both halves said out loud, because neither is expressible as a
        // column type: a grade outside 1-5 is a desk nothing can staff, and a
        // negative gate is a desk that will only serve people it dislikes.
        //
        // MySQL only, like every other CHECK in this schema: SQLite cannot add
        // one to a table that exists, and the test database is SQLite.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `exchanges` ADD CONSTRAINT `chk_exchanges_grade` CHECK (`grade` BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE `exchanges` ADD CONSTRAINT `chk_exchanges_min_standing` CHECK (`min_standing` >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
