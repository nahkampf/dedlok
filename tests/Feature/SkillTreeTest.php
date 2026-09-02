<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterSkill;
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

    public function test_each_category_is_a_collapsible_section(): void
    {
        $this->skill('Cyberdecks', 'Systems');
        $this->skill('Neural Interfacing', 'Cybernetics');
        $this->skill('Knives', 'Combat');

        $html = $this->get(route('skills.index'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<details'), 'one section per category');

        // Only the first opens, so the page is scannable without being a wall.
        $this->assertSame(1, substr_count($html, 'open'));

        // And every skill is inside one, closed or not.
        foreach (['Cyberdecks', 'Neural Interfacing', 'Knives'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
    }

    public function test_a_skill_with_no_prerequisites_is_open(): void
    {
        $this->skill('Cyberdecks');

        $nodes = $this->tree()['Systems'];

        $this->assertCount(1, $nodes);
        $this->assertTrue($nodes[0]->isOpen());
        $this->assertSame([], $nodes[0]->requirements);
    }

    public function test_a_skill_names_what_it_requires(): void
    {
        $profiling = $this->skill('System Profiling');
        $decks = $this->skill('Cyberdecks');
        $this->gate($profiling, $decks, 3);

        $node = collect($this->tree()['Systems'])
            ->firstWhere(fn (SkillTreeNode $n): bool => $n->skill->title === 'System Profiling');

        $this->assertFalse($node->isOpen());
        $this->assertSame('Cyberdecks', $node->requirements[0]['skill']->title);
        $this->assertSame(3, $node->requirements[0]['level']);
    }

    public function test_a_section_is_alphabetical_whatever_the_graph_says(): void
    {
        // Zero Day needs Cyberdecks, so a dependency order would put it last.
        // A section is a place to look a skill up, and looking one up wants
        // its name where you expect it.
        $decks = $this->skill('Cyberdecks');
        foreach (['Zero Day', 'Anonymity', 'Network Topology'] as $title) {
            $this->gate($this->skill($title), $decks);
        }

        $titles = array_map(
            fn (SkillTreeNode $node): string => $node->skill->title,
            $this->tree()['Systems'],
        );

        $this->assertSame(['Anonymity', 'Cyberdecks', 'Network Topology', 'Zero Day'], $titles);
    }

    // ---- where a tree cannot describe the graph -----------------------------

    public function test_an_open_skill_lists_no_requirements(): void
    {
        $this->skill('Cyberdecks');

        $this->assertSame([], $this->tree()['Systems'][0]->requirements);
    }

    public function test_a_skill_states_every_prerequisite_it_has(): void
    {
        // Advanced needs BOTH. A tree had to draw one edge and explain the
        // other; a list of boxes just says both.
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $advanced = $this->skill('Advanced Profiling');

        $this->gate($profiling, $decks, 3);
        $this->gate($advanced, $decks, 5);
        $this->gate($advanced, $profiling, 5);

        $node = collect($this->tree()['Systems'])
            ->firstWhere(fn (SkillTreeNode $n): bool => $n->skill->title === 'Advanced Profiling');

        $this->assertSame(
            ['Cyberdecks' => 5, 'System Profiling' => 5],
            collect($node->requirements)->mapWithKeys(
                fn (array $r): array => [$r['skill']->title => $r['level']]
            )->all()
        );
    }

    public function test_every_skill_appears_exactly_once(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $advanced = $this->skill('Advanced Profiling');
        $this->gate($profiling, $decks);
        $this->gate($advanced, $decks);
        $this->gate($advanced, $profiling);

        $seen = array_map(
            fn (SkillTreeNode $node): string => $node->skill->title,
            $this->tree()['Systems'],
        );

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
        $this->assertCount(1, $node->requirements);
        $this->assertSame('Neural Interfacing', $node->requirements[0]['skill']->title);
    }

    public function test_a_cycle_is_reported_rather_than_hung_on(): void
    {
        $a = $this->skill('Alpha');
        $b = $this->skill('Beta');
        $this->gate($a, $b);
        $this->gate($b, $a);

        $nodes = $this->tree()['Systems'];

        $this->assertCount(2, $nodes);
        foreach ($nodes as $node) {
            $this->assertTrue($node->unreachable, "{$node->skill->title} should be unreachable");
        }
    }

    // ---- presentation -------------------------------------------------------

    public function test_no_row_carries_a_tier_accent(): void
    {
        // .item-row paints border-left from --tier, and .t2 is jade — which IS
        // --ok, what the tick means. Rank belongs in the meta column as ×N,
        // not as a colour that says "you have this". The whole ramp is checked,
        // not just the two an item can be: the kit still defines all five.
        $this->skill('Cyberdecks', rank: 2);

        $html = $this->get(route('skills.index'))->assertOk()->getContent();

        foreach (['t1', 't2', 't3', 't4', 't5'] as $tier) {
            $this->assertStringNotContainsString("item-row {$tier}", $html);
        }
    }

    public function test_a_section_holds_every_skill_in_its_category(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $this->gate($profiling, $decks);
        $this->gate($this->skill('Advanced Profiling'), $profiling);

        $this->assertCount(3, $this->tree()['Systems']);
    }

    // ---- the page -----------------------------------------------------------

    public function test_the_page_lists_every_skill_with_what_it_needs(): void
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

        // Both prerequisites are stated, in the same kind of box.
        $response->assertSee(Text::get('skills.requires', [
            'skill' => 'Cyberdecks', 'level' => 3,
        ]));
        $response->assertSee(Text::get('skills.requires', [
            'skill' => 'System Profiling', 'level' => 5,
        ]));

        // A rank is a multiplier, so it reads as one: ×4, not "Rank 4".
        $response->assertSee(Text::get('skills.rank', ['rank' => 4]));
        $response->assertSee('×4');
    }

    public function test_a_guest_sees_the_catalogue_and_no_overlay(): void
    {
        $this->skill('Cyberdecks');

        $html = $this->get(route('skills.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Cyberdecks', $html);
        $this->assertStringNotContainsString('ticked', $html, 'a guest has injected nothing');
        $this->assertStringNotContainsString('meter--seg', $html);

        // A signed-in user without a character is still sent to creation by
        // RequireCharacter; that is the middleware's job, not this page's.
        $this->actingAs(User::factory()->create())
            ->get(route('skills.index'))
            ->assertRedirect(route('character.create'));
    }

    // ---- what the viewer has ------------------------------------------------

    private function player(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 1],
        ));
    }

    private function inject(Character $character, Skill $skill, int $level = 0, ?int $points = null): void
    {
        CharacterSkill::create([
            'character_id' => $character->id,
            'skill_id' => $skill->id,
            'skill_points' => $points ?? ($level === 0 ? 0 : $skill->pointsForLevel($level)),
            'attained_level' => $level,
        ]);
    }

    public function test_an_injected_skill_is_ticked_and_metered(): void
    {
        $decks = $this->skill('Cyberdecks');
        $player = $this->player();
        $this->inject($player, $decks, 2);

        $html = $this->actingAs($player->user)
            ->get(route('skills.index'))->assertOk()->getContent();

        $this->assertStringContainsString('ticked', $html);
        $this->assertStringContainsString('--tick:var(--ok)', $html);

        // Two of five levels lit, the rest left as the kit's grey. Five cells
        // whatever is lit — the segments belong to the bar, not to the fill.
        $this->assertStringContainsString('--segs:5;--v:0.4', $html);
    }

    public function test_the_tick_says_what_it_means(): void
    {
        $decks = $this->skill('Cyberdecks');
        $player = $this->player();
        $this->inject($player, $decks, 1);

        $this->actingAs($player->user)
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee('<span class="tick" title="'.Text::get('skills.injected').'">', false);
    }

    public function test_the_meter_reports_the_level_and_the_points_banked(): void
    {
        $decks = $this->skill('Cyberdecks');
        $player = $this->player();
        $this->inject($player, $decks, 2, points: 1306);

        $this->actingAs($player->user)
            ->get(route('skills.index'))
            ->assertOk()
            // Thousands separated: it is a number a player reads, not an id.
            ->assertSee(Text::get('skills.level_meter', [
                'level' => 2, 'max' => 5, 'points' => '1,306',
            ]), false);
    }

    public function test_a_skill_you_lack_is_dimmed_and_one_you_have_is_not(): void
    {
        $decks = $this->skill('Cyberdecks');
        $this->skill('Network Topology');
        $player = $this->player();
        $this->inject($player, $decks, 1);

        $html = $this->actingAs($player->user)
            ->get(route('skills.index'))->assertOk()->getContent();

        // One of the two rows is half-lit, and it is not the one with the tick.
        $this->assertSame(1, substr_count($html, 'item-row unlit'));
        $this->assertStringNotContainsString('ticked unlit', $html);
    }

    public function test_a_guest_sees_nothing_dimmed(): void
    {
        // Dimming says "you do not have this", which needs a you. A guest gets
        // the catalogue at full strength rather than a page that looks broken.
        $this->skill('Cyberdecks');
        $this->skill('Network Topology');

        $html = $this->get(route('skills.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('unlit', $html);
    }

    public function test_a_player_who_has_injected_nothing_sees_every_row_dimmed(): void
    {
        $this->skill('Cyberdecks');
        $this->skill('Network Topology');

        $html = $this->actingAs($this->player()->user)
            ->get(route('skills.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'item-row unlit'));
    }

    public function test_an_untrained_injection_is_still_ticked(): void
    {
        // Level 0 is a real state — injected, untrained — so presence in the
        // map marks the skill, never the level.
        $decks = $this->skill('Cyberdecks');
        $player = $this->player();
        $this->inject($player, $decks, 0);

        $html = $this->actingAs($player->user)
            ->get(route('skills.index'))->assertOk()->getContent();

        $this->assertStringContainsString('ticked', $html);
        $this->assertStringContainsString('--segs:5;--v:0', $html);
    }

    public function test_a_skill_the_player_lacks_is_not_marked(): void
    {
        $decks = $this->skill('Cyberdecks');
        $this->skill('Network Topology');
        $player = $this->player();
        $this->inject($player, $decks, 1);

        $html = $this->actingAs($player->user)
            ->get(route('skills.index'))->assertOk()->getContent();

        // One row ticked, one not — the overlay is per skill, not per page.
        $this->assertSame(1, substr_count($html, 'ticked'));
    }

    public function test_the_tree_shape_does_not_depend_on_the_viewer(): void
    {
        $decks = $this->skill('Cyberdecks');
        $profiling = $this->skill('System Profiling');
        $this->gate($profiling, $decks, 3);

        $player = $this->player();
        $this->inject($player, $profiling, 1);

        $guest = $this->get(route('skills.index'))->getContent();
        $mine = $this->actingAs($player->user)->get(route('skills.index'))->getContent();

        $strip = fn (string $html): int => substr_count($html, 'class="item-row');

        $this->assertSame($strip($guest), $strip($mine), 'the catalogue changed shape per viewer');
    }

    public function test_an_empty_database_says_so(): void
    {
        $this->get(route('skills.index'))
            ->assertOk()
            ->assertSee(Text::get('skills.empty'));
    }
}
