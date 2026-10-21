<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts: a market offer with one named buyer. Nobody else sees it and nobody else can buy it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_offers', function (Blueprint $table): void {
            $table->foreignId('recipient_character_id')
                ->nullable()
                ->after('seller_character_id')
                ->comment('NULL for an ordinary listing, everyone sees it. Set for a contract: only this character (and the seller) sees the offer and only this character can buy it. Cascades, because a contract for a deleted character must not turn into a public listing.')
                ->constrained('characters')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('market_offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipient_character_id');
        });
    }
};
