<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\Assessor;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\Skill;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssessorTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Assessor $assessor;

    private Skill $os;

    protected function setUp(): void
    {
        parent::setUp();

        // The skill first: its check stat is generated from the rows that exist when the registry is first built.
        $this->os = Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $this->character = Character::factory()->create();
        $this->assessor = app(Assessor::class);
    }

    private function scan(array $facts = []): SystemScan
    {
        return SystemScan::query()->create($facts + [
            'character_id' => $this->character->id, 'address' => 'AAAA0001', 'x' => 1, 'y' => 1, 'passes' => 1,
            'scanned_at' => now(), 'expires_at' => now()->addDay(),
            'grade' => 2, 'os_skill_id' => $this->os->id, 'ids_detection' => 17, 'tracer_detection' => 4, 'tracer_rate' => 5, 'ice_level' => 0,
        ]);
    }

    private function ice(): Ice
    {
        return Ice::query()->create(['level' => 90, 'name' => 'Test ICE', 'integrity' => 40, 'attack' => 5, 'skill' => 50, 'detection' => 10, 'grade_min' => 1, 'grade_max' => 9]);
    }

    public function test_a_figure_is_the_rolls_own_chance(): void
    {
        $scan = $this->scan();

        $this->assertEqualsWithDelta(
            app(Simulator::class)->osChanceFor($this->character, Skill::query()->find($scan->os_skill_id)),
            $this->assessor->core($this->character, $scan),
            0.0000001,
        );
        $this->assertEqualsWithDelta(
            app(Simulator::class)->unnoticedChance($this->character, [17, 4, 0]),
            $this->assessor->unnoticed($this->character, $scan),
            0.0000001,
        );
    }

    public function test_an_unread_fact_gives_nothing_even_when_the_system_has_it(): void
    {
        $this->ice();
        $blind = $this->scan(['ids_detection' => null, 'tracer_detection' => null, 'ice_level' => null, 'os_skill_id' => null]);

        $this->assertNull($this->assessor->unnoticed($this->character, $blind));
        $this->assertNull($this->assessor->core($this->character, $blind));
        $this->assertNull($this->assessor->strike($blind));
        $this->assertNull($this->assessor->overall($this->character, $blind));
        $this->assertNull($this->assessor->overall($this->character, null));
    }

    public function test_a_fact_read_as_none_is_known(): void
    {
        $scan = $this->scan(['ids_detection' => 0, 'tracer_detection' => 0, 'ice_level' => 0]);

        $this->assertSame(1.0, $this->assessor->unnoticed($this->character, $scan));
        $this->assertSame(0.0, $this->assessor->strike($scan));
        $this->assertNotNull($this->assessor->overall($this->character, $scan));
    }

    public function test_turns_to_break_is_unknown_without_an_icebreaker_and_counts_with_one(): void
    {
        $this->ice();
        $scan = $this->scan(['ice_level' => 90]);

        $this->assertNull($this->assessor->turnsToBreak($this->character, $scan, damage: 0.0));
        $this->assertSame(4, $this->assessor->turnsToBreak($this->character, $scan, damage: 10.0));
        $this->assertSame(1, $this->assessor->turnsToBreak($this->character, $scan, integrity: 5, damage: 10.0));
    }

    public function test_the_overall_is_stable_and_an_icebreaker_helps(): void
    {
        $this->ice();
        $scan = $this->scan(['ice_level' => 90]);

        $without = $this->assessor->overall($this->character, $scan, 0.0);
        $with = $this->assessor->overall($this->character, $scan, 25.0);

        $this->assertSame($without, $this->assessor->overall($this->character, $scan, 0.0));
        $this->assertSame($with, $this->assessor->overall($this->character, $scan, 25.0));
        $this->assertGreaterThanOrEqual($without, $with);
        $this->assertGreaterThanOrEqual(0.0, $without);
        $this->assertLessThanOrEqual(1.0, $with);
    }

    public function test_a_very_stealthy_runner_is_trusted_to_wake_nothing(): void
    {
        $this->ice();
        // Stealth far above every watcher: p is near 1, so the ICE is ignored and no icebreaker is needed.
        $this->character->forceFill(array_fill_keys(Character::ATTRIBUTES, 100))->save();
        $this->character = $this->character->fresh();
        $scan = $this->scan(['ice_level' => 90, 'ids_detection' => 1, 'tracer_detection' => 1]);
        Ice::query()->where('level', 90)->update(['detection' => 1]);

        $this->assertGreaterThan(0.5, $this->assessor->overall($this->character, $scan, 0.0));
    }

    public function test_rounding_goes_to_the_step_and_never_to_the_ends(): void
    {
        $this->assertSame(60, Assessor::round(0.62, 20));
        $this->assertSame(80, Assessor::round(0.97, 20));
        $this->assertSame(20, Assessor::round(0.0085, 20));
        $this->assertSame(65, Assessor::round(0.64, 5));
        $this->assertSame(95, Assessor::round(0.999, 5));
        $this->assertSame(5, Assessor::round(0.0, 5));
    }

    public function test_the_tag_is_green_from_85_yellow_from_50_and_red_below(): void
    {
        $this->assertStringContainsString('tag--ok', Assessor::tag(0.85, 5));
        $this->assertStringContainsString('tag--warn', Assessor::tag(0.82, 5));
        $this->assertStringContainsString('tag--warn', Assessor::tag(0.50, 5));
        $this->assertStringContainsString('tag--crit', Assessor::tag(0.47, 5));
        $this->assertStringNotContainsString('tag--', Assessor::tag(null, 5));
        $this->assertSame('Chance this turn: <span class="tag num">[???]</span>.', (string) Assessor::line('assessor.this_turn', Assessor::tag(null, 5)));
    }
}
