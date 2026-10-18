<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('raid_protected_until')->nullable()->after('rent_due_at')
                ->comment('Until when nobody can break into this character\'s safehouse again, set by a successful raid. NULL: not protected. Written only by Raider.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('raid_protected_until');
        });
    }
};
