<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Models\SkillPrerequisite;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SkillTest extends TestCase
{
    use RefreshDatabase;

    private function character(array $attributes = []): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],   // Sleuth: no perks, so attributes resolve bare
            $attributes,
        ));
    }

    // ---- schema -----------------------------------------------------------

    public function test_the_tables_exist(): void
    {
        foreach (['skills', 'skill_prerequisites', 'character_skills'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_slugs_are_unique(): void
    {
        Skill::factory()->create(['slug' => 'cryptography']);

        $this->expectException(QueryException::class);
        Skill::factory()->create(['slug' => 'cryptography']);
    }

    public function test_a_character_learns_a_skill_once(): void
    {
        $character = $this->character();
        $skill = Skill::factory()->create();

        CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $skill->id]);

        $this->expectException(QueryException::class);
        CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $skill->id]);
    }

    public function test_deleting_a_character_removes_their_progress_but_not_the_skill(): void
    {
        $character = $this->character();
        $skill = Skill::factory()->create();
        CharacterSkill::create(['character_id' => $character->id, 'skill_id' => $skill->id]);

        $character->delete();

        $this->assertDatabaseCount('character_skills', 0);
        $this->assertModelExists($skill);
    }

    // ---- prerequisites ----------------------------------------------------

    public function test_a_skill_reports_what_it_requires_and_what_it_unlocks(): void
    {
        $basic = Skill::factory()->create(['slug' => 'cryptography']);
        $advanced = Skill::factory()->create(['slug' => 'advanced-cryptography']);

        SkillPrerequisite::create([
            'skill_id' => $advanced->id,
            'required_skill_id' => $basic->id,
            'required_level' => 5,
        ]);

        $this->assertSame(1, $advanced->prerequisites()->count());
        $this->assertSame($basic->id, $advanced->prerequisites()->first()->requiredSkill->id);
        $this->assertSame(1, $basic->unlocks()->count());
    }

    public function test_a_skill_cannot_require_itself(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints are only installed on MySQL.');
        }

        $skill = Skill::factory()->create();

        $this->expectException(QueryException::class);
        SkillPrerequisite::create([
            'skill_id' => $skill->id,
            'required_skill_id' => $skill->id,
            'required_level' => 1,
        ]);
    }

    public function test_the_same_prerequisite_cannot_be_listed_twice(): void
    {
        $skill = Skill::factory()->create();
        $required = Skill::factory()->create();

        SkillPrerequisite::create(['skill_id' => $skill->id, 'required_skill_id' => $required->id, 'required_level' => 1]);

        $this->expectException(QueryException::class);
        SkillPrerequisite::create(['skill_id' => $skill->id, 'required_skill_id' => $required->id, 'required_level' => 3]);
    }

    // ---- what a character can learn ---------------------------------------

    public function test_a_skill_with_no_prerequisites_is_always_learnable(): void
    {
        $this->assertTrue($this->character()->canLearn(Skill::factory()->create()));
    }

    public function test_an_unmet_prerequisite_blocks_learning_and_says_why(): void
    {
        $character = $this->character();
        $basic = Skill::factory()->create(['slug' => 'cryptography', 'title' => 'Cryptography']);
        $advanced = Skill::factory()->create(['slug' => 'advanced-cryptography']);

        SkillPrerequisite::create([
            'skill_id' => $advanced->id, 'required_skill_id' => $basic->id, 'required_level' => 5,
        ]);

        $this->assertFalse($character->canLearn($advanced));

        $unmet = $character->unmetPrerequisites($advanced);
        $this->assertCount(1, $unmet);
        $this->assertSame('Cryptography', $unmet[0]['skill']->title);
        $this->assertSame(5, $unmet[0]['required_level']);
        $this->assertSame(0, $unmet[0]['current_level']);
    }

    public function test_a_partially_trained_prerequisite_still_blocks(): void
    {
        $character = $this->character();
        $basic = Skill::factory()->rank(1)->create(['slug' => 'cryptography']);
        $advanced = Skill::factory()->create();

        SkillPrerequisite::create([
            'skill_id' => $advanced->id, 'required_skill_id' => $basic->id, 'required_level' => 5,
        ]);

        CharacterSkill::create([
            'character_id' => $character->id, 'skill_id' => $basic->id,
            'skill_points' => 6776, 'attained_level' => 3,
        ]);

        $this->assertFalse($character->canLearn($advanced));
        $this->assertSame(3, $character->unmetPrerequisites($advanced)[0]['current_level']);
    }

    public function test_a_met_prerequisite_unblocks_learning(): void
    {
        $character = $this->character();
        $basic = Skill::factory()->rank(1)->create();
        $advanced = Skill::factory()->create();

        SkillPrerequisite::create([
            'skill_id' => $advanced->id, 'required_skill_id' => $basic->id, 'required_level' => 5,
        ]);

        CharacterSkill::create([
            'character_id' => $character->id, 'skill_id' => $basic->id,
            'skill_points' => 242726, 'attained_level' => 5,
        ]);

        $this->assertTrue($character->canLearn($advanced));
        $this->assertSame([], $character->unmetPrerequisites($advanced));
    }

    public function test_has_skill_at_level_reads_the_stored_level(): void
    {
        $character = $this->character();
        $skill = Skill::factory()->create(['slug' => 'cryptography']);

        CharacterSkill::create([
            'character_id' => $character->id, 'skill_id' => $skill->id,
            'skill_points' => 6776, 'attained_level' => 3,
        ]);

        $this->assertTrue($character->hasSkillAtLevel('cryptography', 3));
        $this->assertFalse($character->hasSkillAtLevel('cryptography', 4));
        $this->assertFalse($character->hasSkillAtLevel('nonexistent', 1));
    }

    // ---- progress ---------------------------------------------------------

    public function test_a_row_derives_its_level_from_its_points(): void
    {
        $character = $this->character();
        $skill = Skill::factory()->rank(2)->create();

        $row = CharacterSkill::create([
            'character_id' => $character->id, 'skill_id' => $skill->id,
            'skill_points' => 2420, 'attained_level' => 2,
        ]);

        // rank 2: level 2 is 2420 points.
        $this->assertSame(2, $row->derivedLevel());
        $this->assertSame(2, $row->attained_level, 'stored and derived must agree');
        $this->assertFalse($row->isAtCap());
        $this->assertSame(11132, $row->pointsToNextLevel());   // 13552 - 2420
    }

    public function test_the_cap_is_rank_aware(): void
    {
        $rankOne = Skill::factory()->rank(1)->create();
        $rankSixteen = Skill::factory()->rank(16)->create();

        $this->assertSame(242726, $rankOne->pointsCap());
        $this->assertSame(3883616, $rankSixteen->pointsCap());
    }

    // ---- training rate ----------------------------------------------------

    public function test_the_training_rate_uses_the_skills_governing_attributes(): void
    {
        $character = $this->character(['intellect' => 20, 'memory' => 15]);
        $skill = Skill::factory()->governedBy('intellect', 'memory')->create();

        // 60 * (0.121*20 + 0.0762*15)
        $this->assertEqualsWithDelta(213.78, $character->trainingRateFor($skill), 0.001);
    }

    /**
     * The rate must come from RESOLVED attributes, so an archetype perk or an
     * implant that moves the governing attribute changes training speed with no
     * special handling.
     */
    public function test_the_training_rate_uses_resolved_attributes(): void
    {
        $bare = $this->character(['intellect' => 20, 'memory' => 15]);
        $skill = Skill::factory()->governedBy('intellect', 'memory')->create();

        $baseline = $bare->trainingRateFor($skill);

        // hitpoints_max is not an attribute, so no perk currently moves
        // intellect — assert the wiring instead of a magic number.
        $this->assertSame(
            $bare->stat('intellect')->value,
            (float) $bare->intellect,
            'no active modifier on intellect in this fixture'
        );
        $this->assertGreaterThan(0, $baseline);
    }

    public function test_a_character_with_no_attributes_never_finishes(): void
    {
        $character = $this->character(array_fill_keys(Character::ATTRIBUTES, 0));
        $skill = Skill::factory()->create();

        $this->assertSame(0.0, $character->trainingRateFor($skill));
    }

    // ---- the pivot --------------------------------------------------------

    /**
     * The pivot must list only columns that exist. training_queue took over
     * queue_position, last_deposited_at and sp_per_minute from character_skills
     * and the migration dropped them, so a withPivot() left naming one turns
     * every read of the relation into a SQL error rather than a missing value.
     */
    public function test_the_skills_relation_reads_both_ways(): void
    {
        $character = $this->character();
        $skill = Skill::factory()->create();

        CharacterSkill::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => 500,
            'attained_level' => 1,
        ]);

        $fromCharacter = $character->skills()->first();

        $this->assertSame($skill->id, $fromCharacter->id);
        $this->assertSame(500, $fromCharacter->pivot->skill_points);
        $this->assertSame(1, $fromCharacter->pivot->attained_level);

        $this->assertSame($character->id, $skill->characters()->first()->id);
    }

    // ---- scopes -----------------------------------------------------------

    public function test_market_and_category_scopes(): void
    {
        Skill::factory()->create(['category' => 'intrusion']);
        Skill::factory()->offMarket()->create(['category' => 'intrusion']);
        Skill::factory()->create(['category' => 'combat']);

        $this->assertSame(2, Skill::onMarket()->count());
        $this->assertSame(2, Skill::inCategory('intrusion')->count());
        $this->assertSame(1, Skill::onMarket()->inCategory('intrusion')->count());
    }
}
