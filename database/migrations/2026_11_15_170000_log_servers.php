<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Run trees, phase 3 (docs/RUN_TREE.md): log servers. A wipe erases an alert that already went off. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->boolean('log_wiped')->default(false)->after('snared_until')->comment('This runner wiped a log server after the alert went off: the alert costs no standing and no lockdown after the run. Read across the party. An alert after the wipe still counts.');
            $table->decimal('alert_cost', 8, 3)->nullable()->after('log_wiped')->comment('The standing this runner lost when their action set off the alert, as it landed; refunded by a log wipe. NULL is none, or refunded.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn(['log_wiped', 'alert_cost']));
    }
};
