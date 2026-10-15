<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->timestamp('pull_at')->nullable()->comment('A raid on a Data Fortress is pulling files by itself: the moment of the last 5-second tick. NULL when not pulling.');
            $table->unsignedBigInteger('pull_content_id')->nullable()->comment('The vault file currently being pulled (system_contents.id, plain). NULL between files.');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $t) => $t->dropColumn(['pull_at', 'pull_content_id']));
    }
};
