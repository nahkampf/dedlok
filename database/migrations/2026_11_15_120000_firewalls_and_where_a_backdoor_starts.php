<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md), step 3: which firewalls a run has opened, and where a party member holding a backdoor
 * chose to start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->json('opened')->nullable()->after('known')->comment('Firewall nodes of the tree this runner opened by landing the OS roll, as a list. Open for the rest of the run and for the whole party (the union of its runs). NULL on a run that is not a tree run, or before any.');
        });

        Schema::table('run_party_members', function (Blueprint $table): void {
            $table->boolean('start_at_entry')->default(false)->comment('A member holding a backdoor on a tree run\'s system starts at the entry rather than the core (the default). Chosen beside their loadout; unread for anybody without a backdoor there.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('opened'));
        Schema::table('run_party_members', fn (Blueprint $t) => $t->dropColumn('start_at_entry'));
    }
};
