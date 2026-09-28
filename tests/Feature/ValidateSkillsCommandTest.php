<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\TrainingQueueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InjectsSkills;
use Tests\TestCase;

/**
 * Skill definitions live in the database, so they get none of the protection
 * config content gets from tests and review. This command is that replacement,
 * which makes these tests the check on the check.
 */
class ValidateSkillsCommandTest extends TestCase
{
    use InjectsSkills, RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    public function test_a_clean_database_passes(): void
    {
        Skill::factory()->count(3)->create();

        $this->artisan('skills:validate')
            ->expectsOutputToContain('Skills are consistent.')
            ->assertSuccessful();
    }

    public function test_an_empty_database_passes(): void
    {
        $this->artisan('skills:validate')->assertSuccessful();
    }

    /**
     * The reason this command exists. A cycle is a skill nobody can ever learn,
     * and every row in it looks perfectly reasonable on its own.
     */
    public function test_it_catches_a_prerequisite_cycle(): void
    {
        $a = Skill::factory()->create(['slug' => 'alpha']);
        $b = Skill::factory()->create(['slug' => 'beta']);
        $c = Skill::factory()->create(['slug' => 'gamma']);

        SkillPrerequisite::create(['skill_id' => $a->id, 'required_skill_id' => $b->id, 'required_level' => 1]);
        SkillPrerequisite::create(['skill_id' => $b->id, 'required_skill_id' => $c->id, 'required_level' => 1]);
        SkillPrerequisite::create(['skill_id' => $c->id, 'required_skill_id' => $a->id, 'required_level' => 1]);

        $this->artisan('skills:validate')
            ->expectsOutputToContain('cycle:')
            ->assertFailed();
    }

    public function test_a_deep_but_acyclic_chain_passes(): void
    {
        $previous = null;

        foreach (range(1, 6) as $i) {
            $skill = Skill::factory()->create(['slug' => "chain-{$i}"]);

            if ($previous !== null) {
                SkillPrerequisite::create([
                    'skill_id' => $skill->id, 'required_skill_id' => $previous->id, 'required_level' => 3,
                ]);
            }

            $previous = $skill;
        }

        $this->artisan('skills:validate')->assertSuccessful();
    }

    /** A diamond is not a cycle, and must not be reported as one. */
    public function test_a_diamond_dependency_passes(): void
    {
        $root = Skill::factory()->create(['slug' => 'root']);
        $left = Skill::factory()->create(['slug' => 'left']);
        $right = Skill::factory()->create(['slug' => 'right']);
        $top = Skill::factory()->create(['slug' => 'top']);

        foreach ([[$left, $root], [$right, $root], [$top, $left], [$top, $right]] as [$skill, $required]) {
            SkillPrerequisite::create([
                'skill_id' => $skill->id, 'required_skill_id' => $required->id, 'required_level' => 1,
            ]);
        }

        $this->artisan('skills:validate')->assertSuccessful();
    }

    /**
     * attained_level is denormalised. One writer keeps it honest; this is what
     * notices when something else has written.
     */
    public function test_it_catches_a_stored_level_that_disagrees_with_the_points(): void
    {
        $skill = Skill::factory()->rank(1)->create(['slug' => 'cryptography']);

        // Written straight past the model, the way a bad data fix would.
        DB::table('character_skills')->insert([
            'character_id' => $this->character()->id,
            'skill_id' => $skill->id,
            'skill_points' => 6776,      // implies level 3
            'attained_level' => 1,       // but claims level 1
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('skills:validate')
            ->expectsOutputToContain('imply 3')
            ->assertFailed();
    }

    public function test_it_catches_points_above_the_cap(): void
    {
        $skill = Skill::factory()->rank(1)->create();

        DB::table('character_skills')->insert([
            'character_id' => $this->character()->id,
            'skill_id' => $skill->id,
            'skill_points' => 999_999,   // cap is 242,726
            'attained_level' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('skills:validate')
            ->expectsOutputToContain('exceeds the cap')
            ->assertFailed();
    }

    public function test_it_catches_a_gapped_training_queue(): void
    {
        $character = $this->character();

        foreach ([1, 3] as $position) {
            TrainingQueueEntry::create([
                'character_id' => $character->id,
                'skill_id' => Skill::factory()->create()->id,
                'target_level' => 1,
                'position' => $position,
            ]);
        }

        $this->artisan('skills:validate')
            ->expectsOutputToContain('gapless')
            ->assertFailed();
    }

    public function test_a_contiguous_queue_passes(): void
    {
        $character = $this->character();

        foreach ([1, 2, 3] as $position) {
            $skill = Skill::factory()->create();

            // Each imprint spent, each skill on the sheet at level 0. A queue
            // entry for a skill that skipped this is what the next test is.
            $this->inject($character, $skill);

            TrainingQueueEntry::create([
                'character_id' => $character->id,
                'skill_id' => $skill->id,
                'target_level' => 1,
                'position' => $position,
            ]);
        }

        $this->artisan('skills:validate')->assertSuccessful();
    }

    public function test_it_catches_training_queued_for_a_skill_never_injected(): void
    {
        $character = $this->character();

        TrainingQueueEntry::create([
            'character_id' => $character->id,
            'skill_id' => Skill::factory()->create(['slug' => 'unlearned'])->id,
            'target_level' => 1,
            'position' => 1,
        ]);

        $this->artisan('skills:validate')
            ->expectsOutputToContain('never been injected')
            ->assertFailed();
    }

    public function test_it_catches_an_invalid_rank_written_past_the_constraint(): void
    {
        DB::table('skills')->insert([
            'slug' => 'bad-rank', 'title' => 'Bad rank', 'category' => 'test',
            'rank' => 3,   // not one of 1,2,4,8,16
            'primary_attribute' => 'intellect', 'secondary_attribute' => 'memory',
            'base_cost' => 0, 'on_market' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('skills:validate')
            ->expectsOutputToContain('not one of')
            ->assertFailed();
    }

    public function test_it_warns_when_a_skill_governs_on_one_attribute_twice(): void
    {
        Skill::factory()->governedBy('intellect', 'intellect')->create(['slug' => 'lopsided']);

        // A warning, not a failure — unusual rather than invalid.
        $this->artisan('skills:validate')
            ->expectsOutputToContain('same primary and secondary')
            ->assertSuccessful();
    }
}
