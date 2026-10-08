<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every running Decryptor works one file, so several files break at once.
 *
 * `worker_id` is the running decryptor (the inventory row, which stays the same row for as long as it runs) that is
 * working this entry; NULL while nothing is. An entry's `cycle_started_at` is non-null exactly while its worker has
 * run on it continuously since then. Two Ghostkeys running are two workers and two entries in progress; one decryptor
 * never works two files, and one file is never worked by two decryptors. The foreign key nulls on delete, so a
 * decryptor that is destroyed frees its file (the hook in `ProgramRunner` has already closed the cycle).
 *
 * The queue is also reorderable now, so position is a priority, not "the one being worked on".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decryption_queue', function (Blueprint $table): void {
            $table->foreignId('worker_id')->nullable()->after('position')
                ->comment('The running Decryptor (an inventory row) working this entry; NULL while none is. Nulls if that row is deleted.')
                ->constrained('inventory_items')->nullOnDelete();
        });

        // Ghostkey may be run more than once: two copies are two hands, if the character can keep that many going
        // (`program_threads`, one until a skill raises it).
        DB::table('programs')
            ->whereIn('item_id', DB::table('items')->where('slug', 'ghostkey')->select('id'))
            ->update(['multithreaded' => true]);
    }

    public function down(): void
    {
        Schema::table('decryption_queue', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('worker_id');
        });
    }
};
