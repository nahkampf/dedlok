<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\Finder;
use App\Cyberspace\Position;
use Tests\TestCase;

final class PathfinderCoordinatesTest extends TestCase
{
    public function test_a_pair_of_numbers_is_a_position_and_not_a_grade(): void
    {
        $query = (new Finder)->parse('5,102');

        $this->assertEquals(new Position(5, 102), $query->position);
        $this->assertNull($query->grade);
        $this->assertNull((new Finder)->parse('exchange, grade >2')->position);
    }
}
