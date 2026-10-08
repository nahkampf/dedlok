<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\FileOrigin;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The odds of what a decrypted file becomes: held to the rules the design (CRAFTING.md) states. */
final class DecryptionOutcomesConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_grade_sums_to_a_hundred_and_nothing_present_is_under_five(): void
    {
        $components = (array) config('cyberspace.decryption.components');

        foreach (range(1, 9) as $grade) {
            $weights = array_filter(array_map(fn (array $c): int => (int) ($c['weights'][$grade] ?? 0), $components));

            $this->assertSame(100, array_sum($weights), "grade {$grade} must sum to 100");
            $this->assertGreaterThanOrEqual(5, min($weights), "grade {$grade}: a component that can appear is never under 5%");
            $this->assertLessThanOrEqual(20, count($weights), "grade {$grade}: more than twenty could not each keep 5%");
        }
    }

    public function test_every_component_is_an_authored_item_named_by_a_known_origin_field(): void
    {
        foreach ((array) config('cyberspace.decryption.components') as $slug => $component) {
            $this->assertTrue(Item::query()->where('slug', $slug)->exists(), "{$slug} has an item");
            $this->assertContains($component['headline'], FileOrigin::HEADLINES, "{$slug} is named by a real origin field");
        }
    }
}
