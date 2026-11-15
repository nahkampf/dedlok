<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Run trees (docs/RUN_TREE.md): Deep Nmap, the Analyst's. A flag on an Nmapper. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->boolean('nmap_deep')->default(false)->after('nmap_reach')->comment('A deep Nmapper: its Nmap reaches one edge further, finds traps and honeypots in reach, and names whether the sentries it sees are Fast or carry the Cordon.');
        });
    }

    public function down(): void
    {
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('nmap_deep'));
    }
};
