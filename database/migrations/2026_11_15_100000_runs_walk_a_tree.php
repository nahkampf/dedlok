<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md), step 2: where a runner stands in the system's tree, what they have revealed of it, the
 * noise bar and the alert. All of it is NULL or false on a run that is not a tree run, which is every run while the
 * intrusion_runs_v2 flag is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('node')->nullable()->comment('The node of the system\'s run tree (App\\Cyberspace\\Tree\\RunTree) this runner stands on. NULL means the run is not a tree run: set once when it starts, so turning the flag never changes a run under way.');
            $table->json('known')->nullable()->comment('Node ids of the tree whose kind this runner has revealed (stood on or Nmapped), as a list. A party sees the union of its runs\' lists. NULL on a run that is not a tree run.');
            $table->unsignedSmallInteger('pending_node')->nullable()->comment('Where a party runner\'s chosen Move goes, held with pending_action until the round resolves. NULL otherwise.');
            $table->decimal('noise', 6, 1)->unsigned()->nullable()->comment('The noise bar, in points against the system\'s ids_detection; full is the alert. One value for a whole party, kept the same on every run. NULL on a run that is not a tree run.');
            $table->boolean('alerted')->default(false)->comment('The alert has gone off on a tree run: the ICE woke, the owner minds, and the system locks down after the run. Shared by a party. detected alone means only this runner\'s trace is running.');
            $table->timestamp('acted_at')->nullable()->comment('When this runner last took or chose a turn, for jacking out an inactive tree runner (5 minutes alone in a party, 10 solo). NULL until the first.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn(['node', 'known', 'pending_node', 'noise', 'alerted', 'acted_at']));
    }
};
