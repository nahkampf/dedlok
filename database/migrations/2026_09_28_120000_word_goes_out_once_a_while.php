<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bounty roll remembers when it last fired.
 *
 * At high heat the roll is certain, so without a memory it said the word was
 * out every night — honest about a manhunt, and an inbox of the same sentence.
 * The fix is a memory on the CHARACTER rather than a gentler curve: the curve
 * says how likely somebody is to decide you are worth money, and a gentler one
 * would be lying about that to keep the inbox quiet.
 *
 * A column rather than a look through `notifications`, because an inbox is the
 * player's to clear, and clearing it must not reset the world's opinion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('bounty_word_at')
                ->nullable()
                ->after('heat')
                ->comment('When word last went out that somebody would pay for this character. NULL is never. Read by App\Characters\Bounties, which skips the nightly roll while it is inside cyberspace.heat.bounty.cooldown_days.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('bounty_word_at');
        });
    }
};
