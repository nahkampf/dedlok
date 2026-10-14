<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The Tasks & Missions rail item counts what is new since the screen was last opened. This is that moment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('tasks_seen_at')->nullable()->comment('When the Tasks & Missions screen was last opened. Journal lines and accepted jobs newer than this are the rail badge\'s count; NULL counts everything. Written only by App\\Journal\\Journal::markSeen().');
        });

        // What already exists is not news.
        DB::table('characters')->update(['tasks_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('tasks_seen_at');
        });
    }
};
