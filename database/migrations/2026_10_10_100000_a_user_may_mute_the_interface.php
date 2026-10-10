<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whether this account wants the interface's sounds. On the account, so it follows the player between devices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sound_enabled')->default(true)->after('credit_name')
                ->comment('Whether the interface plays its sounds (the inbox chime). TRUE is ordinary; the topbar speaker toggles it. Nothing server-side reads it except the page that draws the toggle.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sound_enabled');
        });
    }
};
