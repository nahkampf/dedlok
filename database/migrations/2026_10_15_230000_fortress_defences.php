<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->json('defence')->nullable()->comment('A crafted fortress ICE or IDS\'s own numbers (attack, integrity, detection, last maintained), per copy; they came out of its maker\'s skills. NULL for everything else.');
        });

        Schema::table('systems', function (Blueprint $table): void {
            $table->json('fortress_ice')->nullable()->comment('What a Data Fortress\'s running ICE is right now ({attack, integrity}), synced by the fortress settle; NULL when none runs. ice_id then points at the template ICE row.');
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ice_attack')->nullable()->comment('The attack of the ICE in play when it is a fortress\'s own crafted one; NULL uses the catalogue row\'s attack.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn('ice_attack'));
        Schema::table('systems', fn (Blueprint $t) => $t->dropColumn('fortress_ice'));
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropColumn('defence'));
    }
};
