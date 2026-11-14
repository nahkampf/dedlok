<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\Probe;
use App\Cyberspace\ScanFact;
use App\Cyberspace\ScanFile;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Sheet;
use App\Stats\SkillStats;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** Run trees, step 6 (docs/RUN_TREE.md): the scan facts Layout, Nodes and Traps, read in order after the Grade. */
final class ScanTreeTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('features.intrusion_runs_v2', true);

        Skill::factory()->governedBy('perception', 'memory')->create(['slug' => 'systemprofiling', 'title' => 'System Profiling', 'rank' => 1]);
        SkillStats::forget();
        Sheet::forgetSkills();

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);

        $scanner = $this->ownedBy($this->character, Item::factory()->program(ProgramKind::Analyser, background: true)->create());
        $this->assertTrue(app(ProgramRunner::class)->start($this->character->fresh(), $scanner)->succeeded());

        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();

        $os = Skill::factory()->create(['slug' => 'auroos', 'title' => 'AuraOS', 'category' => 'Operating Systems', 'rank' => 2]);
        System::factory()->at(8, 8)->grade(3)->type(SystemType::Compute)->create(['address' => 'DEADBEEF', 'os_skill_id' => $os->id]);
    }

    /** One pass, every roll landing; the read starts the next pass by itself. */
    private function pass(): SystemScan
    {
        $probe = Probe::fromConfig(CheckRoller::fromConfig(static fn (): float => 0.0));
        $probe->scan($this->character->fresh());
        // To the moment this pass matures and no further: a later look would settle the passes it chains as well.
        $this->travelTo($this->scan()->pass_ready_at);
        $probe->settle($this->character->fresh(), $this->scan());

        return $this->scan();
    }

    private function scan(): SystemScan
    {
        return SystemScan::query()->where('character_id', $this->character->id)->sole();
    }

    public function test_the_tree_is_read_after_the_grade_one_fact_a_pass(): void
    {
        $scan = $this->pass();
        $this->assertSame(3, $scan->grade);
        $this->assertSame(0, $scan->tree, 'the Layout waits for a pass that starts with the Grade known');
        $this->assertSame(['layout'], $scan->unknowns(), 'and the Nodes wait for the Layout');

        $scan = $this->pass();
        $this->assertSame(1, $scan->tree);
        $this->assertSame(['nodes'], $scan->unknowns());

        $scan = $this->pass();
        $this->assertSame(2, $scan->tree);
        $this->assertSame(['traps'], $scan->unknowns(), 'the Traps wait for the Nodes');

        $scan = $this->pass();
        $this->assertSame(3, $scan->tree);
        $this->assertTrue($scan->isComplete());
        $this->assertMatchesRegularExpression('/paths?, \d+ moves? to the core/', (string) ScanFact::Layout->readFrom($scan));
        $this->assertMatchesRegularExpression('/firewall.*vault/', (string) ScanFact::Nodes->readFrom($scan));
        $this->assertMatchesRegularExpression('/traps?/', (string) ScanFact::Traps->readFrom($scan));
    }

    public function test_with_the_flag_off_there_is_no_tree_to_read_or_list(): void
    {
        Config::set('features.intrusion_runs_v2', false);

        $scan = $this->pass();

        $this->assertNull($scan->tree);
        $this->assertTrue($scan->isComplete());
        $this->assertNotContains('layout', array_column($scan->facts(), 'key'));
    }

    public function test_a_file_made_before_trees_reads_as_having_none_even_with_the_flag_on(): void
    {
        $old = new SystemScan(['grade' => 3, 'os_skill_id' => null, 'ids_detection' => 0, 'tracer_detection' => 0, 'ice_level' => 0, 'tree' => null]);

        $this->assertNotContains('layout', array_column($old->facts(), 'key'));
        $this->assertNotContains('layout', $old->unknowns());
    }

    public function test_an_exported_file_carries_how_far_into_the_tree_it_read(): void
    {
        $this->pass();
        $scan = $this->pass();

        $file = ScanFile::fromArray(ScanFile::fromScan($scan, null)->announced()->toArray());

        $this->assertSame(1, $file?->tree);
        $this->assertSame(['nodes'], $file->toScan()->unknowns(), 'a file that read the Layout only is not complete');
    }

    public function test_the_tree_modal_opens_once_the_layout_is_read_and_names_nodes_once_they_are(): void
    {
        $user = $this->character->user;
        $this->pass();

        $this->assertNull($this->actingAs($user)->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))->json('tree'));
        $this->actingAs($user)->get(route('cyberspace.tree', ['address' => 'DEADBEEF']))->assertNotFound();

        $this->pass();
        $url = $this->actingAs($user)->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))->json('tree');
        $this->assertSame(route('cyberspace.tree', ['address' => 'DEADBEEF']), $url);
        $this->actingAs($user)->get($url)->assertOk()->assertSee('board__node--fog', false)->assertDontSee('data-turn-form', false)
            ->assertDontSeeText(Text::get('cyberspace.tree.node.core'));

        $this->pass();
        $this->actingAs($user)->get($url)->assertOk()->assertDontSee('board__node--fog', false)
            ->assertSeeText(Text::get('cyberspace.tree.node.core'));
        $this->actingAs($user)->get(route('cyberspace.index'))->assertOk()->assertSee($url, false);
    }

    public function test_a_scan_file_whose_system_has_moved_on_says_so(): void
    {
        $this->pass();
        $this->pass();
        $this->pass();
        $this->pass();
        // A finished read files itself.
        $row = InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('scan_file')->sole();

        $this->actingAs($this->character->user)->get(route('inventory.tree', $row))->assertOk()
            ->assertSeeText(Text::get('cyberspace.tree.node.core'));

        System::query()->where('address', 'DEADBEEF')->update(['address' => 'CAFEBABE']);

        $this->actingAs($this->character->user)->get(route('inventory.tree', $row))->assertOk()
            ->assertSeeText(Text::get('cyberspace.tree.gone'));
    }
}
