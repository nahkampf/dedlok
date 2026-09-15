<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a character has found out about a machine, and for how long it is worth
 * knowing.
 *
 * A scan is the answer to the question a grade poses: the difference between a
 * system you can take and one that ends your character is a number you are
 * meant to find out BEFORE you approach. This is where that number lands once
 * somebody has gone and looked.
 *
 * A SIGHTING, LIKE A CONTACT, AND FOR THE SAME REASON. No foreign key to
 * `systems`: a scan has to outlive the machine it describes, because a
 * relocated or burned system is exactly the case where knowing what it used to
 * run is worth something and misleading. The address and the position are
 * copied, and `App\Models\SystemContact` already established that shape.
 *
 * ONE ROW PER CHARACTER PER ADDRESS. Scanning again does not start a second
 * scan; it adds to the one that is there, which is what makes a partial scan a
 * thing you can come back to and finish — see `passes`.
 *
 * WHAT IS KNOWN IS COLUMNS, NOT A BLOB. `grade` and `os_skill_id` are nullable
 * and null means *not found out*, which is the state this table exists to
 * represent. A JSON bag of discovered facts would have been shorter to write
 * and impossible to query: "which of my scans know a grade" is the question a
 * market listing and a run plan both ask.
 *
 * PERISHABLE AND CONSUMED, both of which are on the row rather than in code.
 * Scan data expires 48 hours after it is taken — a machine that has been
 * relocated, patched or raided since is described by a file that no longer
 * matches it — and it is spent the first time it is used. `used_at` is that
 * moment; nothing uses one yet, because using one means a run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_scans', function (Blueprint $table): void {
            $table->comment('What a character has found out about a machine on the grid, and when that stops being worth knowing.');

            $table->id()->comment('Surrogate key. Nothing outside this table references it.');

            $table->foreignId('character_id')
                ->comment('Whose scan this is. A scan is knowledge, and knowledge belongs to somebody.')
                ->constrained()
                ->cascadeOnDelete();

            $table->char('address', 8)->comment('The machine as it identified itself when scanned. NOT a foreign key: a scan outlives the system, and an address that stops resolving is an ordinary outcome.');

            $table->unsignedSmallInteger('x')->comment('Where it was standing when scanned. Kept so a scan can be shown on a map without resolving the address.');
            $table->unsignedSmallInteger('y')->comment('The other half of the position. See x.');

            $table->unsignedTinyInteger('grade')->nullable()->comment('How hard it is, 1-9, or NULL for not found out yet. The number a scan exists to discover.');

            $table->foreignId('os_skill_id')
                ->nullable()
                ->comment('The operating system found running on it, as the skill that gates it. NULL is not found out yet.')
                ->constrained('skills')
                ->nullOnDelete();

            $table->unsignedTinyInteger('passes')->default(0)->comment('How many times this machine has been probed for this scan. Capped in config: a picture that has not arrived by then does not arrive.');

            $table->timestamp('scanned_at')->comment('When the first pass was made. The clock the expiry is measured from.');
            $table->timestamp('expires_at')->comment('When this stops being worth anything. Written at creation from config rather than computed on read, so a row can be swept by anything without knowing the rule.');
            $table->timestamp('used_at')->nullable()->comment('When it was spent. A scan is consumed the first time it is used; NULL is unspent. Nothing spends one yet.');

            // One scan per character per machine: scanning again adds to what
            // is there rather than starting a second picture of the same thing.
            $table->unique(['character_id', 'address'], 'uq_system_scans_character_address');

            // "What have I scanned that is still good", which is the listing
            // every screen and the market will want.
            $table->index(['character_id', 'expires_at'], 'idx_system_scans_live');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        /*
         * A scan that knows something must have run at least one pass.
         *
         * IT NAMES `grade` AND NOT `os_skill_id`, and that is MySQL's rule
         * rather than a choice: error 3823 refuses a column carrying a
         * referential action inside a CHECK, and the operating system is a
         * foreign key that nulls when the skill goes. The programs migration
         * hit the same wall from the other side and resolved it by giving up
         * the referential action; here the key is worth more than the second
         * half of the check, because a scan pointing at a skill that no longer
         * exists would be a dangling id nothing could resolve.
         *
         * So the guard covers the grade, the model covers the rest, and this
         * is the second time the same rule has cost an afternoon.
         */
        DB::statement(
            'ALTER TABLE `system_scans` ADD CONSTRAINT `chk_system_scans_passes` CHECK ('
            .'`passes` > 0 OR `grade` IS NULL)'
        );

        DB::statement(
            'ALTER TABLE `system_scans` ADD CONSTRAINT `chk_system_scans_grade` CHECK ('
            .'`grade` IS NULL OR (`grade` BETWEEN 1 AND 9))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('system_scans');
    }
};
