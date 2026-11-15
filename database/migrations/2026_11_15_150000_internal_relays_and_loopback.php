<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees, phase 2 (docs/RUN_TREE.md): internal relays and the Loopback script that stalls the trace on one. A program
 * column makes a Loopback, a run column holds the one running, and the skill it rolls with arrives with placeholder text
 * the user rewrites in the admin (off the market until then). `runs.opened` now also holds a subverted security hub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('loopback_strength')->nullable()->after('nmap_reach')->comment('A Loopback (a Script): its strength in the hold roll each turn on an internal relay, plus the Loopback skill level, against the egress difficulty of the grade. NULL is not a Loopback.');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('loopback')->nullable()->after('opened')->comment('The running inventory row of the Loopback this runner holds on the internal relay they stand on: the party\'s traces stall while it holds. Spent when it fails a hold roll, the runner moves off, or the run ends. NULL is none.');
            $table->json('opened')->nullable()->comment('Firewall nodes this runner breached and a security hub they subverted, as a list. Open (or subverted) for the rest of the run and the whole party (the union of its runs). NULL on a run that is not a tree run, or before any.')->change();
        });

        DB::table('skills')->insertOrIgnore([
            'slug' => 'loopback_routing',
            'title' => 'Loopback Routing',
            'description' => 'A trace follows the traffic back to its source. A Loopback script on an internal relay folds that traffic back on itself, so the trace keeps chasing a circle while you work. Each level helps the script hold one more turn.',
            'category' => 'Networking',
            'rank' => 8,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'focus',
            'base_cost' => 5000,
            'on_market' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('skills')->where('slug', 'loopback_routing')->delete();
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('loopback'));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('loopback_strength'));
    }
};
