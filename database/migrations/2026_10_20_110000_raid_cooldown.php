<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('raid_cooldown_until')->nullable()->after('rent_due_at')
                ->comment('Until when nobody can rob this character\'s safehouse again, set by a successful rob. NULL: not cooling down. Written only by Leads.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('raid_cooldown_until');
        });
    }
};
