<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Stats\Sources\SkillEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What HAVING a skill grants, beyond the check it feeds.
 *
 * The gate is the same one training uses and always has been: a
 * `character_skills` row means the skill is injected. No level test, no
 * permission check — the rows that exist are the skills you have.
 */
final class SkillEffectTest extends TestCase
{
    use RefreshDatabase;

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    private function skillGranting(array $modifiers): Skill
    {
        return Skill::factory()->rank(4)->governedBy('intellect', 'memory')
            ->create(['slug' => 'granting_skill', 'modifiers' => $modifiers]);
    }

    private function inject(Character $character, Skill $skill, int $level): void
    {
        CharacterSkill::query()->create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => 0,
            'attained_level' => $level,
        ]);
    }

    public function test_a_skill_you_do_not_have_grants_nothing(): void
    {
        $character = $this->character();
        $this->skillGranting([
            ['level' => 1, 'op' => 'add', 'amount' => 5, 'target' => ['id' => 'memory']],
        ]);

        $this->assertSame([], (new SkillEffects($character))->modifiers());
    }

    public function test_an_injected_skill_grants_from_its_level_upward(): void
    {
        $character = $this->character();
        $skill = $this->skillGranting([
            ['level' => 3, 'op' => 'add', 'amount' => 5, 'target' => ['id' => 'memory']],
        ]);

        $this->inject($character, $skill, 2);
        $this->assertSame([], (new SkillEffects($character->fresh()))->modifiers(), 'not yet');

        CharacterSkill::query()->where('character_id', $character->id)->update(['attained_level' => 3]);
        $this->assertCount(1, (new SkillEffects($character->fresh()))->modifiers(), 'at the threshold');
    }

    /** Level 0 is injected-and-untrained, which is a reasonable thing to reward. */
    public function test_a_grant_with_no_level_applies_as_soon_as_it_is_injected(): void
    {
        $character = $this->character();
        $skill = $this->skillGranting([
            ['op' => 'add', 'amount' => 5, 'target' => ['id' => 'memory']],
        ]);

        $this->inject($character, $skill, 0);

        $this->assertCount(1, (new SkillEffects($character->fresh()))->modifiers());
    }

    public function test_a_grant_reaches_the_resolver(): void
    {
        $character = $this->character();
        $before = $character->stat('memory')->value;

        $skill = $this->skillGranting([
            ['level' => 1, 'op' => 'add', 'amount' => 7, 'target' => ['id' => 'memory']],
        ]);
        $this->inject($character, $skill, 1);

        $this->assertSame($before + 7, $character->fresh()->stat('memory')->value);
    }

    /** The breakdown names the skill, not the row. */
    public function test_a_grant_is_attributed_to_the_skill(): void
    {
        $character = $this->character();
        $skill = $this->skillGranting([
            ['level' => 1, 'op' => 'add', 'amount' => 5, 'target' => ['id' => 'memory']],
        ]);
        $this->inject($character, $skill, 1);

        $this->assertSame($skill->title, (new SkillEffects($character->fresh()))->modifiers()[0]->source);
    }

    public function test_a_skill_granting_nothing_is_ordinary(): void
    {
        $character = $this->character();
        $skill = Skill::factory()->rank(4)->governedBy('intellect', 'memory')
            ->create(['slug' => 'plain_skill']);
        $this->inject($character, $skill, 5);

        $this->assertNull($skill->modifiers);
        $this->assertSame([], (new SkillEffects($character->fresh()))->modifiers());
    }

    /** Several thresholds on one skill all apply once passed. */
    public function test_thresholds_accumulate_as_the_level_rises(): void
    {
        $character = $this->character();
        $skill = $this->skillGranting([
            ['level' => 2, 'op' => 'add', 'amount' => 1, 'target' => ['id' => 'memory']],
            ['level' => 4, 'op' => 'add', 'amount' => 1, 'target' => ['id' => 'memory']],
        ]);
        $this->inject($character, $skill, 5);

        $this->assertCount(2, (new SkillEffects($character->fresh()))->modifiers());
    }

    // ---- the shipped catalogue --------------------------------------------

    /**
     * Every authored grant has to be a modifier the resolver will accept. A
     * typo in a target id or an op is a 500 on the character sheet, and this
     * is the only thing standing between the two.
     */
    public function test_every_shipped_grant_is_a_valid_modifier(): void
    {
        $granting = Skill::query()->whereNotNull('modifiers')->get();

        // Skills are AUTHORED CONTENT, written in the admin editor and living
        // in the database — no migration ships them, so a fresh test database
        // has none and there is nothing here to check. The real catalogue is
        // validated by `skills:validate`, which runs against the database that
        // actually holds it.
        if ($granting->isEmpty()) {
            $this->markTestSkipped('no skill in this database grants anything');
        }

        foreach ($granting as $skill) {
            $character = $this->character();
            $this->inject($character, $skill, 5);

            $modifiers = (new SkillEffects($character->fresh()))->modifiers();

            $this->assertNotEmpty($modifiers, "{$skill->slug} grants nothing at level 5");

            foreach ($skill->modifiers as $grant) {
                $this->assertArrayHasKey('level', $grant, "{$skill->slug} has a grant with no level");
                $this->assertGreaterThanOrEqual(0, $grant['level']);
                $this->assertLessThanOrEqual(5, $grant['level'], "{$skill->slug} grants past the ceiling");
            }
        }
    }

    /** Thermal Management is the clearest case, so it is the one pinned. */
    public function test_thermal_management_lowers_what_a_deck_runs_at(): void
    {
        $skill = Skill::query()->where('slug', 'thermal_management')->first();

        if ($skill === null) {
            $this->markTestSkipped('thermal_management is not in this database');
        }

        $character = $this->character();
        $this->inject($character, $skill, 5);

        $modifiers = (new SkillEffects($character->fresh()))->modifiers();
        $thermal = array_filter(
            $modifiers,
            static fn ($m): bool => $m->target->id === 'deck_thermal_load'
        );

        $this->assertNotEmpty($thermal);

        foreach ($thermal as $modifier) {
            $this->assertLessThan(0, $modifier->amount, 'the skill must lower it, not raise it');
        }
    }
}
