<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('mood', 8, 2)->default(0)->after('current_fullness')
                ->comment('Mood, -100 to +100, 0 neutral. Moved by events and drifts back to 0 by the minute clock. Written only by Mood; its bands grant effects.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('mood');
        });
    }
};
