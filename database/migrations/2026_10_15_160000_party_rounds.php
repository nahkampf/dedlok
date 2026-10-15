<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->string('pending_action', 12)->nullable()->comment('A party runner\'s chosen action for the round in progress, waiting for the others. NULL when none chosen or solo.');
            $table->unsignedInteger('ice_damage')->default(0)->comment('Integrity this runner has taken off the ICE so far; the ICE targets the one who has hurt it most.');
        });

        Schema::table('run_parties', function (Blueprint $table): void {
            $table->timestamp('round_started_at')->nullable()->comment('When the current round opened. A round resolves when everybody has chosen or 30 seconds have passed.');
        });
    }

    public function down(): void
    {
        Schema::table('run_parties', fn (Blueprint $t) => $t->dropColumn('round_started_at'));
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn(['pending_action', 'ice_damage']));
    }
};
