<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this account has been shown (and closed) the invitation to the Discord.
 *
 * On the ACCOUNT, like the patch notes, and written when the modal is CLOSED. Everybody who already has an account is
 * marked as having seen it, so the invitation is for new players only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('seen_discord_at')->nullable()->after('last_seen_changelog')
                ->comment('When this account closed the Discord invitation shown on first sign-in. NULL = not shown yet, and it opens by itself. Accounts that existed before the invitation were set to the migration time.');
        });

        DB::table('users')->update(['seen_discord_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('seen_discord_at');
        });
    }
};
