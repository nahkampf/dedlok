<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a machine first appeared to a player, as distinct from when they last
 * looked at it.
 *
 * `seen_at` moves every time somebody sweeps a position; this does not. The two
 * diverge the moment you look twice, and the gap between them is what lets the
 * map say *this one was not here last time* — which is the only way a player
 * who walks the same stretch often can notice anything has changed in it.
 *
 * IT RESTARTS WHEN THE MACHINE CHANGES, not when the sighting does. A
 * relocated system comes back wearing a new address, so "same position, same
 * address" is the whole test: same machine, keep the clock; different address,
 * a stranger has moved in and the clock starts again. Nothing has to watch for
 * relocation, which is the payoff for addresses not surviving one.
 *
 * Backfilled from `seen_at`, which is the truthful answer for sightings taken
 * before this column existed: the only moment anybody recorded. It makes every
 * existing contact as old as its last look, so nothing is spuriously new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->timestamp('first_seen_at')->nullable()->after('seen_at')
                ->comment('When this machine first appeared to this character. Unlike seen_at it does not move on a re-sighting, and it restarts when a different address appears at the position. NULL only on sightings taken before the column existed.');
        });

        DB::table('system_contacts')->whereNull('first_seen_at')->update([
            'first_seen_at' => DB::raw('seen_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('system_contacts', function (Blueprint $table): void {
            $table->dropColumn('first_seen_at');
        });
    }
};
