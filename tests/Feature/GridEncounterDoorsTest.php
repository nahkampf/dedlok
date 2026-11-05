<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\City\EncounterRunner;
use App\City\Encounters;
use App\City\EncounterStart;
use App\Cyberspace\Navigator;
use App\Cyberspace\Position;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\SystemType;
use App\Cyberspace\TravelResult;
use App\Models\Character;
use App\Models\Encounter;
use App\Models\Ice;
use App\Models\Run;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** The system and run doors, and what the ending of a run encounter does to the run. */
final class GridEncounterDoorsTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        $this->app->bind(Encounters::class, fn ($app): Encounters => Encounters::fromConfig($app->make(Resolver::class), static fn (int $min, int $max): int => min($max, max($min, 1))));

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character = $this->character->fresh();

        Encounter::query()->delete();
    }

    /** @param  array<string, mixed>  $attributes */
    private function encounter(EncounterStart $door, array $attributes = [], ?string $runEnd = null): Encounter
    {
        $encounter = Encounter::create(array_merge([
            'slug' => 'door_'.$door->value, 'title' => 'x', 'starts_on' => $door->value, 'weight' => 100, 'once' => false, 'retired' => false,
        ], $attributes));
        $encounter->nodes()->create(['node_key' => 'only', 'body' => 'x', 'entry' => true, 'ends' => true, 'run_end' => $runEnd]);

        return $encounter;
    }

    private function step(): TravelResult
    {
        return Navigator::fromConfig(app(Resolver::class))->step($this->character->fresh(), new Position(9, 8));
    }

    public function test_arriving_at_a_system_of_the_right_type_opens_it(): void
    {
        $this->encounter(EncounterStart::System, ['system_type' => 'silo']);
        System::factory()->at(9, 8)->create(['type' => SystemType::Silo, 'grade' => 3]);

        $this->assertTrue($this->step()->metSomething());
    }

    public function test_a_system_of_another_type_or_grade_does_not(): void
    {
        $this->encounter(EncounterStart::System, ['system_type' => 'compute']);
        System::factory()->at(9, 8)->create(['type' => SystemType::Silo, 'grade' => 3]);
        $this->assertFalse($this->step()->metSomething(), 'wrong type');

        Encounter::query()->delete();
        $this->encounter(EncounterStart::System, ['grade_min' => 5]);
        $this->assertFalse($this->step()->metSomething(), 'grade 3 is below the floor');
    }

    public function test_an_empty_position_is_not_a_system(): void
    {
        $this->encounter(EncounterStart::System);

        $this->assertFalse($this->step()->metSomething());
    }

    private function runUnderway(RunPurpose $purpose = RunPurpose::Extraction): Run
    {
        System::factory()->at(8, 8)->grade(2)->withIce(Ice::query()->where('level', 1)->sole())->create(['address' => 'DEADBEEF']);
        $started = app(RunStarter::class)->start($this->character, $purpose);
        $this->assertTrue($started->succeeded());
        $this->character = $this->character->fresh();

        return $started->run;
    }

    public function test_a_run_door_opens_between_turns_and_kill_run_ends_the_run_where_they_stand(): void
    {
        $encounter = $this->encounter(EncounterStart::Run, ['grade_min' => 1], 'kill_run');
        $run = $this->runUnderway();
        $system = $run->system();

        $live = app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::Run, $system, $run);
        $this->assertNotNull($live);
        $this->assertSame($run->id, $live->run_id);
        $this->assertTrue($encounter->is($live->encounter));

        $this->assertTrue(app(EncounterRunner::class)->leave($this->character->fresh()) === true);

        $character = $this->character->fresh();
        $this->assertNull($character->current_run_id, 'the run ended');
        $this->assertTrue($character->isJackedIn(), 'and they are still on the grid');
        $this->assertSame(RunEnding::Encounter->value, $run->fresh()->ending);
        $this->assertNull($character->current_encounter_id);
    }

    public function test_continue_run_leaves_the_run_exactly_as_it_was(): void
    {
        $this->encounter(EncounterStart::Run, [], 'continue_run');
        $run = $this->runUnderway();

        app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::Run, $run->system(), $run);
        $this->assertTrue(app(EncounterRunner::class)->leave($this->character->fresh()) === true);

        $character = $this->character->fresh();
        $this->assertSame($run->id, $character->current_run_id);
        $this->assertNull($run->fresh()->ended_at);
    }

    public function test_the_run_lock_stands_down_so_the_encounter_screen_is_reachable(): void
    {
        $this->encounter(EncounterStart::Run, [], 'continue_run');
        $run = $this->runUnderway();
        app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::Run, $run->system(), $run);

        $user = $this->character->user;

        $this->actingAs($user)->get(route('encounter.show'))->assertOk();
        $this->actingAs($user)->get(route('run.show'))->assertRedirect(route('encounter.show'));
    }

    public function test_the_run_door_filters_on_the_runs_purpose(): void
    {
        $this->encounter(EncounterStart::Run, ['run_purpose' => 'burn'], 'continue_run');
        $run = $this->runUnderway(RunPurpose::Extraction);

        $this->assertNull(app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::Run, $run->system(), $run));
    }

    private function endedRun(RunPurpose $purpose = RunPurpose::Extraction): Run
    {
        $run = $this->runUnderway($purpose);
        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Completed);
        $this->character = $this->character->fresh();

        return $run->fresh();
    }

    public function test_the_after_run_door_opens_once_a_run_is_over_and_remembers_no_run(): void
    {
        $encounter = $this->encounter(EncounterStart::AfterRun, ['run_purpose' => 'extraction', 'system_type' => 'silo', 'grade_min' => 2, 'grade_max' => 2]);
        $run = $this->endedRun();
        $system = $run->system()->forceFill(['type' => SystemType::Silo]);

        $live = app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::AfterRun, $system, $run);

        $this->assertNotNull($live);
        $this->assertTrue($encounter->is($live->encounter));
        $this->assertNull($live->run_id, 'there is no run left to continue or kill');
        $this->assertTrue($this->character->fresh()->isJackedIn());
    }

    public function test_the_after_run_door_filters_on_purpose_type_grade_and_owner(): void
    {
        $run = $this->endedRun(RunPurpose::Extraction);
        $system = $run->system();

        foreach ([['run_purpose' => 'burn'], ['system_type' => 'relay'], ['grade_min' => 3], ['owner_entity_id' => 999999]] as $filter) {
            Encounter::query()->delete();
            $this->encounter(EncounterStart::AfterRun, $filter);

            $this->assertNull(app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::AfterRun, $system, $run), json_encode($filter));
        }
    }

    public function test_the_after_run_door_reads_a_system_that_the_run_burned(): void
    {
        $this->encounter(EncounterStart::AfterRun, ['grade_min' => 2]);
        $run = $this->endedRun();
        $system = $run->system();
        $system->delete();

        $this->assertNotNull(app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::AfterRun, $system, $run));
    }

    public function test_a_run_door_encounter_does_not_open_after_a_run_and_the_reverse(): void
    {
        $this->encounter(EncounterStart::Run, [], 'continue_run');
        $run = $this->endedRun();

        $this->assertNull(app(Encounters::class)->maybeOpenFor($this->character, EncounterStart::AfterRun, $run->system(), $run));
    }

    public function test_the_after_run_door_can_ask_for_success_failure_or_either(): void
    {
        $done = $this->endedRun();
        $system = $done->system();

        foreach ([['success', true], ['failure', false], [null, true]] as [$outcome, $opens]) {
            Encounter::query()->delete();
            $this->encounter(EncounterStart::AfterRun, ['run_outcome' => $outcome]);

            $this->assertSame($opens, app(Encounters::class)->maybeOpenFor($this->character->fresh(), EncounterStart::AfterRun, $system, $done) !== null, (string) $outcome);
            $this->character->forceFill(['current_encounter_id' => null])->save();
        }

        $done->forceFill(['ending' => RunEnding::Egress->value]);
        Encounter::query()->delete();
        $this->encounter(EncounterStart::AfterRun, ['run_outcome' => 'success']);

        $this->assertNull(app(Encounters::class)->maybeOpenFor($this->character->fresh(), EncounterStart::AfterRun, $system, $done), 'an egress with nothing taken is a failure');
    }
}
