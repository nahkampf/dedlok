<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\ModifierField;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\Skill;
use App\Stats\Resolver;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `hitpoints_regen`: hit points recovered per HOUR, zero for everybody until an effect carries it. The minute sweep
 * credits each minute's share of the hourly rate, exactly, with no stored remainder.
 */
final class HitpointRegenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Off the four-hourly SI mark, so the slow SI point stays out of what these tests count.
        $this->travelTo(now()->setTime(12, 1, 0));
    }

    private function hurt(int $by = 20): Character
    {
        $character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $character->forceFill(['current_hitpoints' => $character->maximumFor('current_hitpoints') - $by])->save();

        return $character->fresh();
    }

    /** A skill carrying `hitpoints_regen` at `$perHour` from level 1, injected for the character. */
    private function carrying(Character $character, int $perHour): Character
    {
        SkillStats::forget();
        Sheet::forgetSkills();

        $skill = Skill::factory()->governedBy('focus', 'intellect')->create([
            'slug' => 'knitting', 'title' => 'Knitting', 'category' => 'Physical', 'rank' => 4,
            'modifiers' => [['op' => 'add', 'level' => 1, 'amount' => $perHour, 'target' => ['id' => 'hitpoints_regen']]],
        ]);

        SkillStats::forget();
        Sheet::forgetSkills();
        app(SkillInjector::class)->grant($character, $skill, 1);

        return $character->fresh();
    }

    private function sweep(): void
    {
        $this->artisan('characters:regenerate')->assertSuccessful();
    }

    public function test_nobody_regenerates_hit_points_without_an_effect(): void
    {
        $character = $this->hurt();
        $before = $character->current_hitpoints;

        foreach (range(1, 5) as $i) {
            $this->travel(1)->minutes();
            $this->sweep();
        }

        $this->assertSame($before, $character->fresh()->current_hitpoints);
        $this->assertSame(0, app(Resolver::class)->resolve('hitpoints_regen', 0.0, $character->modifiers())->toInt());
    }

    public function test_sixty_an_hour_is_a_point_every_minute(): void
    {
        $character = $this->carrying($this->hurt(), 60);
        $before = $character->current_hitpoints;

        foreach (range(1, 5) as $i) {
            $this->travel(1)->minutes();
            $this->sweep();
        }

        $this->assertSame($before + 5, $character->fresh()->current_hitpoints);
    }

    /** THE POINT OF PER-HOUR: the smallest grant is a point an hour, spread across it — not sixty. */
    public function test_one_an_hour_is_exactly_one_point_over_any_hour(): void
    {
        $character = $this->carrying($this->hurt(), 1);
        $before = $character->current_hitpoints;

        foreach (range(1, 60) as $i) {
            $this->travel(1)->minutes();
            $this->sweep();
        }

        $this->assertSame($before + 1, $character->fresh()->current_hitpoints);
    }

    public function test_six_an_hour_is_six_points_over_an_hour_not_in_a_lump(): void
    {
        $character = $this->carrying($this->hurt(), 6);
        $before = $character->current_hitpoints;
        $seen = [];

        foreach (range(1, 60) as $i) {
            $this->travel(1)->minutes();
            $this->sweep();
            $seen[] = $character->fresh()->current_hitpoints - $before;
        }

        $this->assertSame(6, end($seen));
        // A point every ten minutes, give or take the epoch's own alignment: never two in one minute.
        $steps = array_map(fn (int $i): int => $seen[$i] - ($seen[$i - 1] ?? 0), array_keys($seen));
        $this->assertLessThanOrEqual(1, max($steps));
    }

    public function test_it_stops_at_the_maximum_as_it_stands(): void
    {
        $character = $this->carrying($this->hurt(by: 1), 600);
        $max = $character->maximumFor('current_hitpoints');

        foreach (range(1, 5) as $i) {
            $this->travel(1)->minutes();
            $this->sweep();
        }

        $this->assertSame($max, $character->fresh()->current_hitpoints);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $character = $this->carrying($this->hurt(), 60);
        $before = $character->current_hitpoints;

        $this->artisan('characters:regenerate --dry-run')->assertSuccessful()->expectsOutputToContain('hit points');

        $this->assertSame($before, $character->fresh()->current_hitpoints);
    }

    public function test_the_stat_is_offered_in_the_effects_editor(): void
    {
        $offered = collect(ModifierField::targets())->flatMap(fn ($group) => array_keys($group))->all();

        $this->assertContains('hitpoints_regen', $offered);
    }
}
