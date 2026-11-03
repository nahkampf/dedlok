<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A joined member the launch could not start stays in the party, marked, so they cannot go and start a solo run of
 * their own beside it: the row goes with the party when its last run ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_party_members', function (Blueprint $table): void {
            $table->timestamp('dropped_at')->nullable()->comment('When the launch left this member out because they could not start. They stay until the party ends and start no run of their own meanwhile. NULL is in.');
        });
    }

    public function down(): void
    {
        Schema::table('run_party_members', function (Blueprint $table): void {
            $table->dropColumn('dropped_at');
        });
    }
};
