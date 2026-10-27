<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\RunPurpose;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

final class AssessorScreensTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private Skill $os;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        // The skill first: its check stat is generated from the rows that exist when the registry is first built.
        $this->os = Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();

        System::factory()->at(8, 8)->grade(1)->withIce(Ice::query()->where('level', 1)->sole())->create(['address' => 'DEADBEEF']);
    }

    private function assessor(int $step = 20): InventoryItem
    {
        $item = Item::factory()->program(ProgramKind::Utility, qflops: 0.2, memory: 0.3, storage: 0.3, background: true, abilities: ['assess_step' => $step])->create();

        return $this->ownedBy($this->character, $item);
    }

    public function test_only_one_assessor_runs_at_a_time(): void
    {
        $runner = app(ProgramRunner::class);

        $this->assertTrue($runner->start($this->character, $this->assessor())->succeeded());
        $this->assertSame(ProcessRefusal::OneAssessor, $runner->start($this->character->fresh(), $this->assessor(5))->refusal);
    }

    public function test_the_briefing_shows_the_overall_only_while_an_assessor_runs(): void
    {
        $this->actingAs($this->character->user)->get(route('run.setup'))->assertOk()->assertDontSee('data-assess-readout', false);

        app(ProgramRunner::class)->start($this->character, $this->assessor());

        $this->actingAs($this->character->user)->get(route('run.setup'))->assertOk()->assertSee('data-assess-readout', false)->assertSee('[???]');
    }

    public function test_a_read_system_gets_a_rounded_figure_not_the_unknown_mark(): void
    {
        app(ProgramRunner::class)->start($this->character, $this->assessor());

        SystemScan::query()->create([
            'character_id' => $this->character->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8, 'passes' => 1,
            'scanned_at' => now(), 'expires_at' => now()->addDay(),
            'grade' => 1, 'os_skill_id' => $this->os->id, 'ids_detection' => 10, 'tracer_detection' => 2, 'tracer_rate' => 4, 'ice_level' => 1,
        ]);

        $html = $this->actingAs($this->character->user)->get(route('run.setup'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-assess-readout data-assess-url="[^"]*"><span class="tag tag--(ok|warn|crit) num">(20|40|60|80)%<\/span>/', $html);
    }

    public function test_the_run_screen_shows_the_turn_figure_while_an_assessor_runs(): void
    {
        app(ProgramRunner::class)->start($this->character, $this->assessor());

        $this->actingAs($this->character->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value])->assertRedirect();

        // Nothing has been read of this system, so every figure is the unknown mark rather than a guess. Whether the ICE
        // woke on the way in is chance, so either the action's line or the fight's lines are there.
        $html = $this->actingAs($this->character->user)->get(route('run.show'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Chance this turn: <span class="tag num">\[\?\?\?\]<\/span>|ICE lands a strike: <span class="tag num">\[\?\?\?\]<\/span>/', $html);
    }

    public function test_the_loadout_ticked_changes_the_overall_and_nothing_is_started(): void
    {
        app(ProgramRunner::class)->start($this->character, $this->assessor());
        SystemScan::query()->create([
            'character_id' => $this->character->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8, 'passes' => 1,
            'scanned_at' => now(), 'expires_at' => now()->addDay(),
            'grade' => 1, 'os_skill_id' => $this->os->id, 'ids_detection' => 30, 'tracer_detection' => 2, 'tracer_rate' => 4, 'ice_level' => 1,
        ]);

        // An Evasion program in the stash (not running) that adds a great deal of stealth.
        $evasion = Item::factory()->program(ProgramKind::Evasion, qflops: 1, memory: 1, modifiers: [
            ['op' => 'add', 'amount' => 200, 'target' => ['id' => 'stealth']],
        ])->create();
        $row = $this->ownedBy($this->character, $evasion);

        $plain = $this->actingAs($this->character->user)->getJson(route('run.assess'))->assertOk()->json('overall');
        $ticked = $this->actingAs($this->character->user)->getJson(route('run.assess', ['programs' => [$row->id]]))->assertOk()->json('overall');

        $this->assertNotSame($plain, $ticked);
        $this->assertNull($row->fresh()->running_on_deck_id);
    }

    public function test_the_assessment_is_refused_without_an_assessor(): void
    {
        $this->actingAs($this->character->user)->getJson(route('run.assess'))->assertNotFound();
    }
}
