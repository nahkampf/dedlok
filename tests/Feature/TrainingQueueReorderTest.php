<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Skills\ReorderRefusal;
use App\Skills\TrainingQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * Reordering the training queue. A move is refused when the new order breaks a requirement the old one
 * did not: a level ahead of the one before it, or a skill ahead of its prerequisite.
 */
final class TrainingQueueReorderTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    private User $user;

    private Character $character;

    private Skill $hacking;

    private Skill $crypto;

    private Skill $codebreaking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));

        $this->hacking = Skill::factory()->create(['slug' => 'hacking']);
        $this->crypto = Skill::factory()->create(['slug' => 'cryptography']);
        $this->codebreaking = Skill::factory()->create(['slug' => 'codebreaking']);

        SkillPrerequisite::create(['skill_id' => $this->codebreaking->id, 'required_skill_id' => $this->hacking->id, 'required_level' => 2]);

        foreach ([$this->hacking, $this->crypto, $this->codebreaking] as $skill) {
            $this->inject($this->character, $skill);
        }
    }

    /** @param  list<array{0: Skill, 1: int}>  $entries @return list<int> the ids */
    private function queue(array $entries): array
    {
        $taken = TrainingQueueEntry::where('character_id', $this->character->id)->count();

        return array_map(fn (array $e, int $i): int => TrainingQueueEntry::create([
            'character_id' => $this->character->id, 'skill_id' => $e[0]->id, 'target_level' => $e[1], 'position' => $taken + $i + 1,
        ])->id, $entries, array_keys($entries));
    }

    private function order(): array
    {
        return TrainingQueueEntry::where('character_id', $this->character->id)->orderBy('position')->pluck('id')->all();
    }

    private function reorder(array $ids): mixed
    {
        return TrainingQueue::make()->reorder($this->character->fresh(), $ids);
    }

    public function test_independent_entries_can_be_reordered(): void
    {
        [$h1, $c1] = $this->queue([[$this->hacking, 1], [$this->crypto, 1]]);

        $this->assertTrue($this->reorder([$c1, $h1]));
        $this->assertSame([$c1, $h1], $this->order());
    }

    public function test_a_level_cannot_go_ahead_of_the_one_before_it(): void
    {
        [$h1, $h2, $c1] = $this->queue([[$this->hacking, 1], [$this->hacking, 2], [$this->crypto, 1]]);

        $this->assertSame(ReorderRefusal::BreaksRequirement, $this->reorder([$h2, $h1, $c1]));
        $this->assertSame([$h1, $h2, $c1], $this->order());
    }

    public function test_a_skill_cannot_go_ahead_of_its_prerequisite(): void
    {
        [$h1, $h2, $cb] = $this->queue([[$this->hacking, 1], [$this->hacking, 2], [$this->codebreaking, 1]]);

        $this->assertSame(ReorderRefusal::BreaksRequirement, $this->reorder([$h1, $cb, $h2]));
        $this->assertTrue($this->reorder([$h1, $h2, $cb]));
    }

    public function test_a_problem_the_queue_already_had_does_not_block_a_move_that_leaves_it_no_worse(): void
    {
        // Codebreaking first, behind nothing: already a problem. Swapping the two innocent ones is fine.
        [$cb, $h1, $c1] = $this->queue([[$this->codebreaking, 1], [$this->hacking, 1], [$this->crypto, 1]]);

        $this->assertTrue($this->reorder([$cb, $c1, $h1]));
        $this->assertSame([$cb, $c1, $h1], $this->order());
    }

    public function test_a_prerequisite_that_was_already_unmet_stays_the_same_problem_when_it_is_less_short(): void
    {
        // Codebreaking needs Hacking 2. First in the queue it is short by two; behind Hacking I, by one.
        [$cb, $h1, $h2] = $this->queue([[$this->codebreaking, 1], [$this->hacking, 1], [$this->hacking, 2]]);

        $this->assertTrue($this->reorder([$h1, $cb, $h2]));
    }

    public function test_the_entry_that_stops_being_first_loses_its_clock_and_the_new_first_starts_one(): void
    {
        [$h1, $c1] = $this->queue([[$this->hacking, 1], [$this->crypto, 1]]);
        TrainingQueueEntry::whereKey($h1)->update(['last_deposited_at' => now()->startOfMinute()]);

        $this->reorder([$c1, $h1]);

        $this->assertNull(TrainingQueueEntry::find($h1)->last_deposited_at);
        $this->assertNotNull(TrainingQueueEntry::find($c1)->last_deposited_at);
    }

    public function test_the_first_entry_keeps_its_clock_when_only_the_others_move(): void
    {
        [$h1, $c1, $cb] = $this->queue([[$this->hacking, 1], [$this->crypto, 1], [$this->hacking, 2]]);
        $stamp = now()->startOfMinute();
        TrainingQueueEntry::whereKey($h1)->update(['last_deposited_at' => $stamp]);

        $this->assertTrue($this->reorder([$h1, $cb, $c1]));

        $this->assertNotNull(TrainingQueueEntry::find($h1)->last_deposited_at);
    }

    public function test_a_stale_or_foreign_list_is_refused(): void
    {
        [$h1, $c1] = $this->queue([[$this->hacking, 1], [$this->crypto, 1]]);

        $this->assertSame(ReorderRefusal::QueueChanged, $this->reorder([$h1]));
        $this->assertSame(ReorderRefusal::QueueChanged, $this->reorder([$h1, $c1, 9999]));
        $this->assertSame(ReorderRefusal::QueueChanged, $this->reorder([$h1, $h1]));
        $this->assertSame([$h1, $c1], $this->order());
    }

    public function test_the_route_answers_json_and_says_why_it_refused(): void
    {
        [$h1, $h2] = $this->queue([[$this->hacking, 1], [$this->hacking, 2]]);

        $this->actingAs($this->user)->postJson(route('training.reorder'), ['order' => [$h2, $h1]])
            ->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'break a requirement'));

        $this->assertSame([$h1, $h2], $this->order());

        [$a, $b] = [$h1, $h2];
        $this->actingAs($this->user)->postJson(route('training.reorder'), ['order' => [$a, $b]])->assertOk();
    }

    public function test_the_screen_offers_a_handle_only_when_there_is_something_to_reorder(): void
    {
        $this->queue([[$this->hacking, 1]]);
        $this->actingAs($this->user)->get(route('skills.index'))->assertOk()->assertDontSee('data-queue-handle', false);

        $this->queue([[$this->crypto, 1]]);
        $this->actingAs($this->user)->get(route('skills.index'))->assertOk()->assertSee('data-queue-handle', false)->assertSee('data-queue-sort', false);
    }
}
