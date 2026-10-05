<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ammunition gets a KIND: hollow point, armour piercing and so on. A label from a fixed
 * vocabulary (`App\Combat\AmmoType`); what a round does stays its own numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ammunition', function (Blueprint $table): void {
            $table->string('type', 24)->default('standard')->after('calibre')
                ->comment('An App\Combat\AmmoType case: what kind of round this is. A label only — what it does is damage, penetration and damage_profile.');
        });
    }

    public function down(): void
    {
        Schema::table('ammunition', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
