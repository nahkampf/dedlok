<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LocationDescriptionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_07_180000_two_locations_get_their_descriptions.php');
    }

    public function test_the_two_descriptions_are_carried_over(): void
    {
        $this->assertSame('generic harbor location', DB::table('locations')->where('slug', 'pier-nine-warehouse')->value('description'));
        $this->assertSame('generic location', DB::table('locations')->where('slug', 'server-farm-annex')->value('description'));
    }

    public function test_a_description_somebody_has_since_written_is_left_alone_and_down_restores_only_ours(): void
    {
        DB::table('locations')->where('slug', 'pier-nine-warehouse')->update(['description' => 'Written in the live admin.']);
        $this->migration()->up();
        $this->assertSame('Written in the live admin.', DB::table('locations')->where('slug', 'pier-nine-warehouse')->value('description'));

        $this->migration()->down();
        $this->assertSame('Written in the live admin.', DB::table('locations')->where('slug', 'pier-nine-warehouse')->value('description'));
        $this->assertSame('Rows of humming racks nobody seems to maintain.', DB::table('locations')->where('slug', 'server-farm-annex')->value('description'));
    }
}
