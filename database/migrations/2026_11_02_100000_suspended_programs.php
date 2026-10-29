<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The background programs a runner switched off at the briefing to make room for a run, so the run's end can start them
 * again. Item ids and counts, not inventory row ids: a stopped copy merges into its pile and the row it had is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->json('suspended_programs')->nullable()->comment('Background programs stopped to make room for the run now under way, as {item id: copies}; the run\'s end starts them again and clears it. NULL is none.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('suspended_programs');
        });
    }
};
