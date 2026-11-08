<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A book remembers having been over the withdrawal line, so getting clean afterwards can be told from never having been hooked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_drug_use', function (Blueprint $table): void {
            $table->boolean('was_hooked')->default(false)->after('addiction')->comment('Whether addiction has been at or over the withdrawal threshold since it last read zero. Set by a dose, cleared when addiction reaches zero (the "Kickin\' the habit" achievement).');
        });
    }

    public function down(): void
    {
        Schema::table('character_drug_use', function (Blueprint $table): void {
            $table->dropColumn('was_hooked');
        });
    }
};
