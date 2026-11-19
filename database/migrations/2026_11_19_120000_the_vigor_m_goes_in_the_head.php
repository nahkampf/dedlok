<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Kalashtec Vigor-M had no `implant_region`, so New Flesh Studio never offered it: a clinic fits an
 * implant by region. It is a head implant (its slug says so). A data fix by slug that touches the row only
 * while the region is still empty, so a region set by hand in the admin since is left alone.
 */
return new class extends Migration
{
    private const SLUG = 'placeholder_implant_head_focus';

    public function up(): void
    {
        DB::table('items')->where('slug', self::SLUG)->whereNull('implant_region')->update(['implant_region' => 'head']);
    }

    public function down(): void
    {
        DB::table('items')->where('slug', self::SLUG)->where('implant_region', 'head')->update(['implant_region' => null]);
    }
};
