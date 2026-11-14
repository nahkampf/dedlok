<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run trees (docs/RUN_TREE.md): the Egress Tunnel, a Script that makes the node its runner holds an exit. The skill that
 * starts one arrives with placeholder text the user rewrites in the admin (off the market until then).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('tunnel_strength')->nullable()->after('loopback_strength')->comment('An Egress Tunnel (a Script): taken off the egress difficulty at its node, which is the grade below\'s, as at a maintenance port. NULL is not an Egress Tunnel.');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('tunnel')->nullable()->after('loopback')->comment('The running inventory row of the Egress Tunnel this runner holds on the node they stand on: anybody in the party may egress there, and a Cordon cannot cut it. Spent when they move off or the run ends. NULL is none.');
        });

        DB::table('skills')->insertOrIgnore([
            'slug' => 'egress_tunneling',
            'title' => 'Egress Tunneling',
            'description' => 'Placeholder: opening an Egress Tunnel inside a system. Level 1 is needed to start one.',
            'category' => 'Networking',
            'rank' => 2,
            'primary_attribute' => 'intellect',
            'secondary_attribute' => 'focus',
            'base_cost' => 3000,
            'on_market' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('skills')->where('slug', 'egress_tunneling')->delete();
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('tunnel'));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('tunnel_strength'));
    }
};
