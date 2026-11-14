<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md), step 5: loot in data vaults, and which node a run's pull is at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_contents', function (Blueprint $table): void {
            $table->unsignedSmallInteger('node')->nullable()->after('system_id')->comment('The data vault of the system\'s run tree (App\\Cyberspace\\Tree\\RunTree) holding this row. NULL is the core: everything that is not in a vault, which is all of it on a system that does not walk a tree.');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pull_node')->nullable()->after('extracting_qb')->comment('On a tree run, the data vault the paused or running pull (extracting_qb) is at; NULL is the core. Extracting at any other node drops the pull and starts one there.');
        });
    }

    public function down(): void
    {
        Schema::table('system_contents', fn (Blueprint $t) => $t->dropColumn('node'));
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('pull_node'));
    }
};
