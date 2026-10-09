<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\Finder;
use App\Cyberspace\Position;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\SystemContact;
use App\Models\SystemScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_eight_directions(): void
    {
        $o = new Position(50, 50);

        $this->assertSame('n', Finder::direction($o, new Position(50, 40)));
        $this->assertSame('ne', Finder::direction($o, new Position(60, 40)));
        $this->assertSame('e', Finder::direction($o, new Position(60, 50)));
        $this->assertSame('se', Finder::direction($o, new Position(60, 60)));
        $this->assertSame('s', Finder::direction($o, new Position(50, 60)));
        $this->assertSame('sw', Finder::direction($o, new Position(40, 60)));
        $this->assertSame('w', Finder::direction($o, new Position(40, 50)));
        $this->assertSame('nw', Finder::direction($o, new Position(40, 40)));
        // Mostly east with a little north is still east.
        $this->assertSame('e', Finder::direction($o, new Position(80, 45)));
    }

    public function test_a_query_is_read_as_type_owner_and_grade(): void
    {
        $q = (new Finder)->parse('Exchange, Lockjaw, Grade >2');

        $this->assertSame(SystemType::Exchange, $q->type);
        $this->assertSame('Lockjaw', $q->owner);
        $this->assertSame('>', $q->gradeOp);
        $this->assertSame(2, $q->grade);
        $this->assertTrue($q->gradeFits(3));
        $this->assertFalse($q->gradeFits(2));
    }

    public function test_it_finds_the_nearest_system_that_fits_among_those_met(): void
    {
        $character = Character::factory()->create(['grid_x' => 100, 'grid_y' => 100]);
        $see = fn (string $addr, int $x, int $y, string $type, ?string $owner) => SystemContact::query()->create([
            'character_id' => $character->id, 'x' => $x, 'y' => $y, 'address' => $addr, 'name' => $addr,
            'type' => $type, 'owner' => $owner, 'seen_at' => now(), 'first_seen_at' => now(),
        ]);
        $see('AAAAAAAA', 103, 100, 'exchange', 'Lockjaw');
        $see('BBBBBBBB', 100, 90, 'exchange', 'Lockjaw');
        $see('CCCCCCCC', 100, 108, 'exchange', 'Other');
        foreach (['AAAAAAAA' => 4, 'BBBBBBBB' => 1] as $addr => $grade) {
            SystemScan::query()->create(['character_id' => $character->id, 'address' => $addr, 'x' => 1, 'y' => 1, 'grade' => $grade, 'passes' => 0, 'scanned_at' => now(), 'expires_at' => now()->addDay()]);
        }

        $finder = new Finder;

        $any = $finder->nearest($character, $finder->parse('exchange, lockjaw'));
        $this->assertSame('AAAAAAAA', $any->contact->address);
        $this->assertSame(3, $any->steps);
        $this->assertSame('e', $any->direction);

        // Grade is known only from a scan, and BBBBBBBB's is too low.
        $high = $finder->nearest($character, $finder->parse('lockjaw, grade >2'));
        $this->assertSame('AAAAAAAA', $high->contact->address);
        $this->assertNull($finder->nearest($character, $finder->parse('lockjaw, grade >5')));
        // CCCCCCCC has no scan, so a grade term cannot match it.
        $this->assertNull($finder->nearest($character, $finder->parse('other, grade >0')));
    }

    public function test_a_step_goes_one_cell_toward_the_target(): void
    {
        $this->assertEquals(new Position(11, 9), Finder::stepToward(new Position(10, 10), new Position(50, 0)));
        $this->assertEquals(new Position(10, 11), Finder::stepToward(new Position(10, 10), new Position(10, 40)));
        $this->assertEquals(new Position(9, 10), Finder::stepToward(new Position(10, 10), new Position(0, 10)));
    }

    public function test_autotraverse_needs_a_running_pathfinder(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->create(['user_id' => $user->id, 'grid_x' => 100, 'grid_y' => 100]);

        $status = $this->actingAs($user)->postJson(route('cyberspace.traverse'), ['x' => 110, 'y' => 100])->status();
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertSame(100, $character->fresh()->grid_x);
    }
}
