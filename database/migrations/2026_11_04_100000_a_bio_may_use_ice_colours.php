<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the ANSI art in a bio is drawn with iCE colours.
 *
 * The flag lives in the SAUCE record at the end of an ANSI file, which is cut off when the file is attached to the bio, so
 * the one bit that matters is kept here. Set by the "Add ANSI file" upload, cleared when the bio is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->boolean('bio_ice')->default(false)->after('bio')
                ->comment('The bio\'s ANSI art is drawn with iCE colours (blink is a bright background, nothing flashes). Read from the SAUCE record of the last ANSI file attached to it; false when the bio is cleared.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('bio_ice');
        });
    }
};
