<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message may carry a list under its sentence.
 *
 * The nightly siphon report is one message per character with a line per
 * system drained, and a line has an address a player wants to copy. A single
 * 500-character sentence could not hold that, and an address inside a string
 * cannot become a copy button.
 *
 * SNAPSHOTTED LIKE THE BODY. The lines are written once, as true that night,
 * and never re-read from `systems`: a drained machine can relocate or burn by
 * morning, and the report must still say what it paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->json('details')
                ->nullable()
                ->after('body')
                ->comment('Structured lines under the sentence, as a JSON list, written once with the body and never recomposed. NULL on an ordinary one-sentence message. The shape belongs to the sender: the siphon report writes {system, address, grade, creds}.');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('details');
        });
    }
};
