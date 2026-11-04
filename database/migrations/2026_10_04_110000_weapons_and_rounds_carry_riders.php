<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riders: what a hit leaves behind that lasts more than the hit (stun, burning, poison).
 * docs/COMBAT.md. Authored now; NOTHING READS THEM until the fight engine exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weapons', function (Blueprint $table): void {
            $table->json('riders')->nullable()->after('damage_profile')
                ->comment('Riders this weapon puts on a hit: a JSON list of {kind, chance, turns, amount}, at most one per App\Combat\RiderKind. NULL is none. Authored only; nothing reads it until the fight engine exists.');
        });

        Schema::table('ammunition', function (Blueprint $table): void {
            $table->json('riders')->nullable()->after('damage_profile')
                ->comment('Riders this round ADDS to the weapon\'s while loaded (an incendiary round adding burning); it does not replace them, unlike damage_profile. Same shape as weapons.riders. NULL is none. Authored only; nothing reads it until the fight engine exists.');
        });
    }

    public function down(): void
    {
        Schema::table('ammunition', fn (Blueprint $table) => $table->dropColumn('riders'));
        Schema::table('weapons', fn (Blueprint $table) => $table->dropColumn('riders'));
    }
};
