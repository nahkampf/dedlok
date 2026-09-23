<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Inventory\Imprint;
use App\Inventory\ItemGranter;
use App\Models\Character;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Skills\QueueRefusal;
use App\Skills\TrainingQueue;
use App\Support\Text;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * Lining up training, and the screen that shows it.
 *
 * TrainingQueueTest covers what makes a queue VALID — it writes rows directly
 * and asks the validator. This covers the only thing that writes them when a
 * player asks: what level an entry gets, what is refused, and what the screen
 * does with the answer.
 *
 * The two halves matter together. A picker that offers an option the writer
 * would refuse is worse than one that offers none, so the screen's list and
 * the writer's arithmetic are asserted against each other here.
 */
final class TrainingQueueScreenTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    private User $user;

    private Character $character;

    private Skill $hacking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['user_id' => $this->user->id, 'archetype' => 3],
        ));

        $this->hacking = Skill::factory()->create(['slug' => 'hacking', 'title' => 'Hacking']);
    }

    private function queue(): TrainingQueue
    {
        return TrainingQueue::make();
    }

    // ---- what level an entry gets ------------------------------------------

    /**
     * A player picks a SKILL and never a level, so the writer has to work out
     * which one — and the only one the validator would accept is the next up.
     */
    public function test_queuing_an_untrained_skill_targets_level_one(): void
    {
        $this->inject($this->character, $this->hacking);

        $entry = $this->queue()->enqueue($this->character, $this->hacking);

        $this->assertInstanceOf(TrainingQueueEntry::class, $entry);
        $this->assertSame(1, $entry->target_level);
        $this->assertSame(1, $entry->position);
        // Not training yet, so there is no clock to keep. TrainingDeposit
        // starts one the first time it finds this entry at position 1.
        $this->assertNull($entry->last_deposited_at);
    }

    public function test_it_continues_from_the_level_already_held(): void
    {
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(3), 3);

        $entry = $this->queue()->enqueue($this->character, $this->hacking);

        $this->assertSame(4, $entry->target_level);
    }

    /**
     * PRESSING IT AGAIN IS HOW A SKILL GETS TAKEN FURTHER. The second entry
     * has to count what the first one already queued, or both would target the
     * same level and collide on the unique (character, skill, level) index.
     */
    public function test_queuing_the_same_skill_twice_stacks_the_levels(): void
    {
        $this->inject($this->character, $this->hacking);

        $first = $this->queue()->enqueue($this->character, $this->hacking);
        $second = $this->queue()->enqueue($this->character, $this->hacking);

        $this->assertSame(1, $first->target_level);
        $this->assertSame(2, $second->target_level);
        $this->assertSame(2, $second->position);
    }

    // ---- what is refused ----------------------------------------------------

    /**
     * The imprint gate, from the other side. A character_skills row is what
     * injecting creates and training can never create, so its absence is the
     * whole check — and never a level check, since level 0 is a real state.
     */
    public function test_a_skill_that_was_never_injected_is_refused(): void
    {
        $result = $this->queue()->enqueue($this->character, $this->hacking);

        $this->assertSame(QueueRefusal::NotInjected, $result);
        $this->assertSame(0, $this->character->trainingQueue()->count());
    }

    public function test_a_skill_with_every_level_reached_is_refused(): void
    {
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(5), 5);

        $this->assertSame(
            QueueRefusal::AlreadyMaxed,
            $this->queue()->enqueue($this->character, $this->hacking)
        );
    }

    /**
     * Every level QUEUED counts as spoken for, even though none of them is
     * reached — otherwise a second press would target a level the first one
     * already claimed, and collide on the unique index.
     *
     * Started at level 3 on purpose: queuing all five levels from scratch
     * fills the queue first, and QueueFull is the truer answer there.
     */
    public function test_a_skill_with_every_remaining_level_queued_is_refused(): void
    {
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(3), 3);

        $this->queue()->enqueue($this->character, $this->hacking);   // 4
        $this->queue()->enqueue($this->character, $this->hacking);   // 5

        $this->assertSame(
            QueueRefusal::AlreadyMaxed,
            $this->queue()->enqueue($this->character, $this->hacking)
        );
        $this->assertSame(2, $this->character->trainingQueue()->count());
    }

    public function test_a_sixth_entry_is_refused(): void
    {
        $this->inject($this->character, $this->hacking);

        for ($i = 0; $i < TrainingQueue::LIMIT; $i++) {
            $this->queue()->enqueue($this->character, $this->hacking);
        }

        $other = Skill::factory()->create();
        $this->inject($this->character, $other);

        $this->assertSame(
            QueueRefusal::QueueFull,
            $this->queue()->enqueue($this->character, $other)
        );
        $this->assertSame(TrainingQueue::LIMIT, $this->character->trainingQueue()->count());
    }

    /**
     * A QUEUE IS ALLOWED TO DESCRIBE TRAINING THAT CANNOT HAPPEN YET. That is
     * the whole point of ordering it — the entry that unlocks a skill sits in
     * front of the entry that needs it — so an unmet prerequisite is a warning
     * on the screen and never a refusal here.
     */
    public function test_an_unmet_prerequisite_does_not_refuse_the_entry(): void
    {
        $locked = Skill::factory()->create(['slug' => 'codebreaking']);
        SkillPrerequisite::create([
            'skill_id' => $locked->id,
            'required_skill_id' => $this->hacking->id,
            'required_level' => 3,
        ]);

        $this->inject($this->character, $locked);

        $this->assertInstanceOf(
            TrainingQueueEntry::class,
            $this->queue()->enqueue($this->character, $locked)
        );
    }

    // ---- taking one out -----------------------------------------------------

    /**
     * (character_id, position) is unique, so closing the gap has to renumber
     * ascending — each row moving into a slot the row before it has vacated.
     */
    public function test_removing_an_entry_closes_the_gap_behind_it(): void
    {
        $this->inject($this->character, $this->hacking);

        $first = $this->queue()->enqueue($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);

        $this->queue()->remove($this->character, $first);

        $this->assertSame(
            [1, 2],
            $this->character->trainingQueue()->pluck('position')->map(fn ($p): int => (int) $p)->all()
        );
        $this->assertSame([2, 3], $this->character->trainingQueue()->pluck('target_level')
            ->map(fn ($l): int => (int) $l)->all());
    }

    public function test_the_screen_will_not_remove_another_characters_entry(): void
    {
        $stranger = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['user_id' => User::factory()->create()->id, 'archetype' => 3],
        ));

        $this->inject($stranger, $this->hacking);
        $theirs = $this->queue()->enqueue($stranger, $this->hacking);

        $this->inject($this->character, $this->hacking);

        $this->actingAs($this->user)
            ->delete(route('training.destroy', $theirs))
            ->assertNotFound();

        $this->assertSame(1, $stranger->trainingQueue()->count());
    }

    // ---- the screen ---------------------------------------------------------

    public function test_the_screen_shows_the_queue_and_what_is_training(): void
    {
        $this->inject($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee('Hacking')
            ->assertSee(Text::get('training.now'))
            ->assertSee(Text::get('training.mine'))
            // The kit's live dot is what says something is running, and there
            // is at most one on the screen because at most one entry trains.
            ->assertSee('dot--live', false);
    }

    /**
     * The whole queue's time, per SKILL rather than per entry: three entries
     * taking one skill from 0 to 3 cost pointsForLevel(3) altogether, and
     * counting each from zero would charge for the same points three times.
     */
    public function test_the_queue_says_how_long_all_of_it_takes(): void
    {
        $this->inject($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);

        $rate = $this->character->trainingRateFor($this->hacking);
        $minutes = (int) ceil($this->hacking->pointsForLevel(3) / $rate);
        $expected = CarbonInterval::minutes($minutes)->cascade()->forHumans(['parts' => 2]);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSeeText(Text::get('training.queue_total', ['duration' => $expected]));
    }

    /** What is training and what is queued reach the same card the list does. */
    public function test_the_training_name_and_the_queue_rows_open_the_card(): void
    {
        $this->inject($this->character, $this->hacking);
        $this->queue()->enqueue($this->character, $this->hacking);

        $html = $this->actingAs($this->user)->get(route('skills.index'))->assertOk()->getContent();

        // Once on the name that is training, once on the queue row, once on
        // the row in the list below — and the template they all point at.
        $this->assertSame(3, substr_count($html, 'data-inspect="#skill-'.$this->hacking->id.'"'));
        $this->assertStringContainsString('<template id="skill-'.$this->hacking->id.'">', $html);
    }

    /**
     * Each known skill carries its whole card in a template: requirements,
     * effects in words, the book, and the level being earned breathing.
     */
    public function test_every_known_skill_has_its_card(): void
    {
        $routing = Skill::factory()->create(['slug' => 'routing', 'title' => 'Routing']);
        SkillPrerequisite::query()->create([
            'skill_id' => $this->hacking->id,
            'required_skill_id' => $routing->id,
            'required_level' => 2,
        ]);
        $this->hacking->forceFill(['modifiers' => [
            ['op' => 'add', 'amount' => 1, 'level' => 1, 'target' => ['id' => 'routing_hops']],
        ]])->save();

        $this->inject($this->character, $this->hacking);
        ItemGranter::grant($this->character, Imprint::ensureFor($this->hacking));
        $this->queue()->enqueue($this->character, $this->hacking);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee('data-inspect="#skill-'.$this->hacking->id.'"', false)
            ->assertSeeText('Routing')
            ->assertSeeText('+1 '.Text::get('character.names.routing_hops'))
            ->assertSeeText(Text::get('training.card.book_owned'))
            ->assertSeeText(Text::get('training.card.injected'))
            ->assertSee('meter__next', false);
    }

    public function test_an_idle_character_is_told_nothing_is_training(): void
    {
        $this->inject($this->character, $this->hacking);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertDontSee('dot--live', false);
    }

    /**
     * The validator's findings reach the player. Until this screen existed
     * they were only ever printed by `skills:validate`, which nobody playing
     * the game ever runs.
     */
    public function test_a_queue_that_cannot_train_says_so_on_the_screen(): void
    {
        $locked = Skill::factory()->create(['slug' => 'codebreaking', 'title' => 'Codebreaking']);
        SkillPrerequisite::create([
            'skill_id' => $locked->id,
            'required_skill_id' => $this->hacking->id,
            'required_level' => 3,
        ]);

        $this->inject($this->character, $locked);
        $this->queue()->enqueue($this->character, $locked);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee(Text::get('training.problems'))
            ->assertSee('codebreaking')
            ->assertSee('hacking');
    }

    /**
     * The picker and the writer do the same arithmetic, so an option that
     * would be refused is never offered. Asserted by the absence of the form
     * altogether once there is nothing left to queue.
     */
    public function test_a_maxed_skill_is_not_offered(): void
    {
        $this->inject($this->character, $this->hacking, $this->hacking->pointsForLevel(5), 5);

        $this->actingAs($this->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertDontSee('name="skill"', false);
    }

    /**
     * The screen is somebody's own, so there is nothing on it for a guest.
     * It was the public catalogue once and is not one any more — a list of
     * every skill in the game is what the skill editor is for.
     */
    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('skills.index'))->assertRedirect(route('login'));
    }

    public function test_queuing_through_the_screen(): void
    {
        $this->inject($this->character, $this->hacking);

        $this->actingAs($this->user)
            ->post(route('training.store'), ['skill' => $this->hacking->id])
            ->assertRedirect();

        $this->assertSame(1, $this->character->trainingQueue()->count());
    }

    public function test_the_screen_refuses_an_uninjected_skill_with_a_sentence(): void
    {
        $this->actingAs($this->user)
            ->post(route('training.store'), ['skill' => $this->hacking->id])
            ->assertSessionHas('error');

        $this->assertSame(0, $this->character->trainingQueue()->count());
    }
}
