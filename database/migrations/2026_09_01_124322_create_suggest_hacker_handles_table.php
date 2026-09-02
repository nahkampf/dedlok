<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table predates Laravel and already exists on the remote host, so the
 * create is guarded — this migration is a no-op there and simply records
 * itself. Its job is to make the schema reproducible for tests and for any
 * fresh environment, which would otherwise have no way to build the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggest_hacker_handles')) {
            return;
        }

        Schema::create('suggest_hacker_handles', function (Blueprint $table) {
            $table->string('handle', 100)->primary();
            $table->boolean('in_use')->default(false)->index();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: this table holds authored content that did
        // not come from a migration, and a rollback must not destroy it.
    }
};
