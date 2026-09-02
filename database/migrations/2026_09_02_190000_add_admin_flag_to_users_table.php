<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may edit the game's content.
 *
 * A column rather than a config allowlist, because being an admin is a fact
 * about an ACCOUNT and the account already has a row. An allowlist keyed by
 * email would also survive the email changing, which is precisely the wrong
 * way round.
 *
 * It is deliberately absent from User's #[Fillable], so no request can grant
 * it however the payload is shaped. `php artisan admin:grant` is the only
 * thing that writes it, and it writes it with forceFill.
 *
 * Not indexed: the flag is read for the current user by primary key on the
 * requests that need it, and an index on a column that is false for almost
 * every row would never be chosen anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
