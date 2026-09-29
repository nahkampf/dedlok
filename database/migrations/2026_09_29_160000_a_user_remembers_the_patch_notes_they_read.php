<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The newest release whose patch notes this account has closed.
 *
 * On the ACCOUNT, like achievements and the inbox, because it is the person
 * who has read something rather than their character. Written when the modal
 * is CLOSED, never when it renders — a note cleared by arriving is a note
 * cleared for somebody who never read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_seen_changelog', 20)->nullable()->after('is_admin')
                ->comment('Newest release version (X.Y.Z) whose patch notes this account has closed. NULL is ordinary: never closed one, and shown only the latest release.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_changelog');
        });
    }
};
