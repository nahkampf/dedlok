<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * How long a chain a character may route a run through.
 *
 * The ceiling is ten and it is all skill. Nothing reads it yet — the chain
 * itself is not built — but the two skills have existed since the catalogue
 * was written with nothing ever asking them a question, and this is the
 * question.
 */
final class RoutingHopsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
    }

    /** A gate before it is a number: untrained routes through nothing. */
    public function test_nobody_routes_through_anything_by_default(): void
    {
        $this->assertSame(0.0, $this->character->stat('routing_hops')->value);
    }

    public function test_a_hop_a_level(): void
    {
        $this->trainOwn('a_routing_skill', 3);

        $this->assertSame(3.0, $this->character->fresh()->stat('routing_hops')->value);
    }

    /** Ten is the ceiling on skills alone, and it takes both trained out. */
    public function test_both_skills_trained_out_reach_ten(): void
    {
        $this->trainOwn('a_routing_skill', 5);
        $this->trainOwn('an_advanced_routing_skill', 5);

        $this->assertSame(10.0, $this->character->fresh()->stat('routing_hops')->value);
    }

    /** Injected and untrained grants nothing, the way level 0 always does. */
    public function test_knowing_it_untrained_routes_through_nothing(): void
    {
        $this->trainOwn('a_routing_skill', 0);

        $this->assertSame(0.0, $this->character->fresh()->stat('routing_hops')->value);
    }

    // ---- and the shipped rows carry it --------------------------------------

    /**
     * THE CATALOGUE, where it exists. The shipped skills are content in the
     * database rather than rows a migration writes, so a test database has
     * only what a test made — `SkillEffectTest` skips in the same words.
     */
    public function test_the_shipped_skills_grant_five_hops_each(): void
    {
        foreach (['routing', 'advancedrouting'] as $slug) {
            $skill = Skill::query()->where('slug', $slug)->first();

            if ($skill === null) {
                $this->markTestSkipped("{$slug} is not in this database");
            }

            $hops = array_filter(
                (array) $skill->modifiers,
                static fn (array $grant): bool => ($grant['target']['id'] ?? null) === 'routing_hops',
            );

            $this->assertCount(5, $hops, "{$slug} grants one hop a level");
            $this->assertSame([1, 2, 3, 4, 5], array_values(array_column($hops, 'level')));

            foreach ($hops as $grant) {
                $this->assertSame('add', $grant['op'], 'a hop is a whole machine');
                $this->assertSame(1, $grant['amount']);
            }
        }
    }

    /** The grants were APPENDED: what the skills already did still happens. */
    public function test_the_shipped_skills_still_do_what_they_did_before(): void
    {
        $skill = Skill::query()->where('slug', 'routing')->first();

        if ($skill === null) {
            $this->markTestSkipped('routing is not in this database');
        }

        $targets = array_column(array_column((array) $skill->modifiers, 'target'), 'id');

        $this->assertContains('routing_hops', $targets, 'the new grants are there');
        $this->assertContains('deck_bandwidth', $targets, 'and the old ones survived');
    }

    // ---- and what a hop is worth --------------------------------------------

    /**
     * NO HOP IS EVER CERTAIN TO PASS, which is the whole of what keeps a
     * worthless machine worth backdooring: at 100 a grade 1 hop could never
     * fail, so it could never delay anything.
     */
    public function test_even_the_cheapest_hop_can_hold_a_trace(): void
    {
        $table = Config::get('cyberspace.trace.advance');

        $this->assertLessThan(100, $table[1], 'a junk hop still has a chance');

        foreach ($table as $grade => $chance) {
            $this->assertLessThanOrEqual(100, $chance);
            $this->assertGreaterThan(0, $chance, "grade {$grade} must always be passable");
        }
    }

    /** Deeper machines hinder more, all the way down the table. */
    public function test_a_hop_is_worth_its_grade(): void
    {
        $table = Config::get('cyberspace.trace.advance');

        $previous = 101;

        foreach (range(1, 9) as $grade) {
            $this->assertLessThan($previous, $table[$grade], "grade {$grade} must hinder more than the one above it");
            $previous = $table[$grade];
        }
    }

    /**
     * A skill of this test's own, granting a hop a level, known at that level.
     *
     * BUILT RATHER THAN LOOKED UP, because the shipped catalogue is content in
     * the database and a test database has none of it. What is being proved
     * here is the MECHANISM — five grants of `add 1` come to five hops, and
     * two such skills come to ten — which is true whatever the rows are
     * called. The test above checks that the shipped rows carry exactly this
     * shape, where they exist.
     */
    private function trainOwn(string $slug, int $level): void
    {
        $skill = Skill::factory()->rank(4)->governedBy('intellect', 'memory')->create([
            'slug' => $slug,
            'modifiers' => array_map(
                static fn (int $at): array => [
                    'op' => 'add',
                    'level' => $at,
                    'amount' => 1,
                    'target' => ['id' => 'routing_hops'],
                ],
                range(1, 5),
            ),
        ]);

        CharacterSkill::query()->create([
            'character_id' => $this->character->id,
            'skill_id' => $skill->id,
            'attained_level' => $level,
            'skill_points' => 0,
        ]);

        $this->character->refresh();
    }
}
