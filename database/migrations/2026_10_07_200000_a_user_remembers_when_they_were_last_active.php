<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an account last did something. Written by App\Http\Middleware\TouchLastActive, at most once every few minutes,
 * and never by the inbox's own polling (an open tab nobody is looking at is not somebody playing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('credit_name')
                ->comment('The last time this account made a request of its own (not the inbox poll), to within a few minutes. NULL is ordinary: not seen since this column existed. Read only by the front page\'s count of active players.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
