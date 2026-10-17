<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The three fortress skills are content found rather than bought. An imprint's `on_market` follows its skill's on every
| save, so both are set here.
*/
return new class extends Migration
{
    private const SLUGS = ['node_administration', 'leecher_crafting', 'kernel_crafting'];

    public function up(): void
    {
        $this->set(false);
    }

    public function down(): void
    {
        $this->set(true);
    }

    private function set(bool $onMarket): void
    {
        $ids = DB::table('skills')->whereIn('slug', self::SLUGS)->pluck('id');

        DB::table('skills')->whereIn('id', $ids)->update(['on_market' => $onMarket]);
        DB::table('items')->whereIn('use_skill_id', $ids)->update(['on_market' => $onMarket]);
    }
};
