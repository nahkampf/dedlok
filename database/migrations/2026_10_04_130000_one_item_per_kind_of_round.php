<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One item per kind of round: a (calibre, type) pair names exactly one ammunition item.
 *
 * Ammunition stacks per ITEM, so two items for one kind would be two piles that never merge and two
 * magazines' worth of rounds a player cannot combine. `ItemRequest` explains it; this is the guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ammunition', function (Blueprint $table): void {
            $table->unique(['calibre', 'type'], 'ammunition_one_item_per_kind');
        });
    }

    public function down(): void
    {
        Schema::table('ammunition', function (Blueprint $table): void {
            $table->dropUnique('ammunition_one_item_per_kind');
        });
    }
};
