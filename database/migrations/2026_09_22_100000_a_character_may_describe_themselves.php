<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for a player to say who they are.
 *
 * THE FIRST THING ON A CHARACTER THAT THE GAME NEVER READS. Every other column
 * on the row is a number something resolves, a pointer something follows or a
 * clock something settles; this is the player's own text and nothing mechanical
 * will ever look at it.
 *
 * MEDIUMTEXT rather than TEXT, for one reason: the form allows 65,000
 * CHARACTERS and TEXT holds 65,535 BYTES. A bio in Latin fits either; one in
 * Japanese is three bytes a character and would be truncated on the way in by
 * a column that looked big enough. The extra ceiling costs nothing.
 *
 * Nullable, and null is ordinary: most characters will never write one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->mediumText('bio')->nullable()->after('handle')
                ->comment('What the player wrote about themselves. Theirs, never the game\'s: nothing resolves it, reads it or derives anything from it. NULL is ordinary. MEDIUMTEXT because the form allows 65,000 characters and TEXT holds 65,535 bytes.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('bio');
        });
    }
};
