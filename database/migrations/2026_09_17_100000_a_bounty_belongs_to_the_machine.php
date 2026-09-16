<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether losing on THIS machine gets a price put on you.
 *
 * ON THE SYSTEM AND NOT ON THE ICE, which is the whole point: the same piece of
 * ICE guarding two machines is two different propositions, because one of them
 * belongs to somebody who answers and the other does not. A player who has met
 * an ICE XII and walked away has learned what it hits for — not whether the
 * next one will have them followed home.
 *
 * Rolled once when the ICE is assigned, at odds climbing with the piece's level
 * — a tenth at ICE I, two-thirds at ICE XII. It is the only part of a defence
 * that is not a property of the guard.
 *
 * A boolean rather than a chance stored per row: the roll happens at
 * instantiation like every other defence, and what is stored is the answer. A
 * stored chance would be a die nobody had thrown yet, re-thrown on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->boolean('ice_bounty')->default(false)->after('ice_id')
                ->comment('Does losing to this machine\'s ICE put a price on you? Rolled per MACHINE when its ICE is assigned, not carried by the ICE — the same guard on two systems is two different risks. False when there is no ICE at all.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->dropColumn('ice_bounty');
        });
    }
};
