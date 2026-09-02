<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Stats\StatRegistry;
use Tests\TestCase;

/**
 * The drift check MECHANICS.md asks for: config/stats.php and the Character
 * model must agree, or a new attribute silently becomes unmodifiable.
 */
class StatConfigTest extends TestCase
{
    public function test_every_character_attribute_is_a_declared_stat(): void
    {
        $registry = StatRegistry::fromConfig();

        foreach (Character::ATTRIBUTES as $attribute) {
            $this->assertTrue(
                $registry->has($attribute),
                "attribute [{$attribute}] is missing from config/stats.php"
            );
        }
    }

    public function test_attributes_are_tagged_by_their_group(): void
    {
        $registry = StatRegistry::fromConfig();

        $this->assertSame(Character::ATTRIBUTES, $registry->tagged('attribute'));
        $this->assertSame(Character::PHYSICAL_ATTRIBUTES, $registry->tagged('attribute', 'physical'));
        $this->assertSame(Character::MENTAL_ATTRIBUTES, $registry->tagged('attribute', 'mental'));
    }

    public function test_attribute_bounds_match_the_lifetime_ceiling_not_the_creation_one(): void
    {
        $registry = StatRegistry::fromConfig();

        foreach (Character::ATTRIBUTES as $attribute) {
            $stat = $registry->get($attribute);

            $this->assertSame((float) Character::ATTRIBUTE_MIN, $stat->min);
            $this->assertSame((float) Character::ATTRIBUTE_MAX, $stat->max, 'stats must use the lifetime cap');
            $this->assertSame(100.0, $stat->max);
        }
    }
}
