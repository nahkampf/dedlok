<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Skills\TrainingPlanner;
use App\Skills\TrainingQueue;
use App\Skills\TrainingQueueValidator;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * Working backwards from a skill somebody wants to the entries that reach it.
 *
 * The queue is the same queue — every entry still goes through
 * TrainingQueue::enqueue(), and everything TrainingQueueScreenTest pins about
 * what a single press does is unchanged. What is asserted here is the ORDER and
 * the COUNT, and the two things that make a plan a partial answer rather than a
 * failure: the queue holds five, and a book that was never spent is a wall.
 */
final class TrainingPlanTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    private User $user;

    private Character $character;

    private Skill $hacking;

    private Skill $cryptography;

    private Skill $codebreaking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));

        $this->hacking = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);
        $this->cryptography = Skill::factory()->create(['slug' => 'cryptography', 'title' => 'Cryptography']);
        $this->codebreaking = Skill::factory()->create(['slug' => 'codebreaking', 'title' => 'Codebreaking']);

        // The example the design keeps using: Codebreaking sits behind Hacking
        // III and Cryptography V, so the route to its first level is nine
        // entries and nobody should have to work that out by hand.
        SkillPrerequisite::create([
            'skill_id' => $this->codebreaking->id,
            'required_skill_id' => $this->hacking->id,
            'required_level' => 3,
        ]);
        SkillPrerequisite::create([
            'skill_id' => $this->codebreaking->id,
            'required_skill_id' => $this->cryptography->id,
            'required_level' => 5,
        ]);
    }

    private function planner(): TrainingPlanner
    {
        return TrainingPlanner::make();
    }

    private function injected(Skill ...$skills): void
    {
        foreach ($skills as $skill) {
            $this->inject($this->character, $skill);
        }
    }

    /** @return list<string> one slug per entry, in queue order */
    private function slugs(array $steps): array
    {
        return array_map(static fn (Skill $skill): string => $skill->slug, $steps);
    }

    // ---- what a plan contains ----------------------------------------------

    /**
     * PREREQUISITES FIRST, THEN THE SKILL'S OWN LEVELS, and one entry per
     * level — which is what an entry is.
     */
    public function test_a_plan_puts_the_prerequisites_in_front(): void
    {
        $this->injected($this->hacking, $this->cryptography, $this->codebreaking);

        $steps = $this->planner()->plan($this->character, $this->codebreaking, 1);

        $this->assertSame([
            'hacking', 'hacking', 'hacking',
            'cryptography', 'cryptography', 'cryptography', 'cryptography', 'cryptography',
            'codebreaking',
        ], $this->slugs($steps));
    }

    /** What is already known is not planned again. */
    public function test_it_plans_only_what_is_missing(): void
    {
        $this->injected($this->cryptography, $this->codebreaking);
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(3), 3);

        $steps = $this->planner()->plan($this->character, $this->codebreaking, 1);

        $this->assertSame(6, count($steps), 'Hacking is already there');
        $this->assertNotContains('hacking', $this->slugs($steps));
    }

    /**
     * THE PLAN IS WHAT TO ADD, so it counts what is queued as well as what is
     * reached — the queue is what it is being appended to.
     */
    public function test_it_counts_what_is_already_queued(): void
    {
        $this->injected($this->hacking);

        TrainingQueue::make()->enqueue($this->character, $this->hacking);
        TrainingQueue::make()->enqueue($this->character, $this->hacking);

        $steps = $this->planner()->plan($this->character, $this->hacking, 3);

        $this->assertSame(['hacking'], $this->slugs($steps), 'levels I and II are spoken for');
    }

    public function test_a_plan_with_nothing_left_to_do_is_empty(): void
    {
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(3), 3);

        $this->assertSame([], $this->planner()->plan($this->character, $this->hacking, 2));
    }

    /**
     * THE PLAN IS JUDGED BY THE THING THAT JUDGES A HAND-BUILT QUEUE. Ordering
     * entries so every one of them can train is exactly what
     * TrainingQueueValidator checks, so a plan that satisfies it needs no
     * second opinion of its own.
     */
    public function test_the_queue_a_plan_builds_has_nothing_wrong_with_it(): void
    {
        $scripting = Skill::factory()->create(['slug' => 'scripting', 'title' => 'Scripting']);

        SkillPrerequisite::create([
            'skill_id' => $scripting->id,
            'required_skill_id' => $this->hacking->id,
            'required_level' => 1,
        ]);

        $this->injected($this->hacking, $scripting);

        foreach ($this->planner()->plan($this->character, $scripting, 2) as $step) {
            TrainingQueue::make()->enqueue($this->character, $step);
        }

        $this->assertSame(
            [['hacking', 1], ['scripting', 1], ['scripting', 2]],
            $this->character->trainingQueue()->with('skill')->get()
                ->map(fn (TrainingQueueEntry $entry): array => [$entry->skill->slug, $entry->target_level])
                ->all(),
        );

        $this->assertSame([], (new TrainingQueueValidator)->problemsFor($this->character->fresh()));
    }

    // ---- what the route does with it ---------------------------------------

    /**
     * AS FAR AS IT GOT IS THE ORDINARY OUTCOME. Nine entries into a queue that
     * holds five is not a failure, and the queue already has the words for why
     * it stopped.
     */
    public function test_the_route_queues_as_much_as_fits_and_says_what_stopped_it(): void
    {
        $this->injected($this->hacking, $this->cryptography, $this->codebreaking);

        $this->actingAs($this->user)
            ->from(route('skills.index'))
            ->post(route('training.plan'), ['skill' => $this->codebreaking->id, 'level' => 1])
            ->assertRedirect(route('skills.index'))
            ->assertSessionHas('status', Text::get('training.plan_stopped', [
                'count' => TrainingQueue::LIMIT,
                'total' => 9,
                'skill' => 'Cryptography',
                'reason' => Text::get('training.refused.queue_full'),
            ]));

        $this->assertSame(TrainingQueue::LIMIT, $this->character->trainingQueue()->count());
    }

    /**
     * A BOOK THAT WAS NEVER SPENT IS A WALL, and being told which one is the
     * whole value of asking — a plan that quietly routed around it would
     * describe a route that does not exist.
     */
    public function test_a_missing_book_stops_the_plan_where_it_stands(): void
    {
        $this->injected($this->hacking, $this->codebreaking);

        $this->actingAs($this->user)
            ->from(route('skills.index'))
            ->post(route('training.plan'), ['skill' => $this->codebreaking->id, 'level' => 1])
            ->assertSessionHas('status', Text::get('training.plan_needs_imprint', [
                'count' => 3,
                'total' => 9,
                'skill' => 'Cryptography',
                'target' => 'Codebreaking',
            ]));

        // What went in before the wall stays in: a partial route is still the
        // beginning of the route.
        $this->assertSame(3, $this->character->trainingQueue()->count());
    }

    public function test_a_plan_that_adds_nothing_says_so(): void
    {
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(4), 4);

        $this->actingAs($this->user)
            ->from(route('skills.index'))
            ->post(route('training.plan'), ['skill' => $this->hacking->id, 'level' => 2])
            ->assertSessionHas('status', Text::get('training.plan_nothing', [
                'skill' => 'Hacking',
                'level' => 2,
            ]));

        $this->assertSame(0, $this->character->trainingQueue()->count());
    }

    public function test_a_level_outside_the_progression_is_refused(): void
    {
        $this->injected($this->hacking);

        $this->actingAs($this->user)
            ->from(route('skills.index'))
            ->post(route('training.plan'), ['skill' => $this->hacking->id, 'level' => 99])
            ->assertSessionHasErrors('level');

        $this->assertSame(0, $this->character->trainingQueue()->count());
    }

    public function test_the_screen_offers_the_picker(): void
    {
        $this->injected($this->hacking);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee(route('training.plan'))
            ->assertSeeText(Text::get('training.plan_pick'))
            ->assertSeeText('Hacking');
    }
}
