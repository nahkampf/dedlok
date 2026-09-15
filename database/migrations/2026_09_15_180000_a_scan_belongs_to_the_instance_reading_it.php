<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A read belongs to the copy of the scanner doing it.
 *
 * Scanning became a thing that takes minutes, and now it becomes a thing that
 * runs while you are somewhere else — you point an instrument at a machine and
 * walk away, or jack out entirely, and it is still reading when you come back.
 * With two instruments running, "is a scanner going?" stops being enough of a
 * question: the one that matters is whether THE INSTRUMENT THAT STARTED THIS
 * READ is still on it.
 *
 * `inventory_items.id` is that instrument. A running program is a row of its
 * own — that is what `running_on_deck_id` splitting a copy off a pile is for —
 * so a pointer at one names a particular instance rather than a kind of
 * software.
 *
 * NULL ON DELETE, and the delete is not hypothetical: stopping a stackable
 * program MERGES its row back into the pile it came from, which deletes the
 * row. So a scan whose instrument was stopped loses its pointer by the same
 * act, and the read is void without anything having to notice separately.
 * Stopping an unstackable one clears the running columns instead, which the
 * probe checks for as well — two shapes of the same event, both covered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->foreignId('reading_process_id')
                ->nullable()
                ->after('pass_ready_at')
                ->comment('The running copy of the scanner doing this read, as an inventory row. NULL when no pass is running, or when the instrument was stopped and its row merged back into the pile.')
                ->constrained('inventory_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropForeign(['reading_process_id']);
            $table->dropColumn('reading_process_id');
        });
    }
};
