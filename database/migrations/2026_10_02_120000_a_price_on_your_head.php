<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bounty is a sum carried on the character, and the grade that earned it.
 *
 * `bounty` accumulates: every night word goes out adds to it. `bounty_grade` is
 * the memory the sum is priced from — the highest grade of system that cost
 * the character heat since the last time word went out — so a bounty rises
 * with what caused it as well as with heat in general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedBigInteger('bounty')
                ->default(0)
                ->after('bounty_word_at')
                ->comment('Creds somebody will pay for this character. Accumulates each time word goes out (App\Characters\Bounties); nothing collects or clears it yet. 0 is none.');
            $table->unsignedTinyInteger('bounty_grade')
                ->default(0)
                ->after('bounty')
                ->comment('Highest system grade that cost this character heat since word last went out. Prices the next bounty increase, then resets to 0. 0 is none.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['bounty', 'bounty_grade']);
        });
    }
};
