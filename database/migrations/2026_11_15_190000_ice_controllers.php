<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Run trees, phase 3 (docs/RUN_TREE.md): the ICE controller beside the core, posting sentries after the alert. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->json('controller')->nullable()->after('sentries')->comment('The tree\'s ICE controller as this run sees it, kept the same on every live run of a party: {ticks (sentry turns since the alert), spawned (sentries posted, up to 3), down (shut down)}. NULL on a tree with none, or before the alert.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('controller'));
    }
};
