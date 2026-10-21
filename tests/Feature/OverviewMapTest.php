<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\SystemContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

final class OverviewMapTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    public function test_it_lists_what_the_character_remembers_cached_for_a_minute_and_marks_where_they_stand(): void
    {
        Cache::flush();
        $me = Character::factory()->create(['archetype' => 3]);
        $this->equipReadyDeck($me);
        $me->forceFill(['grid_x' => 5, 'grid_y' => 6, 'grid_id' => 1])->save();
        $other = Character::factory()->create();
        $row = ['grid_id' => 1, 'address' => 'AAAAAAAA', 'name' => 'Silo - A', 'seen_at' => now(), 'first_seen_at' => now()];
        SystemContact::query()->create($row + ['character_id' => $me->id, 'x' => 2, 'y' => 3, 'type' => SystemType::Silo]);
        SystemContact::query()->create(['address' => 'BBBBBBBB'] + $row + ['character_id' => $other->id, 'x' => 9, 'y' => 9, 'type' => SystemType::Relay]);

        $json = $this->actingAs($me->user)->getJson(route('cyberspace.overview'))->assertOk()->json();

        $this->assertSame('Overview map: Main Grid', $json['title']);
        $this->assertSame([[2, 3, 'silo', 'AAAAAAAA']], $json['cells']);
        $this->assertSame([5, 6], $json['you']);
        $this->assertCount((int) config('cyberspace.size'), $json['swept']);
        $this->assertSame(str_repeat('0', (int) config('cyberspace.size')), $json['swept'][0]);
        $this->assertSame((int) config('cyberspace.size'), $json['size']);

        // A new sighting is not on the map until the minute is up; where they stand is always current.
        SystemContact::query()->create(['address' => 'CCCCCCCC'] + $row + ['character_id' => $me->id, 'x' => 4, 'y' => 4, 'type' => SystemType::Relay]);
        $me->forceFill(['grid_x' => 7])->save();
        $again = $this->actingAs($me->user)->getJson(route('cyberspace.overview'))->json();

        $this->assertCount(1, $again['cells']);
        $this->assertSame([7, 6], $again['you']);
    }
}
