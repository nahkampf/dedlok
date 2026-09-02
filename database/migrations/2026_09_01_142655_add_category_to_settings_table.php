<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grouping label for settings.
 *
 * Nullable and free-text: it exists to make settings groupable later, and
 * constraining it now would just guess at groups that do not exist yet.
 * Both NULL and '' mean ungrouped — see Setting::inCategory().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Indexed because grouping is the column's entire purpose. Not
            // load-bearing while reads come from cache, but it costs almost
            // nothing on a table this size and saves a later migration.
            $table->string('category', 255)->nullable()->after('value')->index();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
