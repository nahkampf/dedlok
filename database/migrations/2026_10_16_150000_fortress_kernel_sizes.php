<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table): void {
            $table->unsignedInteger('kernel_integrity_max')->nullable()->comment('A Data Fortress\'s Kernel maximum System Integrity, resolved from the Kernel that founded it and kept here. NULL is the config base (cyberspace.fortress.kernel_integrity).');
            $table->unsignedInteger('kernel_resistance')->nullable()->comment('Flat points taken off every point of burn damage by the Kernel that founded this fortress. NULL is the config base (cyberspace.fortress.kernel_resistance).');
            $table->unsignedInteger('kernel_demand_percent')->nullable()->comment('What the Kernel\'s own compute, RAM and storage demand is scaled to, per cent, from the Kernel that founded this fortress. NULL is 100.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['kernel_integrity_max', 'kernel_resistance', 'kernel_demand_percent']));
    }
};
