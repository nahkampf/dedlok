<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The background programs a hunter's arrival stopped to make room for the icebreaker, so the hunt's end (a win, a
 * defeat, an egress or a collapse) can start them again. Item ids and counts, not inventory row ids, as on `characters.suspended_programs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hunts', function (Blueprint $table): void {
            $table->json('stopped_programs')->nullable()->comment('Background programs the hunter\'s arrival stopped, as {item id: copies}; the hunt\'s end starts them again and clears it. NULL is none.');
        });
    }

    public function down(): void
    {
        Schema::table('hunts', function (Blueprint $table): void {
            $table->dropColumn('stopped_programs');
        });
    }
};
