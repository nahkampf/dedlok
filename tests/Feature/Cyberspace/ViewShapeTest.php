<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\Sweep;
use App\Cyberspace\Terrain;
use App\Cyberspace\ViewShape;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContact;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** View range: ten levels, each a shape, from 0 (where you stand) to 9. */
final class ViewShapeTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 32);
        Config::set('cyberspace.view_range', 2);
    }

    /** @return array<string, array{int, int, int, int}> level => cells, reach sideways, reach up and down */
    public static function levels(): array
    {
        return [
            'vr0' => [0, 1, 0, 0],
            'vr1' => [1, 5, 1, 1],
            'vr2' => [2, 11, 2, 1],
            'vr3' => [3, 13, 2, 2],
            'vr4' => [4, 21, 2, 2],
            'vr5' => [5, 23, 3, 2],
            'vr6' => [6, 25, 3, 3],
            'vr7' => [7, 39, 4, 3],
            'vr8' => [8, 49, 4, 4],
            'vr9' => [9, 57, 4, 4],
        ];
    }

    /** @dataProvider levels */
    #[DataProvider('levels')]
    public function test_each_level_is_the_shape_that_was_drawn(int $level, int $cells, int $sideways, int $vertical): void
    {
        $shape = ViewShape::for($level);

        $this->assertCount($cells, $shape->offsets);
        $this->assertSame($sideways, max(array_map(static fn (array $o): int => abs($o[0]), $shape->offsets)));
        $this->assertSame($vertical, max(array_map(static fn (array $o): int => abs($o[1]), $shape->offsets)));
        $this->assertTrue($shape->sees(0, 0), 'the player is always in view');

        foreach ($shape->offsets as [$dx, $dy]) {
            $this->assertTrue($shape->sees(-$dx, $dy) && $shape->sees($dx, -$dy), 'every shape is symmetric');
        }
    }

    public function test_every_level_contains_the_one_below_it(): void
    {
        foreach (range(1, 9) as $level) {
            foreach (ViewShape::for($level - 1)->offsets as [$dx, $dy]) {
                $this->assertTrue(ViewShape::for($level)->sees($dx, $dy), "level {$level} lacks ({$dx},{$dy})");
            }
        }
    }

    public function test_a_level_outside_the_range_is_clamped(): void
    {
        $this->assertSame(0, ViewShape::for(-3)->level);
        $this->assertSame(9, ViewShape::for(40)->level);
    }

    public function test_level_zero_sees_only_where_you_stand_and_forgets_nothing_else(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        System::factory()->at(16, 17)->create(['address' => 'AAAAAAAA']);

        Sweep::fromConfig()->from($character, new Position(16, 16), ViewShape::for(2));
        $this->assertSame(1, SystemContact::query()->count());

        // Looking from level 0 at the same spot sees only (16,16): the neighbour stays remembered.
        $result = Sweep::fromConfig()->from($character, new Position(16, 16), ViewShape::for(0));

        $this->assertSame(1, $result->positions);
        $this->assertSame(0, $result->contactsLost);
        $this->assertSame(1, SystemContact::query()->count());
    }

    public function test_a_contact_inside_the_bounding_square_but_outside_the_shape_is_kept(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        // (+2,+2): inside the 5 x 5 square of level 2, outside its shape, inside level 9's.
        $system = System::factory()->at(18, 18)->create(['address' => 'BBBBBBBB']);

        Sweep::fromConfig()->from($character, new Position(16, 16), ViewShape::for(9));
        $this->assertSame(1, SystemContact::query()->count());

        // The machine moves away. A look that cannot reach that cell has no way to know.
        $system->update(['x' => 25, 'y' => 25, 'address' => 'CCCCCCCC']);

        $small = Sweep::fromConfig()->from($character, new Position(16, 16), ViewShape::for(2));
        $this->assertSame(0, $small->contactsLost);
        $this->assertSame(1, SystemContact::query()->count(), 'not looked at, so not forgotten');

        $wide = Sweep::fromConfig()->from($character, new Position(16, 16), ViewShape::for(9));
        $this->assertSame(1, $wide->contactsLost);
        $this->assertSame(0, SystemContact::query()->count());
    }

    public function test_the_resolved_level_follows_network_topology_and_stops_at_nine(): void
    {
        $character = Character::factory()->create(['archetype' => 3]);
        $skill = Skill::factory()->create([
            'slug' => 'networktopology',
            'modifiers' => array_map(
                static fn (int $level): array => ['op' => 'add', 'level' => $level, 'amount' => 1, 'target' => ['id' => 'view_range']],
                range(1, 5),
            ),
        ]);

        $this->assertSame(2, ViewShape::forCharacter($character, app(Resolver::class))->level, 'nothing improves it yet');

        CharacterSkill::query()->create([
            'character_id' => $character->id, 'skill_id' => $skill->id, 'skill_points' => 0, 'attained_level' => 3,
        ]);
        $this->assertSame(5, ViewShape::forCharacter($character->fresh(), app(Resolver::class))->level, 'one level per level trained');

        Config::set('cyberspace.view_range', 9);
        $this->assertSame(9, ViewShape::forCharacter($character->fresh(), app(Resolver::class))->level, 'and never past nine');
    }

    public function test_a_move_sweeps_the_characters_own_shape(): void
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($character);
        $character->forceFill(['grid_x' => 10, 'grid_y' => 10])->save();

        Navigator::fromConfig(app(Resolver::class))->step($character->fresh(), new Position(11, 10));

        $this->assertSame(11, (new Terrain($character))->sweptCount(), 'level 2 is eleven positions');
    }
}
