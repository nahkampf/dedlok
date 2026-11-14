<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Run trees, phase 3 (docs/RUN_TREE.md): traps. What a run knows of them, a trap's snare, and a scan's third tree fact. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->json('traps')->nullable()->after('loopback')->comment('Trap nodes of the tree this runner found or dealt with, as {node: found|spent|cut}: found is named on the map, spent (tripped or disarmed) is a conduit, cut (Node Disconnect) is gone with its edges. Read across the party. NULL is none.');
            $table->unsignedInteger('snared_until')->nullable()->after('traps')->comment('An Ensnare trap holds this runner: moves are refused while runs.turn is below this. NULL is not snared.');
        });

        Schema::table('system_scans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('tree')->nullable()->comment('How far this scan read the run tree: 0 nothing, 1 the Layout, 2 the Nodes, 3 the Traps. NULL is no tree to read (the flag off, a system that does not walk one, or a file made before trees).')->change();
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn(['traps', 'snared_until']));
    }
};
