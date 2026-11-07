<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An encounter may hang under another, only so the editor's list can show what belongs together. The game never reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('slug')->comment('The encounter this one is listed under in the editor. Display only; the game never reads it. NULL is a top-level one.')
                ->constrained('encounters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
