<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SUPPORTERS: an account flag that earns a badge, an unlimited training queue and a place on the Supporters page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_supporter')->default(false)->after('is_admin')
                ->comment('A supporter of the game: a badge by the character\'s name, an unlimited training queue, a place on the Supporters page. Outside User\'s fillable; App\Supporters\Supporters is the only writer.');
            $table->timestamp('supporter_since')->nullable()->after('is_supporter')
                ->comment('When the flag was last set, which orders the Supporters page. NULL while not a supporter.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_supporter', 'supporter_since']);
        });
    }
};
