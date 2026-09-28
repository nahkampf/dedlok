<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboard storage becomes a property of the deck.
 *
 * It was one config figure for every deck, which made the cheapest Kurogane
 * and a THO Nightjar hold exactly as much. Now a deck carries its own, and
 * NULL falls back to `decks.onboard_storage` — so a deck authored tomorrow
 * without a figure still holds something.
 *
 * The figures are written by slug, and a database without these decks (a
 * fresh one, the suite) is an ordinary no-op.
 */
return new class extends Migration
{
    private const FIGURES = [
        'kurogane_explorer' => 8,
        'kurogane_scout' => 10,
        'veritas_nc2' => 10,
        'kurogane_wayfarer' => 12,
        'veritas_nc3' => 12,
        'aegis_kader' => 16,
        'tsukuyomi_monolith' => 32,
        'paladin_citadel' => 32,
        'dunkelwerk_sturmlicht' => 64,
        'hosaka_daimyo' => 64,
        'tho_nightjar' => 64,
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('onboard_storage')->nullable()->after('board_layout')
                ->comment('QB a cyberdeck holds before any storage module: the base of deck_storage. NULL on a deck falls back to decks.onboard_storage; NULL on everything else, since only a deck holds data.');
        });

        foreach (self::FIGURES as $slug => $qb) {
            DB::table('items')->where('slug', $slug)->where('hardware', 'deck')->update(['onboard_storage' => $qb]);
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('onboard_storage');
        });
    }
};
