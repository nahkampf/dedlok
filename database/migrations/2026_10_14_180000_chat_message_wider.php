<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A chat line may be 2000 characters, so a pasted piece of ASCII art fits (it was 500). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->string('message', 2000)->comment('Plain text as stored (HTML already stripped; up to 2000, newlines kept); markdown is interpreted when drawn, never here.')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->string('message', 500)->comment('Plain text as stored (HTML already stripped); markdown is interpreted when drawn, never here.')->change();
        });
    }
};
