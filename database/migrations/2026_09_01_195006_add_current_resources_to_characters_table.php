<?php

use App\Models\Character;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two resources: stored, mutable state bounded by a derived maximum.
 *
 * hitpoints_max and synaptic_integrity_max are computed from attributes and
 * never stored. These are their stored counterparts, and they are the only
 * numbers on this table that are both mutable and saved.
 *
 * smallInteger rather than tinyInteger: with attributes at the lifetime cap of
 * 100, hitpoints_max reaches 200 before any modifier, which a tinyint could
 * not hold.
 *
 * Nullable on purpose — null means "not yet initialised", and the model fills
 * it with the resolved maximum on create. That keeps "a brand new character"
 * distinguishable from "a character on 0 hit points", which is dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_hitpoints')->nullable()->after('archetype');
            $table->unsignedSmallInteger('current_synaptic_integrity')->nullable()->after('current_hitpoints');
        });

        // Existing characters start at full. Resolved per row rather than with
        // a single UPDATE, because the maximum depends on that character's
        // attributes and archetype.
        Character::query()->whereNull('current_hitpoints')->each(function (Character $character): void {
            DB::table('characters')->where('id', $character->id)->update([
                'current_hitpoints' => (int) $character->stat('hitpoints_max')->value,
                'current_synaptic_integrity' => (int) $character->stat('synaptic_integrity_max')->value,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['current_hitpoints', 'current_synaptic_integrity']);
        });
    }
};
