<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Checks\CheckRoller;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seam: a resolved check becomes a yes or a no.
 *
 * Two halves that know nothing about each other. The stat system turns a
 * character into `check.<slug>` — attributes, level, and every modifier acting
 * on them — and the roller turns that number and an opposition into an outcome.
 * These tests are about the join, which is where a game with two answers to
 * "what are my chances" would start.
 */
final class CheckRollTest extends TestCase
{
    use RefreshDatabase;

    /** Fixer: no perks, so a check is the bare formula. */
    private const NO_PERKS = 3;

    protected function setUp(): void
    {
        parent::setUp();

        SkillStats::forget();
        Sheet::forgetSkills();
    }

    /** @param  array<string, int>  $attributes */
    private function character(array $attributes = []): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            $attributes,
            ['archetype' => self::NO_PERKS],
        ));
    }

    private function trained(Character $character, Skill $skill, int $level): void
    {
        CharacterSkill::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => 0,
            'attained_level' => $level,
        ]);
    }

    public function test_a_resolved_check_rolls_against_an_opposition(): void
    {
        $skill = Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'probe', 'rank' => 1]);
        $character = $this->character(['perception' => 40, 'memory' => 30]);
        $this->trained($character, $skill, 3);

        // 0.5*40 + 0.25*30 + 8*3 = 51.5, unrounded.
        $value = $character->fresh()->stat(SkillStats::checkId('probe'))->value;
        $this->assertSame(51.5, $value);

        // Evenly matched against a difficulty of 51.5: a coin flip, whatever
        // the numbers happen to be.
        $roller = CheckRoller::fromConfig(static fn (): float => 0.49);

        $this->assertSame(0.5, $roller->chance($value, 51.5));
        $this->assertTrue($roller->roll($value, 51.5)->passed);
    }

    /**
     * THE POINT OF A CHECK BEING A STAT. Training moves the odds without the
     * roller learning that skills exist, and a modifier would do the same.
     */
    public function test_training_improves_the_odds(): void
    {
        $skill = Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'probe', 'rank' => 1]);
        $roller = CheckRoller::fromConfig(static fn (): float => 0.5);

        $novice = $this->character(['perception' => 40, 'memory' => 30]);
        $this->trained($novice, $skill, 1);

        $adept = $this->character(['perception' => 40, 'memory' => 30]);
        $this->trained($adept, $skill, 5);

        $against = 45.0;
        $noviceChance = $roller->chance($novice->fresh()->stat(SkillStats::checkId('probe'))->value, $against);
        $adeptChance = $roller->chance($adept->fresh()->stat(SkillStats::checkId('probe'))->value, $against);

        $this->assertLessThan(0.5, $noviceChance, 'four levels short of the opposition');
        $this->assertGreaterThan(0.5, $adeptChance);
        $this->assertGreaterThan($noviceChance, $adeptChance);
    }

    /**
     * A check resolves for a skill nobody has injected, and rolling it is not
     * the same question as being allowed to. The gate is hasInjected(), and it
     * always was — reading a number as permission would let anyone attempt
     * anything.
     */
    public function test_an_untrained_check_still_rolls(): void
    {
        Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'probe', 'rank' => 1]);
        $character = $this->character(['perception' => 40, 'memory' => 30]);

        $value = $character->stat(SkillStats::checkId('probe'))->value;
        $floor = (float) config('checks.certainty_floor');

        // 27.5 against 80 is a margin of -52.5: the curve gives a hair under
        // 0.14%, which the floor lifts back to 0.85%. Hopeless, never impossible.
        $outcome = CheckRoller::fromConfig(static fn (): float => $floor / 2)->roll($value, 80);

        $this->assertSame(27.5, $value);
        $this->assertSame($floor, $outcome->chance);
        $this->assertTrue($outcome->passed, 'hopeless, and the floor is still open');
        $this->assertTrue($outcome->wasFloored($floor), 'that was luck, not skill');
    }
}
