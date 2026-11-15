<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md), step 6: the scan facts Layout and Nodes, and a run starting with what the scan knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('tree')->nullable()->after('cloak_broken')->comment('How much of the system\'s run tree (App\\Cyberspace\\Tree\\RunTree) this scan has read: 0 nothing yet, 1 the Layout, 2 the Nodes as well. NULL, unlike the other facts, is NOT "unread" but "no tree to read": the system did not walk a tree when the file was made, which is also what every file made before trees existed says.');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('mapped')->default(false)->after('known')->comment('On a tree run, the runner\'s scan had read the Layout when the run started, so the whole tree shows, its nodes as "?" until known. Read across the party.');
        });
    }

    public function down(): void
    {
        Schema::table('system_scans', fn (Blueprint $t) => $t->dropColumn('tree'));
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('mapped'));
    }
};
