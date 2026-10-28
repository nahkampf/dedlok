<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program can belong to a family (a Compressor, say), so a skill can sharpen the whole group through the stat
 * `family.<name>` without naming its members. No item is seeded: the Kompressor is authored in the item editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->string('family', 30)->nullable()->comment('The group of programs a skill can sharpen together (config/stats.php, stat family.<name>): a running program\'s modifiers are scaled by that stat. NULL means none.');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('family');
        });
    }
};
