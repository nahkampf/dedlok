<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * A message the game writes can be longer than a player's 500 (the Joint Signals Command's notice is 515). The column
 * widens to 1000; what a player may SEND is still capped at 500 by the form and by `Notifier::sendFrom()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('body', 1000)->comment('The sentence, composed once at send time and never recomposed. Up to 1000 for the game\'s own; a player\'s own words are held to 500.')->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('body', 500)->comment('The sentence, composed once at send time and never recomposed.')->change();
        });
    }
};
