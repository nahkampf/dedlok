<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\SkillPrerequisite;
use App\Models\User;
use App\Skills\SkillTree;
use App\Skills\SkillTreeNode;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arranging the skill graph as one tree per category.
 *
 * The graph is not a tree and never was: a skill may require several others,
 * and the seeded catalogue already contains one that requires two. Most of
 * what matters here is what happens at the places a tree cannot describe —
 * a second parent, a parent in another lane, and a cycle.
 */
class SkillTreeTest extends TestCase
{
    use RefreshDatabase;

    private function skill(string $title, string $category = 'Systems', int $rank = 1): Skill
    {
        return Skill::factory()->create([
            'slug' => str($title)->slug()->toString(),
            'title' => $title,
            'category' => $category,
            'rank' => $rank,
        ]);
    }

    private function gate(Skill $skill, Skill $required, int $level = 3): void
    {
        SkillPrerequisite::create([
            'skill_id' => $skill->id,
            'required_skill_id' => $required->id,
            'required_level' => $level,
        ]);
    }

    /** @return array<string, list<SkillTreeNode>> */
    private function tree(): array
    {
        return (new SkillTree(Skill::query()->with('prerequisites')->orderBy('title')->get()))->categories();
    }

    // ---- lanes -------------------------------------------------------------

    public function test_one_lane_per_category_in_name_order(): void
    {
        $this->skill('Cyberdecks', 'Systems');
        $this->skill('Neural Interfacing', 'Cybernetics');
        $this->skill('Knives', 'Combat');

        $this->assertSame(['Combat', 'Cybernetics', 'Systems'], array_keys($this->tree()));
    }

    public function test_a_skill_with_no_prerequisites_is_a_root(): void
    {
        $this->skill('Cyberdecks');

        $roots = $this->tree()['Systems'];

        $this->assertCount(1, $roots);
        $this->assertTrue($roots[0]->isRoot());
        $this->assertSame(0, $roots[0]->depth);
        $this->assertNull($roots[0]->requiredLevel);
    }

    public function test_a_requiring_skill_hangs_under_what_it_requires(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $this->gate($profiling, $decks, 3);

        $roots = $this->tree()['Systems'];

        $this->assertCount(1, $roots, 'the child must not also be a root');
        $this->assertSame('Cyberdecks', $roots[0]->skill->title);

        $child = $roots[0]->children[0];
        $this->assertSame('System Profiling', $child->skill->title);
        $this->assertSame(3, $child->requiredLevel);
        $this->assertSame(1, $child->depth);
    }

    public function test_children_are_ordered_by_title(): void
    {
        $decks = $this->skill('Cyberdecks');
        foreach (['Zero Day', 'Anonymity', 'Network Topology'] as $title) {
            $this->gate($this->skill($title), $decks);
        }

        $titles = array_map(
            fn (SkillTreeNode $node): string => $node->skill->title,
            $this->tree()['Systems'][0]->children,
        );

        $this->assertSame(['Anonymity', 'Network Topology', 'Zero Day'], $titles);
    }

    // ---- where a tree cannot describe the graph -----------------------------

    public function test_a_node_lists_the_edge_it_hangs_under_alongside_the_rest(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $this->gate($profiling, $decks, 3);

        $node = $this->tree()['Systems'][0]->children[0];
        $requirements = $node->requirements();

        $this->assertCount(1, $requirements);
        $this->assertSame('Cyberdecks', $requirements[0]['skill']->title);
        $this->assertSame(3, $requirements[0]['level']);
        $this->assertSame('Cyberdecks', $node->requiredSkill->title);
    }

    public function test_requirements_lead_with_the_drawn_edge(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $advanced = $this->skill('Advanced Profiling');
        $this->gate($profiling, $decks, 3);
        $this->gate($advanced, $decks, 5);
        $this->gate($advanced, $profiling, 5);

        $node = $this->tree()['Systems'][0]->children[0]->children[0];

        $this->assertSame(
            ['System Profiling', 'Cyberdecks'],
            array_map(fn (array $r): string => $r['skill']->title, $node->requirements()),
            'the one it hangs under comes first'
        );
    }

    public function test_a_root_lists_no_requirements(): void
    {
        $this->skill('Cyberdecks');

        $this->assertSame([], $this->tree()['Systems'][0]->requirements());
    }

    public function test_a_skill_with_two_parents_hangs_under_the_deeper_one(): void
    {
        // Advanced needs both Cyberdecks (depth 0) and Profiling (depth 1).
        // It becomes reachable only after the deeper chain, so that is where
        // it belongs; the other requirement is carried on the node.
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $advanced = $this->skill('Advanced Profiling');

        $this->gate($profiling, $decks, 3);
        $this->gate($advanced, $decks, 5);
        $this->gate($advanced, $profiling, 5);

        $node = $this->tree()['Systems'][0]->children[0]->children[0];

        $this->assertSame('Advanced Profiling', $node->skill->title);
        $this->assertSame(2, $node->depth);
        $this->assertSame(5, $node->requiredLevel);

        $this->assertCount(1, $node->alsoRequires);
        $this->assertSame('Cyberdecks', $node->alsoRequires[0]['skill']->title);
        $this->assertSame(5, $node->alsoRequires[0]['level']);
    }

    public function test_every_skill_appears_exactly_once(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $advanced = $this->skill('Advanced Profiling');
        $this->gate($profiling, $decks);
        $this->gate($advanced, $decks);
        $this->gate($advanced, $profiling);

        $seen = [];
        $walk = function (SkillTreeNode $node) use (&$walk, &$seen): void {
            $seen[] = $node->skill->title;
            foreach ($node->children as $child) {
                $walk($child);
            }
        };

        foreach ($this->tree()['Systems'] as $root) {
            $walk($root);
        }

        sort($seen);
        $this->assertSame(['Advanced Profiling', 'Cyberdecks', 'System Profiling'], $seen);
    }

    public function test_a_prerequisite_in_another_lane_does_not_move_the_skill(): void
    {
        // A node cannot hang in two lanes, so it stays a root in its own and
        // says what it is waiting on.
        $wetware = $this->skill('Neural Interfacing', 'Cybernetics');
        $decks = $this->skill('Cyberdecks', 'Systems');
        $this->gate($decks, $wetware, 2);

        $tree = $this->tree();

        $this->assertCount(1, $tree['Systems']);
        $node = $tree['Systems'][0];

        $this->assertSame('Cyberdecks', $node->skill->title);
        $this->assertTrue($node->isRoot());
        $this->assertCount(1, $node->alsoRequires);
        $this->assertSame('Neural Interfacing', $node->alsoRequires[0]['skill']->title);
    }

    public function test_a_cycle_is_reported_rather_than_hung_on(): void
    {
        $a = $this->skill('Alpha');
        $b = $this->skill('Beta');
        $this->gate($a, $b);
        $this->gate($b, $a);

        $roots = $this->tree()['Systems'];

        $this->assertCount(2, $roots);
        foreach ($roots as $node) {
            $this->assertTrue($node->unreachable, "{$node->skill->title} should be unreachable");
        }
    }

    // ---- presentation -------------------------------------------------------

    public function test_rank_maps_onto_the_kits_five_tiers(): void
    {
        foreach ([1 => 't1', 2 => 't2', 4 => 't3', 8 => 't4', 16 => 't5'] as $rank => $tier) {
            $node = new SkillTreeNode(Skill::factory()->make(['rank' => $rank]), 0, null);

            $this->assertSame($tier, $node->tierClass(), "rank {$rank}");
        }
    }

    public function test_a_lane_counts_everything_in_it(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $this->gate($profiling, $decks);
        $this->gate($this->skill('Advanced Profiling'), $profiling);

        $this->assertSame(3, $this->tree()['Systems'][0]->descendantCount());
    }

    // ---- the page -----------------------------------------------------------

    public function test_the_page_draws_the_tree(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $advanced = $this->skill('Advanced Profiling', rank: 4);
        $this->gate($profiling, $decks, 3);
        $this->gate($advanced, $decks, 5);
        $this->gate($advanced, $profiling, 5);

        $response = $this->get(route('skills.index'))->assertOk();

        $response->assertSee('Systems');
        $response->assertSee('Cyberdecks');

        // The edge the tree DID draw is still stated in a box: "Cyberdecks 3"
        // says more than "from level 3", and says it the same way as the rest.
        $response->assertSee(Text::get('skills.requires', [
            'skill' => 'Cyberdecks', 'level' => 3,
        ]));

        // And so is the second parent, which the tree could not draw.
        $response->assertSee(Text::get('skills.requires', [
            'skill' => 'System Profiling', 'level' => 5,
        ]));

        // A rank is a multiplier, so it reads as one: ×4, not "Rank 4".
        $response->assertSee(Text::get('skills.rank', ['rank' => 4]));
        $response->assertSee('×4');
    }

    /**
     * Indentation compounds, so it has to stop.
     *
     * A 320px screen has about 238px inside a panel; five levels of indent
     * would eat a third of it and the rows would wrap to nothing. The rail
     * still runs at every depth — only the offset stops.
     */
    public function test_the_indent_stops_compounding_but_the_rail_does_not(): void
    {
        $previous = $this->skill('Level 0');

        foreach (range(1, 5) as $level) {
            $current = $this->skill("Level {$level}");
            $this->gate($current, $previous);
            $previous = $current;
        }

        $html = $this->get(route('skills.index'))->assertOk()->getContent();

        $this->assertSame(5, substr_count($html, 'border-left:var(--hair)'), 'every level keeps its rail');
        $this->assertSame(3, substr_count($html, 'margin-inline-start'), 'the offset stops after three');
    }

    public function test_the_page_is_public_and_reads_no_character(): void
    {
        $this->skill('Cyberdecks');

        $this->get(route('skills.index'))->assertOk();

        // A signed-in user without a character is still sent to creation by
        // RequireCharacter; that is the middleware's job, not this page's.
        $this->actingAs(User::factory()->create())
            ->get(route('skills.index'))
            ->assertRedirect(route('character.create'));
    }

    public function test_an_empty_database_says_so(): void
    {
        $this->get(route('skills.index'))
            ->assertOk()
            ->assertSee(Text::get('skills.empty'));
    }
}
