<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Stats\StatRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Resources are stored state bounded by a derived maximum. The rule from
 * MECHANICS.md is clamp DOWN, never scale: losing a source of max HP must not
 * heal you, and must not leave you above your own ceiling either.
 */
class CharacterResourceTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function character(array $overrides = []): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],   // Fixer: no perks, so maxima are bare formulas
            $overrides,
        ));
    }

    public function test_the_columns_exist(): void
    {
        foreach (array_keys(Character::RESOURCES) as $resource) {
            $this->assertTrue(Schema::hasColumn('characters', $resource));
        }
    }

    public function test_every_resource_maps_to_a_declared_max_stat(): void
    {
        $registry = StatRegistry::fromConfig();

        foreach (Character::RESOURCES as $resource => $statId) {
            $this->assertTrue($registry->has($statId), "[{$resource}] points at undeclared stat [{$statId}]");
            $this->assertStringEndsWith('_max', $statId);
        }
    }

    public function test_a_new_character_starts_at_full(): void
    {
        // attributes all 10: HP = 10 + 5 + 5 = 20, SI = 5 + 5 + 5 + 5 = 20
        $character = $this->character();

        $this->assertSame(20, $character->current_hitpoints);
        $this->assertSame(20, $character->current_synaptic_integrity);
        $this->assertSame(20, $character->maximumFor('current_hitpoints'));
    }

    public function test_an_explicit_starting_value_is_respected(): void
    {
        $character = $this->character(['current_hitpoints' => 3]);

        $this->assertSame(3, $character->current_hitpoints);
    }

    public function test_zero_is_kept_rather_than_treated_as_uninitialised(): void
    {
        // 0 means dead, not "not set" — that distinction is why the column is
        // nullable rather than defaulting to 0.
        $character = $this->character(['current_hitpoints' => 0]);

        $this->assertSame(0, $character->fresh()->current_hitpoints);
    }

    public function test_a_resource_cannot_exceed_its_maximum(): void
    {
        $character = $this->character();

        $character->update(['current_hitpoints' => 9999]);

        $this->assertSame(20, $character->fresh()->current_hitpoints);
    }

    public function test_a_resource_cannot_go_negative(): void
    {
        $character = $this->character();

        $character->current_hitpoints = -5;
        $character->save();

        $this->assertSame(0, $character->fresh()->current_hitpoints);
    }

    public function test_lowering_an_attribute_clamps_the_resource_down(): void
    {
        $character = $this->character();
        $this->assertSame(20, $character->current_hitpoints);

        // meat 10 -> 2 drops HP max from 20 to 12.
        $character->update(['meat' => 2]);

        $this->assertSame(12, $character->maximumFor('current_hitpoints'));
        $this->assertSame(12, $character->fresh()->current_hitpoints, 'clamped down to the new ceiling');
    }

    public function test_a_wounded_character_is_not_healed_by_a_higher_maximum(): void
    {
        $character = $this->character();
        $character->update(['current_hitpoints' => 4]);

        // Raising meat raises the ceiling; current must NOT scale with it.
        $character->update(['meat' => 40]);

        $this->assertSame(50, $character->maximumFor('current_hitpoints'));
        $this->assertSame(4, $character->fresh()->current_hitpoints, 'clamp down, never scale');
    }

    public function test_a_wounded_character_is_not_healed_by_a_lower_maximum_either(): void
    {
        $character = $this->character();
        $character->update(['current_hitpoints' => 4]);

        $character->update(['meat' => 2]);   // max 20 -> 12, still above 4

        $this->assertSame(4, $character->fresh()->current_hitpoints);
    }

    public function test_the_archetype_perk_raises_the_ceiling_a_resource_clamps_to(): void
    {
        // Solo carries Scar tissue: +5% hitpoints_max.
        $solo = $this->character(['archetype' => 2]);

        // Base 20, +5% = 21, floored.
        $this->assertSame(21, $solo->maximumFor('current_hitpoints'));
        $this->assertSame(21, $solo->current_hitpoints);
    }

    public function test_clamp_resources_reports_whether_it_moved_anything(): void
    {
        $character = $this->character();

        $this->assertFalse($character->clampResources(), 'already inside its bounds');

        $character->current_hitpoints = 500;
        $this->assertTrue($character->clampResources());
        $this->assertSame(20, $character->current_hitpoints);
    }

    public function test_an_unknown_resource_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->character()->maximumFor('current_nonsense');
    }
}
