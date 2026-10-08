<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The decryption queue: the files a character has set to be broken, in order, one at a time.
 *
 * The training queue's shape (an ordered list with a clock on the head), because the problem is the same; the
 * difference is that a cycle ends in a roll, not a deposit. `cycle_started_at` is NOT NULL exactly while a
 * decryptor has been running on the equipped deck since that moment, and `App\Decryption\DecryptionQueue` is the
 * only writer. Both foreign keys cascade: a file sold, destroyed or lost takes its entry with it, so the queue
 * never needs to notice a vanished file (it still checks the owner, since a sale can change it).
 *
 * `inventory_items.extracted_file` also stops describing only the Encrypted Data Dump: the Memory Dump a
 * successful decryption makes carries the same JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decryption_queue', function (Blueprint $table): void {
            $table->comment('Files a character has set to be decrypted, in order. Position 1 is the one being worked on. Written only by App\\Decryption\\DecryptionQueue.');
            $table->id()->comment('Surrogate key.');
            $table->foreignId('character_id')->comment('Whose queue. Cascades with the character.')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->comment('The file row to decrypt. Cascades: a destroyed or consumed file takes its entry with it.')->constrained('inventory_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->comment('1 is being worked on. Unique per character.');
            $table->timestamp('cycle_started_at')->nullable()->comment('When the current cycle began; NULL while no decryptor is running. Only the head entry carries one.');
            $table->timestamp('created_at')->nullable()->comment('Laravel bookkeeping: when the row was created.');
            $table->timestamp('updated_at')->nullable()->comment('Laravel bookkeeping: when the row was last changed.');

            $table->unique(['character_id', 'position']);
            $table->unique('inventory_item_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_items MODIFY extracted_file JSON NULL COMMENT 'Non-null only on a file row (an Encrypted Data Dump, or the Memory Dump it becomes when decrypted): label, size in QB, encryption skill id, the two values and the grade of the system it came off. JSON because App\\\\Cyberspace\\\\ExtractedFile is its only author and reader.'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('decryption_queue');
    }
};
