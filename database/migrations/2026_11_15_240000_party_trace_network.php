<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md): a party on a tree run comes in over ONE routing chain, the network of a runner the owner
 * picks at the door, and shares one trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_parties', function (Blueprint $table): void {
            $table->unsignedBigInteger('chain_character_id')->nullable()->after('owner_loadout')->comment('A tree run: whose routing chain (trace network) every runner of the party comes in over, the owner or a joined member; the owner picks it at the door. Set to the owner when the party opens, back to the owner when the picked member leaves. NULL is no network.');
        });
    }

    public function down(): void
    {
        Schema::table('run_parties', fn (Blueprint $t) => $t->dropColumn('chain_character_id'));
    }
};
