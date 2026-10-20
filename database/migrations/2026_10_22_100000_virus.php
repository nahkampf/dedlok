<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Virus ICE tag (CYBERSPACE.md, *Virus*): one infection at a time, kept on the character. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->decimal('virus_damage', 8, 2)->nullable()->comment('Synaptic Integrity the infection takes on its first tick, tapering to nothing by its last. NULL means not infected. Written only by App\\Cyberspace\\Virus.');
            $table->unsignedSmallInteger('virus_turns')->nullable()->comment('The infection\'s base lifetime in ticks (a run turn inside a run, a minute outside). The virus_lifetime stat starts from this, so an Antiviral shortens it. NULL when not infected.');
            $table->unsignedSmallInteger('virus_elapsed')->default(0)->comment('Ticks the infection has already run. Meaningless while virus_damage is NULL.');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['virus_damage', 'virus_turns', 'virus_elapsed']);
        });
    }
};
