<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\TrainingQueueEntry;
use App\Skills\TrainingQueueValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * A queue entry is a (skill, target level) PAIR. The same skill appears once
 * per level being trained, and entries from different skills interleave.
 */
class TrainingQueueTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    private Character $character;

    private Skill $hacking;

    private Skill $cryptography;

    private Skill $codebreaking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        $this->hacking = Skill::factory()->create(['slug' => 'hacking']);
        $this->cryptography = Skill::factory()->create(['slug' => 'cryptography']);
        $this->codebreaking = Skill::factory()->create(['slug' => 'codebreaking']);

        // Codebreaking needs Hacking III and Cryptography V.
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

    private function knows(Skill $skill, int $level): void
    {
        $this->inject($this->character, $skill, $skill->pointsForLevel($level), $level);
    }

    /**
     * Injected but untrained: level 0, no points.
     *
     * The state every skill starts in once its book is spent, and the state a
     * queue entry needs before it may name that skill at all.
     */
    private function injected(Skill ...$skills): void
    {
        foreach ($skills as $skill) {
            if (! $this->character->fresh()->hasInjected($skill)) {
                $this->inject($this->character, $skill);
            }
        }
    }

    /**
     * @param  list<array{0: Skill, 1: int}>  $entries
     *
     * Injects every skill named, unless the test is specifically about the
     * uninjected case — building a queue for a skill with no book spent is
     * exactly what the validator now refuses.
     */
    private function queue(array $entries, bool $inject = true): void
    {
        if ($inject) {
            $this->injected(...array_column($entries, 0));
        }

        foreach ($entries as $position => [$skill, $level]) {
            TrainingQueueEntry::create([
                'character_id' => $this->character->id,
                'skill_id' => $skill->id,
                'target_level' => $level,
                'position' => $position + 1,
            ]);
        }
    }

    private function problems(): array
    {
        return (new TrainingQueueValidator)->problemsFor($this->character->fresh());
    }

    // ---- the worked example ----------------------------------------------

    /**
     * Already holding Cryptography 3, queueing toward Codebreaking I:
     *
     *   1. Hacking I      4. Cryptography IV
     *   2. Hacking II     5. Cryptography V
     *   3. Hacking III    6. Codebreaking I
     */
    public function test_the_worked_example_is_a_valid_queue(): void
    {
        $this->knows($this->cryptography, 3);

        $this->queue([
            [$this->hacking, 1],
            [$this->hacking, 2],
            [$this->hacking, 3],
            [$this->cryptography, 4],
            [$this->cryptography, 5],
            [$this->codebreaking, 1],
        ]);

        $this->assertSame([], $this->problems());
        $this->assertSame(6, $this->character->trainingQueue()->count());
    }

    public function test_the_same_skill_occupies_several_entries(): void
    {
        $this->queue([[$this->hacking, 1], [$this->hacking, 2], [$this->hacking, 3]]);

        $this->assertSame(
            [1, 2, 3],
            $this->character->trainingQueue()->pluck('target_level')->all(),
            'one entry per level of the same skill'
        );
    }

    public function test_position_one_is_what_is_training(): void
    {
        $this->queue([[$this->hacking, 1], [$this->cryptography, 4]]);

        $training = $this->character->training();

        $this->assertNotNull($training);
        $this->assertSame('hacking', $training->skill->slug);
        $this->assertTrue($training->isActive());
    }

    public function test_a_character_with_an_empty_queue_trains_nothing(): void
    {
        $this->assertNull($this->character->training());
        $this->assertSame([], $this->problems());
    }

    // ---- rules that only the whole sequence can show ----------------------

    public function test_prerequisites_are_judged_at_the_point_the_entry_starts(): void
    {
        // Codebreaking alone is invalid: nothing unlocks it.
        $this->knows($this->cryptography, 3);
        $this->queue([[$this->codebreaking, 1]]);

        $problems = $this->problems();

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('hacking', implode(' ', $problems));
    }

    public function test_an_unlocking_entry_earlier_in_the_queue_makes_it_valid(): void
    {
        $this->knows($this->cryptography, 4);

        // Cryptography V at position 1 satisfies Codebreaking's requirement.
        $this->queue([
            [$this->cryptography, 5],
            [$this->hacking, 1],
            [$this->hacking, 2],
            [$this->hacking, 3],
            [$this->codebreaking, 1],
        ]);

        $this->assertSame([], $this->problems());
    }

    public function test_the_same_entries_in_the_wrong_order_are_invalid(): void
    {
        $this->knows($this->cryptography, 4);

        // Codebreaking first, before anything unlocks it.
        $this->queue([
            [$this->codebreaking, 1],
            [$this->cryptography, 5],
            [$this->hacking, 1],
            [$this->hacking, 2],
            [$this->hacking, 3],
        ]);

        $this->assertNotEmpty($this->problems(), 'order is what makes a queue valid');
    }

    public function test_levels_cannot_be_skipped(): void
    {
        $this->queue([[$this->hacking, 1], [$this->hacking, 3]]);

        $problems = $this->problems();

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('level 2 is not queued before it', implode(' ', $problems));
    }

    public function test_a_level_already_held_cannot_be_queued(): void
    {
        $this->knows($this->cryptography, 3);
        $this->queue([[$this->cryptography, 2]]);

        $this->assertStringContainsString('already reached', implode(' ', $this->problems()));
    }

    public function test_it_continues_from_the_level_already_held(): void
    {
        $this->knows($this->cryptography, 3);

        $this->queue([[$this->cryptography, 4], [$this->cryptography, 5]]);

        $this->assertSame([], $this->problems(), 'levels 1-3 are not queued because they are held');
    }

    // ---- constraints ------------------------------------------------------

    public function test_two_entries_cannot_share_a_position(): void
    {
        $this->queue([[$this->hacking, 1]]);

        $this->expectException(QueryException::class);
        TrainingQueueEntry::create([
            'character_id' => $this->character->id,
            'skill_id' => $this->cryptography->id,
            'target_level' => 4,
            'position' => 1,
        ]);
    }

    public function test_the_same_level_cannot_be_queued_twice(): void
    {
        $this->queue([[$this->hacking, 1]]);

        $this->expectException(QueryException::class);
        TrainingQueueEntry::create([
            'character_id' => $this->character->id,
            'skill_id' => $this->hacking->id,
            'target_level' => 1,
            'position' => 2,
        ]);
    }

    public function test_a_gapped_queue_is_reported(): void
    {
        TrainingQueueEntry::create([
            'character_id' => $this->character->id, 'skill_id' => $this->hacking->id,
            'target_level' => 1, 'position' => 1,
        ]);
        TrainingQueueEntry::create([
            'character_id' => $this->character->id, 'skill_id' => $this->cryptography->id,
            'target_level' => 4, 'position' => 3,
        ]);

        $this->assertStringContainsString('gapless', implode(' ', $this->problems()));
    }

    public function test_deleting_a_character_clears_their_queue(): void
    {
        $this->queue([[$this->hacking, 1]]);

        $this->character->delete();

        $this->assertDatabaseCount('training_queue', 0);
    }

    public function test_the_validate_command_reports_a_bad_queue(): void
    {
        $this->queue([[$this->hacking, 1], [$this->hacking, 3]]);

        $this->artisan('skills:validate')
            ->expectsOutputToContain('is not queued before it')
            ->assertFailed();
    }
}
