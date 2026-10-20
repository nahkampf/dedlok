<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Grid;
use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use App\Cyberspace\Terrain;
use App\Models\Character;
use App\Models\System;
use App\Models\SystemContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A position is (grid, x, y): a second grid holds its own systems, contacts and terrain at the same coordinates, and the main
 * grid cannot see them. Every row elsewhere in the suite is on grid 1, so this is the only test that can tell a missing filter.
 */
final class GridsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 8);
        DB::table('grids')->insert(['id' => 2, 'slug' => 'mil', 'name' => 'Military', 'prefix' => 'mil', 'size' => 8, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_main_grid_is_row_one(): void
    {
        $this->assertSame('main', DB::table('grids')->find(1)->slug);
    }

    public function test_two_grids_hold_a_system_at_the_same_coordinates_and_each_sees_only_its_own(): void
    {
        $main = System::factory()->at(3, 3)->create(['type' => SystemType::Silo]);
        $sub = System::factory()->at(3, 3)->create(['type' => SystemType::Compute, 'grid_id' => 2]);

        $grid = Grid::fromConfig();

        $this->assertTrue($grid->at(new Position(3, 3))->is($main));
        $this->assertTrue(System::query()->at(new Position(3, 3, 2))->first()->is($sub));
        $this->assertSame(1, $grid->occupied());
        $this->assertSame(['3,3' => true], $grid->occupiedPositions());
        $this->assertCount(1, $grid->within(new Position(3, 3)));
        $this->assertCount(1, System::query()->within(new Position(3, 3, 2), 2)->get());
    }

    public function test_a_position_is_on_one_grid(): void
    {
        $this->assertFalse((new Position(1, 1))->equals(new Position(1, 1, 2)));
        $this->assertFalse((new Position(1, 1))->isAdjacentTo(new Position(1, 2, 2)));
        $this->assertSame(2, (new Position(1, 1, 2))->neighbours()[0]->grid);
    }

    public function test_contacts_and_terrain_of_the_same_coordinates_live_side_by_side(): void
    {
        $character = Character::factory()->create();
        $seen = fn (string $address, int $grid) => SystemContact::query()->create([
            'character_id' => $character->id, 'grid_id' => $grid, 'x' => 2, 'y' => 2, 'address' => $address, 'name' => 'x',
            'type' => SystemType::Silo, 'seen_at' => now(), 'first_seen_at' => now(),
        ]);
        $seen('AAAAAAAA', 1);
        $seen('mil:0001', 2);

        $this->assertSame('AAAAAAAA', SystemContact::query()->at(new Position(2, 2))->value('address'));
        $this->assertSame('mil:0001', SystemContact::query()->at(new Position(2, 2, 2))->value('address'));

        $terrain = new Terrain($character);
        $terrain->sweep([new Position(2, 2)]);

        $this->assertTrue((new Terrain($character->fresh()))->hasSwept(new Position(2, 2)));
        $this->assertFalse((new Terrain($character->fresh()))->hasSwept(new Position(2, 2, 2)));

        (new Terrain($character->fresh()))->sweep([new Position(2, 2, 2)]);
        $this->assertSame(2, DB::table('character_terrain')->where('character_id', $character->id)->count());
    }
}
