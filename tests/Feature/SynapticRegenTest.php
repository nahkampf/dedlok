<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\Skill;
use App\Stats\Modifier;
use App\Stats\Resolver;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Healing by waiting: a slow mend for everybody, and a fast trickle bought at
 * the top of the ladder.
 */
final class SynapticRegenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Off the four-hourly mark, so the mend everybody gets stays out of
        // tests about the trained trickle. See the mend tests below.
        $this->travelTo(now()->setTime(12, 1));
    }

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    /** Trained to `$level`, which is the only way anybody regenerates at all. */
    private function withNeuroplasticity(Character $character, int $level): Character
    {
        SkillStats::forget();
        Sheet::forgetSkills();

        $skill = Skill::factory()->governedBy('focus', 'intellect')->create([
            'slug' => 'neuroplasticity',
            'title' => 'Neuroplasticity',
            'category' => 'Cybernetics',
            'rank' => 16,
            'modifiers' => array_map(
                static fn (int $l): array => [
                    'op' => 'add',
                    'level' => $l,
                    'amount' => 1,
                    'target' => ['id' => 'synaptic_regen'],
                ],
                range(1, 5),
            ),
        ]);

        SkillStats::forget();
        Sheet::forgetSkills();

        app(SkillInjector::class)->grant($character, $skill, $level);

        return $character->fresh();
    }

    /** NOBODY GETS THIS FOR FREE. A untrained character sits at zero for ever. */
    public function test_an_untrained_character_recovers_nothing(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 1])->save();

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(1, $character->fresh()->current_synaptic_integrity);
    }

    public function test_a_trained_character_recovers_a_point_a_level(): void
    {
        $character = $this->withNeuroplasticity($this->character(), 3);
        $character->forceFill(['current_synaptic_integrity' => 1])->save();

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(4, $character->fresh()->current_synaptic_integrity, 'three levels, three points');
    }

    /**
     * IT HEALS FROM ZERO, deliberately — the only route off the floor that
     * exists until healing is designed.
     */
    public function test_it_brings_somebody_back_from_nothing(): void
    {
        $character = $this->withNeuroplasticity($this->character(), 2);
        $character->forceFill(['current_synaptic_integrity' => 0])->save();

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame(2, $character->fresh()->current_synaptic_integrity);
    }

    /** Capped at the ceiling AS IT IS NOW — healing never climbs past an injury. */
    public function test_it_never_heals_past_the_maximum(): void
    {
        $character = $this->withNeuroplasticity($this->character(), 5);
        $max = $character->maximumFor('current_synaptic_integrity');

        $character->forceFill(['current_synaptic_integrity' => $max - 1])->save();

        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame($max, $character->fresh()->current_synaptic_integrity);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $character = $this->withNeuroplasticity($this->character(), 3);
        $character->forceFill(['current_synaptic_integrity' => 1])->save();

        $this->artisan('characters:regenerate --dry-run')->assertSuccessful();

        $this->assertSame(1, $character->fresh()->current_synaptic_integrity);
    }

    /** `recover()` is takeDamage's mirror, and just as dull. */
    public function test_recovering_returns_what_it_actually_restored(): void
    {
        $character = $this->character();
        $max = $character->maximumFor('current_synaptic_integrity');

        $character->forceFill(['current_synaptic_integrity' => $max - 1])->save();

        $this->assertSame(1, $character->recover('current_synaptic_integrity', 5), 'one short of the ceiling gains one');
        $this->assertSame(0, $character->recover('current_synaptic_integrity', 5), 'and nothing at it');
    }

    public function test_healing_is_never_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->character()->recover('current_synaptic_integrity', -1);
    }

    /** EVERYBODY MENDS: a point on each four-hourly mark, trained or not. */
    public function test_everybody_mends_a_point_on_the_four_hourly_mark(): void
    {
        $character = $this->character();
        $character->forceFill(['current_synaptic_integrity' => 1])->save();

        $this->travelTo(now()->setTime(12, 0));
        $this->artisan('characters:regenerate')->assertSuccessful();
        $this->assertSame(2, $character->fresh()->current_synaptic_integrity);

        // Every other minute of the four hours brings nothing.
        foreach ([[12, 1], [13, 0], [15, 59]] as [$h, $m]) {
            $this->travelTo(now()->setTime($h, $m));
            $this->artisan('characters:regenerate')->assertSuccessful();
        }
        $this->assertSame(2, $character->fresh()->current_synaptic_integrity);

        $this->travelTo(now()->setTime(16, 0));
        $this->artisan('characters:regenerate')->assertSuccessful();
        $this->assertSame(3, $character->fresh()->current_synaptic_integrity);
    }

    public function test_mending_never_passes_the_maximum(): void
    {
        $character = $this->character();
        $max = $character->maximumFor('current_synaptic_integrity');
        $character->forceFill(['current_synaptic_integrity' => $max])->save();

        $this->travelTo(now()->setTime(12, 0));
        $this->artisan('characters:regenerate')->assertSuccessful();

        $this->assertSame($max, $character->fresh()->current_synaptic_integrity);
    }

    /** An interval, so a percentage off it lands: −25% is a point every three hours. */
    public function test_the_interval_is_a_stat_a_modifier_can_shorten(): void
    {
        $character = $this->character();
        $base = $character->stat('synaptic_mend_minutes')->toInt();

        $faster = app(Resolver::class)->resolve('synaptic_mend_minutes', (float) $base, [
            Modifier::fromArray(['op' => 'percentage_of_base', 'amount' => -25, 'target' => ['id' => 'synaptic_mend_minutes']]),
        ])->toInt();

        $this->assertSame(240, $base);
        $this->assertSame(180, $faster);
    }
}
