<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Deck Jockey's starting creds go from 2000 to 3500. Only characters made afterwards are paid it
 * (`LoadoutGranter` runs at creation and nowhere else).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('archetypes')->where('slug', 'deckjockey')->where('starting_creds', 2000)->update(['starting_creds' => 3500]);
    }

    public function down(): void
    {
        DB::table('archetypes')->where('slug', 'deckjockey')->where('starting_creds', 3500)->update(['starting_creds' => 2000]);
    }
};
