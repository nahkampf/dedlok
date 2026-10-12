<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The editor's rules for the grid doors: what an ending of a run encounter must say, and what a kind needs. */
final class GridEncounterEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        Encounter::query()->delete();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->encounter = Encounter::create(['slug' => 'ed', 'title' => 'x', 'starts_on' => 'run', 'weight' => 5, 'once' => false, 'retired' => false]);
    }

    /** @param  array<string, mixed>  $node */
    private function save(string $door, array $node = [], array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.encounters.update', $this->encounter), $extra + [
            'title' => 'x', 'starts_on' => $door, 'weight' => 5, 'entry' => 0,
            'nodes' => [array_merge(['key' => 'ed_a', 'body' => 'x', 'ends' => '1'], $node)],
        ]);
    }

    public function test_every_ending_of_a_run_encounter_must_say_what_becomes_of_the_run(): void
    {
        $this->save('run')->assertSessionHasErrors('nodes.0.run_end');
        $this->save('run', ['run_end' => 'kill_run'])->assertSessionHasNoErrors();
        $this->assertSame('kill_run', $this->encounter->nodes()->value('run_end'));
    }

    public function test_only_a_run_encounter_may_carry_a_run_end(): void
    {
        $this->save('grid_step', ['run_end' => 'kill_run'])->assertSessionHasErrors('nodes.0.run_end');
        $this->save('grid_step')->assertSessionHasNoErrors();
    }

    public function test_a_run_outcome_needs_a_run_door_and_a_move_needs_a_grid_door_that_is_not_a_run(): void
    {
        $trace = ['outcomes' => [['kind' => 'trace', 'amount' => '10']]];
        $this->save('grid_step', $trace)->assertSessionHasErrors('nodes.0.outcomes.0.kind');
        $this->save('run', $trace + ['run_end' => 'continue_run'])->assertSessionHasNoErrors();

        $move = ['outcomes' => [['kind' => 'grid_move', 'ref' => '3,4']]];
        $this->save('run', $move + ['run_end' => 'continue_run'])->assertSessionHasErrors('nodes.0.outcomes.0.kind');
        $this->save('grid_step', $move)->assertSessionHasNoErrors();
    }

    public function test_a_system_filter_is_only_for_the_system_door(): void
    {
        $this->save('grid_step', [], ['system_type' => 'silo'])->assertSessionHasErrors('system_type');
        $this->save('system', [], ['system_type' => 'silo', 'grade_min' => 2, 'grade_max' => 5])->assertSessionHasNoErrors();
        $this->save('system', [], ['grade_min' => 5, 'grade_max' => 2])->assertSessionHasErrors('grade_max');
    }
}
