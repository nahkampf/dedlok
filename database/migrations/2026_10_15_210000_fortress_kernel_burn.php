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
            $table->unsignedInteger('kernel_integrity')->nullable()->comment('A Data Fortress Kernel\'s System Integrity as damaged by burns; NULL is full (cyberspace.fortress.kernel_integrity). Burns carry between runs.');
            $table->timestamp('frozen_until')->nullable()->comment('A burned Data Fortress\'s Kernel is Frozen until this moment: nobody can raid it, and the owner can save it by getting the Kernel running again. Past it the fortress is deleted. NULL is not frozen.');
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn(['kernel_integrity', 'frozen_until']));
    }
};
