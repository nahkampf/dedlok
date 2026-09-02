<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flat key/value store for game settings.
 *
 * `key` is the primary key rather than an auto-increment id: a settings row is
 * identified by its key, and a surrogate id would allow two rows to claim the
 * same one. This matches suggest_hacker_handles, which is keyed on its handle
 * for the same reason.
 *
 * Note that KEY is a reserved word in MySQL. Laravel's query builder and
 * Eloquent quote identifiers automatically, so ordinary code is fine, but any
 * hand-written SQL must backtick it: SELECT `key` FROM settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            // varchar(255) as a primary key is 1020 bytes under utf8mb4, well
            // inside InnoDB's 3072-byte index limit.
            $table->string('key', 255)->primary();

            // Nullable so "set but empty" and "no value" stay distinguishable.
            $table->string('value', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
