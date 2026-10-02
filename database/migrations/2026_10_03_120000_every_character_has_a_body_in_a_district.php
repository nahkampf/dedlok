<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the body is. Added nullable, backfilled with the starting district,
 * and then made NOT NULL — in that order because the table is populated.
 *
 * RESTRICT on delete, never cascade: deleting a district must not take the
 * characters standing in it away. On SQLite (the tests) the NOT NULL step is
 * skipped, since changing a column there rebuilds the table; the model's
 * creating hook is what guarantees the value either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->foreignId('district_id')
                ->nullable()
                ->after('archetype')
                ->comment('The district the character\'s body is in. Always set: being jacked in is the separate grid position. Written by App\\City in phase 2; new characters get config(city.start).')
                ->constrained('districts')
                ->restrictOnDelete();
        });

        $start = DB::table('districts')->where('slug', 'old-town')->value('id');
        DB::table('characters')->whereNull('district_id')->update(['district_id' => $start]);

        if (DB::getDriverName() === 'mysql') {
            Schema::table('characters', function (Blueprint $table): void {
                $table->unsignedBigInteger('district_id')->nullable(false)->comment('The district the character\'s body is in. Always set: being jacked in is the separate grid position. Written by App\\City in phase 2; new characters get config(city.start).')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('district_id');
        });
    }
};
