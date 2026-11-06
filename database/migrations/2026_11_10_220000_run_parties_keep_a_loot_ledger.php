<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_parties', function (Blueprint $table): void {
            $table->json('loot')->nullable()->after('share_extraction')
                ->comment('What the party took off the system and who it went to, in order: a list of {character_id, kind (creds, file, item), label, quantity}. Read when the last run ends, for the "Run concluded" message.');
        });
    }

    public function down(): void
    {
        Schema::table('run_parties', function (Blueprint $table): void {
            $table->dropColumn('loot');
        });
    }
};
