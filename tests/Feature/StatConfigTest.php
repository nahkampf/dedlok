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

    /**
     * `coop_slots` floors at ZERO and `program_threads` floors at one, and the
     * difference is the whole design rather than an inconsistency to tidy.
     *
     * Software floors at one because a character who could run no copy of
     * anything is one for whom every program in the game quietly stopped
     * existing. A character who can call no cooperative run has simply not
     * trained for it — zero is the ordinary state AND the gate, so raising this
     * floor to one would hand every character in the game a party they never
     * paid for.
     */
    public function test_a_party_is_trained_for_and_software_is_not(): void
    {
        $registry = StatRegistry::fromConfig();

        $this->assertTrue($registry->has('coop_slots'), 'the two coordination skills have nothing to target without it');
        $this->assertSame(0.0, (float) $registry->get('coop_slots')->min, 'zero is the gate');
        $this->assertSame(1.0, (float) $registry->get('program_threads')->min);
        $this->assertSame(0, (int) config('cyberspace.coop.slots'), 'and nobody starts with a party');
    }

    /**
     * NOTHING HEALS BY WAITING, and the zero base is where that rule lives.
     *
     * Synaptic Integrity and hit points come back through things a player does.
     * The single exception is bought with a rank 16 skill — Neuroplasticity, a
     * point a minute per level — so a character who has not trained it recovers
     * nothing by sitting still, ever. Raising this base would hand passive
     * healing to everybody and quietly delete the reason healing exists.
     */
    public function test_nobody_recovers_by_waiting_unless_they_trained_for_it(): void
    {
        $registry = StatRegistry::fromConfig();

        $this->assertTrue($registry->has('synaptic_regen'));
        $this->assertSame(0.0, (float) $registry->get('synaptic_regen')->min);
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
