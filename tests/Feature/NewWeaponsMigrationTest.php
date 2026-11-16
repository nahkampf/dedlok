<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The weapons made in development reach a fresh database through a migration, and running it again changes nothing. */
final class NewWeaponsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_items_arrive_with_their_rows_and_run_twice_adds_nothing(): void
    {
        $count = fn (): array => [DB::table('items')->count(), DB::table('weapons')->count(), DB::table('ammunition')->count(), DB::table('shop_items')->count(), DB::table('armour')->count()];
        $this->assertSame(10, DB::table('items')->whereIn('slug', ['katana', 'lk_canister', 'liquid_knuckles', 'switchblade', 'tebbit_knife', '22', 'zipgun', 'kalashtec-udar9', 'rds22p', 'kuroda_silkframe'])->count());
        $this->assertSame(1, DB::table('armour')->where('item_id', DB::table('items')->where('slug', 'kuroda_silkframe')->value('id'))->count());
        $this->assertSame('blade', DB::table('weapons')->where('item_id', DB::table('items')->where('slug', 'tebbit_knife')->value('id'))->value('class'));

        $before = $count();
        (require database_path('migrations/2026_11_16_130000_the_katana_the_knives_the_zip_gun_and_their_ammunition.php'))->up();

        $this->assertSame($before, $count());
    }
}
