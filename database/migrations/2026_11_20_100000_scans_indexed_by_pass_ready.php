<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every settle asks "which of this character's reads have matured" (`character_id`, `pass_ready_at` <= now). With only the
 * character and address indexes it walked all of their scan rows: 5,000 of them for a long-time scanner, ten times the cost
 * of a newer player's. An index only, no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->index(['character_id', 'pass_ready_at'], 'idx_system_scans_ready');
        });
    }

    public function down(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropIndex('idx_system_scans_ready');
        });
    }
};
