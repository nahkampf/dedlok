<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md): a tree run is not given a purpose at the door; every action is offered inside, and the
 * run takes the purpose of what it does. A Flood can start late, so the node's patience counts from the first one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedInteger('flood_turn')->nullable()->after('pressure')->comment('A tree run: the turn of its first Flood, from which the node\'s patience (cyberspace.denial.turns) counts. NULL before any, and on a run that chose Denial at the door.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('flood_turn'));
    }
};
