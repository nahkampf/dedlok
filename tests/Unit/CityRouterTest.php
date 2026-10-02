<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\City\Router;
use PHPUnit\Framework\TestCase;

final class CityRouterTest extends TestCase
{
    public function test_the_same_district_is_a_route_of_one(): void
    {
        $this->assertSame([1], Router::route([[1, 2]], [], 1, 1));
    }

    public function test_it_takes_the_fewest_districts_when_the_weights_are_equal(): void
    {
        // 1-2-4 and 1-3-5-4: equal weights, so the shorter wins.
        $links = [[1, 2], [2, 4], [1, 3], [3, 5], [4, 5]];

        $this->assertSame([1, 2, 4], Router::route($links, [], 1, 4));
    }

    public function test_the_cost_is_the_weight_of_every_district_but_the_destination(): void
    {
        // 1 -> 2 -> 4 crosses 1 and 2; 1 -> 3 -> 4 crosses 1 and 3.
        // District 2 is heavy, so the route goes by 3 — whatever 4 weighs.
        $links = [[1, 2], [2, 4], [1, 3], [3, 4]];
        $weights = [1 => 1.0, 2 => 5.0, 3 => 1.0, 4 => 1.0];

        $this->assertSame([1, 3, 4], Router::route($links, $weights, 1, 4));
    }

    public function test_the_destinations_own_weight_never_counts(): void
    {
        // Two ways to reach 4 or 5 differ only in the DESTINATION'S weight.
        $links = [[1, 2], [1, 3], [2, 4], [3, 4]];
        $weights = [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 99.0];

        // Both routes cost 1 + 1; ties go to the lower ids.
        $this->assertSame([1, 2, 4], Router::route($links, $weights, 1, 4));
    }

    public function test_a_heavier_direct_route_can_lose_to_a_longer_light_one(): void
    {
        // Direct 1-4 costs weight(1)=1. Via 2 and 3 costs 1+1+1. Direct wins...
        $links = [[1, 4], [1, 2], [2, 3], [3, 4]];
        $this->assertSame([1, 4], Router::route($links, [], 1, 4));

        // ...but heavy the origin and it is the same either way, so fewer hops.
        $this->assertSame([1, 4], Router::route($links, [1 => 9.0], 1, 4));
    }

    public function test_no_chain_of_links_is_no_route(): void
    {
        $this->assertNull(Router::route([[1, 2], [3, 4]], [], 1, 4));
    }
}
