<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Stats\Context;
use App\Stats\Modifier;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use App\Stats\StatRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The check layer, end to end.
 *
 * The first test here is the one this whole phase exists for: before the
 * dependency graph, a derived stat was computed from RAW columns, so an
 * implant granting +10 Focus raised Focus everywhere it was shown and moved
 * maximum HP by nothing at all. The arithmetic was right and the input was
 * wrong, and nothing could report it.
 */
final class StatSheetTest extends TestCase
{
    use RefreshDatabase;

    /** Fixer: no perks at all, so these are bare formulas. */
    private const NO_PERKS = 3;

    protected function setUp(): void
    {
        parent::setUp();

        SkillStats::forget();
        Sheet::forgetSkills();
    }

    private function character(array $attributes = []): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => self::NO_PERKS],
            $attributes,
        ));
    }

    // ---- the bug this phase closed --------------------------------------

    /**
     * HP = meat + ceil(muscle/2) + ceil(focus/2). A modifier on Focus has to
     * reach the ceiling that is half of Focus.
     */
    public function test_a_modifier_on_an_attribute_reaches_the_stat_derived_from_it(): void
    {
        $character = $this->character(['meat' => 20, 'muscle' => 20, 'focus' => 20]);

        $this->assertSame(40.0, $character->stat('hitpoints_max')->value, 'baseline: 20 + 10 + 10');

        $boosted = Sheet::for($character, Context::empty(), [
            Modifier::fromArray([
                'op' => 'add',
                'amount' => 10,
                'target' => ['id' => 'focus'],
                'source' => 'Cortex booster',
            ]),
        ]);

        $this->assertSame(30.0, $boosted->value('focus'));
        $this->assertSame(
            45.0,
            $boosted->value('hitpoints_max'),
            '+10 Focus is +5 max HP, because max HP is half of Focus'
        );
    }

    // ---- skills as stats -------------------------------------------------

    public function test_a_skill_is_a_stat_whose_base_is_the_trained_level(): void
    {
        $skill = Skill::factory()->create(['slug' => 'ghostwalk', 'rank' => 1]);
        $character = $this->character();

        $this->assertSame(
            0.0,
            $character->stat(SkillStats::skillId('ghostwalk'))->value,
            'a skill nobody has injected resolves to zero'
        );

        CharacterSkill::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => 0,
            'attained_level' => 3,
        ]);

        $this->assertSame(3.0, $character->fresh()->stat(SkillStats::skillId('ghostwalk'))->value);
    }

    /**
     * Zero is what an uninjected skill and an injected-but-untrained skill both
     * resolve to, and that is fine: the gate is hasInjected() and never a level.
     * Reading a stat as permission would let anyone attempt anything.
     */
    public function test_a_check_resolves_for_a_skill_the_character_has_never_injected(): void
    {
        $skill = Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'coldread']);
        $character = $this->character(['perception' => 40, 'memory' => 30]);

        // 0.5*40 + 0.25*30 + 8*0 = 27.5, unrounded.
        $this->assertSame(27.5, $character->stat(SkillStats::checkId('coldread'))->value);
        $this->assertFalse($character->hasInjected($skill), 'the gate is still the row, not the number');
    }

    public function test_a_check_combines_both_attributes_and_the_level(): void
    {
        $skill = Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'probe', 'rank' => 1]);
        $character = $this->character(['perception' => 40, 'memory' => 30]);

        CharacterSkill::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => 0,
            'attained_level' => 3,
        ]);

        // 0.5*40 + 0.25*30 + 8*3 = 20 + 7.5 + 24 = 51.5, unrounded.
        $this->assertSame(51.5, $character->fresh()->stat(SkillStats::checkId('probe'))->value);
    }

    /**
     * The reason a check is a stat at all: everything that feeds it is
     * resolved, so an implant on the governing attribute moves the check with
     * no wiring of its own.
     */
    public function test_a_modifier_on_the_governing_attribute_moves_the_check(): void
    {
        Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'sweep']);
        $character = $this->character(['perception' => 40, 'memory' => 30]);

        $sheet = Sheet::for($character, Context::empty());
        $before = $sheet->value(SkillStats::checkId('sweep'));

        $lifted = Sheet::for($character, Context::empty(), [
            Modifier::fromArray(['op' => 'add', 'amount' => 20, 'target' => ['id' => 'perception']]),
        ]);

        // +20 perception at half weight is +10 on the check.
        $this->assertSame($before + 10.0, $lifted->value(SkillStats::checkId('sweep')));
    }

    // ---- targeting -------------------------------------------------------

    public function test_a_skill_stat_carries_its_category_as_a_tag_so_a_group_can_be_targeted(): void
    {
        Skill::factory()->create(['slug' => 'wiretap', 'category' => 'Operating Systems']);

        $registry = StatRegistry::fromDefinitions(SkillStats::definitions());
        $stat = $registry->get(SkillStats::skillId('wiretap'));

        $this->assertTrue($stat->hasTag('skill'));
        $this->assertTrue($stat->hasTag('operating-systems'));
    }

    public function test_a_skill_level_cannot_be_pushed_past_what_training_could_reach(): void
    {
        Skill::factory()->create(['slug' => 'overclock']);

        $registry = StatRegistry::fromDefinitions(SkillStats::definitions());

        $this->assertSame(0.0, $registry->get(SkillStats::skillId('overclock'))->min);
        $this->assertSame(
            (float) config('skills.max_level'),
            $registry->get(SkillStats::skillId('overclock'))->max
        );
    }

    // ---- context ---------------------------------------------------------

    public function test_the_context_publishes_both_vitals_as_ratios(): void
    {
        $character = $this->character();
        $context = Sheet::contextFor($character);

        $this->assertSame(1.0, $context->number('hp_pct'), 'a new character starts full');
        $this->assertSame(1.0, $context->number('si_pct'));

        $character->current_synaptic_integrity = (int) floor($character->maximumFor('current_synaptic_integrity') / 4);
        $character->save();

        $this->assertLessThan(0.3, Sheet::contextFor($character->fresh())->number('si_pct'));
    }

    /**
     * The rule that fell out of building this: a ratio needs a maximum, a
     * maximum is a resolved stat, and resolving reads the context — so the
     * maxima that DEFINE the context are resolved context-free. A conditional
     * modifier therefore cannot vote on its own condition.
     */
    public function test_building_the_context_does_not_recurse(): void
    {
        $character = $this->character();

        $context = Sheet::contextFor($character);

        $this->assertIsFloat($context->number('hp_pct'));
        $this->assertIsFloat($context->number('si_pct'));
    }

    public function test_a_sheet_resolves_a_shared_input_once(): void
    {
        $character = $this->character();
        $sheet = $character->sheet();

        $first = $sheet->get('focus');

        $this->assertSame($first, $sheet->get('focus'), 'the same object, not an equal one');
    }

    public function test_an_unknown_stat_is_still_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->character()->stat('check.nothing_of_the_sort');
    }

    /**
     * THE CONTEXT'S SHEET AND THE REAL ONE ASK ONCE BETWEEN THEM. What acts on
     * a character does not depend on the context, so the sheet that works the
     * context out hands over what it gathered. Before, every sheet built
     * without a context queried all six sources twice, and the top bar built
     * four of them on every page.
     */
    public function test_a_sheet_asks_the_character_once(): void
    {
        $character = $this->character();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $sheet = Sheet::for($character);
        $sheet->get('hitpoints_max');
        $sheet->get('synaptic_integrity_max');

        // Nothing asked twice. Two different questions of one table are fine
        // (the skill levels and the skill-effects source both read
        // character_skills); the same question twice is the bug.
        $repeated = collect(DB::getQueryLog())
            ->map(fn (array $query): string => $query['query'].json_encode($query['bindings']))
            ->countBy()
            ->filter(fn (int $times): bool => $times > 1);

        $this->assertSame([], $repeated->all());
        $this->assertNotEmpty(DB::getQueryLog(), 'and it did ask');
    }

    /** And the answers are the ones a sheet with its own questions gets. */
    public function test_the_handed_over_sheet_agrees_with_a_fresh_one(): void
    {
        $character = $this->character(['current_synaptic_integrity' => 3]);

        $given = Sheet::for($character);
        $fresh = Sheet::for($character, Sheet::contextFor($character));

        $this->assertEquals($fresh->context(), $given->context());
        $this->assertSame($fresh->value('hitpoints_max'), $given->value('hitpoints_max'));
        $this->assertSame($fresh->value('synaptic_integrity_max'), $given->value('synaptic_integrity_max'));
    }
}
