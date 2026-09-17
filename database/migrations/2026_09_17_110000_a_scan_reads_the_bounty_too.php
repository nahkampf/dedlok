<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a scan found out about the price on losing here.
 *
 * FOLDED INTO THE ICE FACT rather than given one of its own, so it is learned
 * in the same roll and at the same instant: knowing there is an ICE XII and
 * knowing the owner answers for it are one assessment, and a file that had the
 * guard without the reprisal would be describing half a decision.
 *
 * That is also why this column is NOT nullable, unlike every other answer on
 * this table. The question "has this been read" is already carried by
 * `ice_level` — null there means the fact was never found out — so a second
 * nullable column would be a second way to ask one question, free to disagree
 * with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->boolean('ice_bounty')->default(false)->after('ice_level')
                ->comment('Did the scan find that losing to this machine puts a price on you? Read with the ICE and in the same roll, so `ice_level` being null is what says neither is known — this is never the thing that answers "was it read".');
        });
    }

    public function down(): void
    {
        Schema::table('system_scans', function (Blueprint $table): void {
            $table->dropColumn('ice_bounty');
        });
    }
};
