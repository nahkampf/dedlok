<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `Category::Street` was named for one sentence in one message and is the City
 * now. The enum's backed value is stored on every row, so the rows move with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->where('category', 'street')->update(['category' => 'city']);
    }

    public function down(): void
    {
        DB::table('notifications')->where('category', 'city')->update(['category' => 'street']);
    }
};
