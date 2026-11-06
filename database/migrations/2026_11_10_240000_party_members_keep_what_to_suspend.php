<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's "stop these to make room" ticks, kept with their loadout. They were lost on Accept, so a member whose deck
 * was busy (a decryptor, a Surveyor) was dropped at launch for "not enough compute" with no way to say what to stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_party_members', function (Blueprint $table): void {
            $table->json('suspend')->nullable()->comment('Running inventory row ids the member ticked to stop when the run starts, to make room for their loadout. NULL is none.');
        });
    }

    public function down(): void
    {
        Schema::table('run_party_members', function (Blueprint $table): void {
            $table->dropColumn('suspend');
        });
    }
};
