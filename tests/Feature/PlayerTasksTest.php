<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerTask;
use App\Tasks\TaskPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The tasks a player sets for themselves on the Tasks & Missions screen. */
final class PlayerTasksTest extends TestCase
{
    use RefreshDatabase;

    private Character $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = Character::factory()->create();
    }

    private function task(string $title, TaskPriority $priority, bool $done = false, ?Character $who = null): PlayerTask
    {
        return PlayerTask::create(['character_id' => ($who ?? $this->me)->id, 'title' => $title, 'priority' => $priority, 'completed_at' => $done ? now() : null]);
    }

    public function test_a_task_is_saved_with_its_priority(): void
    {
        $this->actingAs($this->me->user)->post(route('tasks.store'), ['title' => 'Buy a deck', 'priority' => 3])->assertRedirect();

        $task = PlayerTask::sole();
        $this->assertSame($this->me->id, $task->character_id);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertNull($task->completed_at);
    }

    public function test_a_title_is_required_and_capped_at_255(): void
    {
        $this->actingAs($this->me->user)->post(route('tasks.store'), ['title' => '', 'priority' => 1])->assertSessionHasErrors('title');
        $this->actingAs($this->me->user)->post(route('tasks.store'), ['title' => str_repeat('a', 256), 'priority' => 1])->assertSessionHasErrors('title');
        $this->actingAs($this->me->user)->post(route('tasks.store'), ['title' => str_repeat('a', 255), 'priority' => 4])->assertSessionHasErrors('priority');

        $this->assertSame(0, PlayerTask::count());
    }

    public function test_the_list_is_high_first_then_newest_and_done_ones_are_not_in_it(): void
    {
        $this->task('low old', TaskPriority::Low);
        $this->task('high old', TaskPriority::High);
        $this->task('medium', TaskPriority::Medium);
        $this->task('high new', TaskPriority::High);
        $this->task('finished', TaskPriority::High, done: true);

        $tasks = $this->actingAs($this->me->user)->get(route('tasks.index'))->assertOk()->viewData('tasks');

        $this->assertSame(['high new', 'high old', 'medium', 'low old'], $tasks->pluck('title')->all());
    }

    public function test_ticking_a_task_moves_it_to_the_completed_list_and_it_can_be_reintroduced(): void
    {
        $task = $this->task('Do it', TaskPriority::Low);

        $this->actingAs($this->me->user)->post(route('tasks.complete', $task))->assertRedirect();
        $this->assertNotNull($task->fresh()->completed_at);

        $page = $this->actingAs($this->me->user)->get(route('tasks.index'));
        $this->assertCount(0, $page->viewData('tasks'));
        $this->assertSame(['Do it'], $page->viewData('doneTasks')->pluck('title')->all());

        $this->actingAs($this->me->user)->post(route('tasks.reintroduce', $task))->assertRedirect();
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_a_task_can_be_deleted_by_its_owner_only(): void
    {
        $mine = $this->task('Mine', TaskPriority::Low, done: true);
        $theirs = $this->task('Theirs', TaskPriority::Low, done: true, who: Character::factory()->create());

        $this->actingAs($this->me->user)->post(route('tasks.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->me->user)->post(route('tasks.destroy', $mine))->assertRedirect()->assertSessionHas('done_tasks_open');

        $this->assertSame(['Theirs'], PlayerTask::pluck('title')->all());
    }

    public function test_somebody_elses_task_cannot_be_touched(): void
    {
        $theirs = $this->task('Theirs', TaskPriority::Low, who: Character::factory()->create());

        $this->actingAs($this->me->user)->post(route('tasks.complete', $theirs))->assertNotFound();
        $this->assertNull($theirs->fresh()->completed_at);
    }

    public function test_the_priority_tag_colours(): void
    {
        $this->assertSame(['tag--quiet', 'tag--warn', 'tag--ember'], array_map(fn (TaskPriority $p) => $p->tag(), TaskPriority::cases()));
    }
}
