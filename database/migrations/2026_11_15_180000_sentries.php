<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Run trees, phase 3 (docs/RUN_TREE.md): sentries, ICE posted in the tree that hunts runners once awake. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->json('sentries')->nullable()->after('alert_cost')->comment('The tree\'s sentries as this run sees them, kept the same on every live run of a party: a list of {post, node, ice, integrity, awake, fast, target (a run id)}. NULL on a run that is not a tree run, or a tree with none.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('sentries'));
    }
};
