<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The scheduled half of training. The arithmetic is TrainingDepositTest's
 * problem; what matters here is who the command touches and what it says.
 */
class DepositTrainingCommandTest extends TestCase
{
    use RefreshDatabase;

    private Skill $hacking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hacking = Skill::factory()->rank(16)
            ->governedBy('intellect', 'memory')
            ->create(['slug' => 'hacking']);
    }

    private function trainee(?CarbonImmutable $clock = null): Character
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        TrainingQueueEntry::create([
            'character_id' => $character->id,
            'skill_id' => $this->hacking->id,
            'target_level' => 3,
            'position' => 1,
            'last_deposited_at' => $clock,
        ]);

        return $character;
    }

    private function tenMinutesAgo(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMinute()->subMinutes(10);
    }

    private function points(Character $character): int
    {
        return (int) (CharacterSkill::where('character_id', $character->id)->value('skill_points') ?? 0);
    }

    public function test_it_advances_every_character_with_a_queue(): void
    {
        $first = $this->trainee($this->tenMinutesAgo());
        $second = $this->trainee($this->tenMinutesAgo());

        $this->artisan('training:deposit')
            ->expectsOutputToContain('2 character(s) advanced')
            ->assertSuccessful();

        $this->assertGreaterThan(0, $this->points($first));
        $this->assertGreaterThan(0, $this->points($second));
    }

    public function test_a_character_with_no_queue_is_left_alone(): void
    {
        $idle = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        $this->artisan('training:deposit')->assertSuccessful();

        $this->assertSame(0, CharacterSkill::where('character_id', $idle->id)->count());
    }

    public function test_the_character_option_limits_the_run(): void
    {
        $chosen = $this->trainee($this->tenMinutesAgo());
        $other = $this->trainee($this->tenMinutesAgo());

        $this->artisan('training:deposit', ['--character' => $chosen->id])
            ->expectsOutputToContain('1 character(s) advanced')
            ->assertSuccessful();

        $this->assertGreaterThan(0, $this->points($chosen));
        $this->assertSame(0, $this->points($other));
    }

    public function test_it_reports_a_level_as_it_is_reached(): void
    {
        // Level 1 of a rank 16 skill is 3,872 points: about 33 minutes.
        $character = $this->trainee(CarbonImmutable::now()->startOfMinute()->subMinutes(40));

        $this->artisan('training:deposit')
            ->expectsOutputToContain("{$character->handle} reached [hacking] level 1")
            ->assertSuccessful();
    }

    public function test_quiet_when_idle_says_nothing_when_nothing_happened(): void
    {
        // A queue whose clock has not started: the run sets it and awards nothing.
        $this->trainee();

        $this->artisan('training:deposit', ['--quiet-when-idle' => true])
            ->doesntExpectOutput()
            ->assertSuccessful();
    }

    public function test_without_the_flag_an_idle_run_still_reports(): void
    {
        $this->trainee();

        $this->artisan('training:deposit')
            ->expectsOutputToContain('0 character(s) advanced')
            ->assertSuccessful();
    }

    /**
     * The console half of this command is written to nothing on the heartbeat
     * — a scheduled command's stdout cannot reach the container log — so the
     * log is the only record that a run did anything.
     */
    public function test_what_happened_is_logged(): void
    {
        Log::spy();

        $character = $this->trainee(CarbonImmutable::now()->startOfMinute()->subMinutes(40));

        $this->artisan('training:deposit')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Skill level reached'
                && $context['character'] === $character->id
                && $context['skill'] === 'hacking'
                && $context['level'] === 1)
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, '1 character(s) advanced'))
            ->once();
    }

    public function test_an_idle_run_is_not_logged(): void
    {
        Log::spy();

        $this->trainee();

        $this->artisan('training:deposit')->assertSuccessful();

        Log::shouldNotHaveReceived('info');
    }

    public function test_a_stalled_character_is_named_in_the_summary(): void
    {
        $useless = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 0),
            ['archetype' => 3],
        ));

        TrainingQueueEntry::create([
            'character_id' => $useless->id,
            'skill_id' => $this->hacking->id,
            'target_level' => 1,
            'position' => 1,
            'last_deposited_at' => $this->tenMinutesAgo(),
        ]);

        $this->artisan('training:deposit')
            ->expectsOutputToContain('1 stalled on zero attributes')
            ->assertSuccessful();
    }
}
