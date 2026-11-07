<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who made an encounter's picture. Both are cleared when the picture is removed, since they credit that file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->string('image_credit', 255)->nullable()->comment('Who to credit for the encounter picture. Cleared with the picture. NULL is none.');
            $table->string('image_link', 255)->nullable()->comment('Link to the artist of the encounter picture (http or https). Cleared with the picture. NULL is none.');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropColumn(['image_credit', 'image_link']);
        });
    }
};
