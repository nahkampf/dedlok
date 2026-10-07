<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an alpha tester wants to be called in the credits (the thank-you page). Asked at registration while the
 * `alpha_testing` flag is on, and optional. On the ACCOUNT, like the beta key it came in with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('credit_name', 100)->nullable()->after('seen_discord_at')
                ->comment('What this person asked to be called in the credits (the thank-you page), typed at registration while alpha testing is on. NULL is ordinary: not asked, or left blank. Nothing in the game reads it.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credit_name');
        });
    }
};
