<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Script is a program that is spent by running: it lives for `lifetime_seconds` from `started_at` and is then gone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedInteger('lifetime_seconds')->nullable()->after('storage_size')
                ->comment('How long a running copy lasts, from started_at, before it is spent. NULL is an ordinary program that runs until stopped. A copy that is stopped early is destroyed, not put back.');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('lifetime_seconds');
        });
    }
};
