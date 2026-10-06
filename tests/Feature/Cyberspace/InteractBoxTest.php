<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Models\Character;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The map's Interact box: the way into a run and the way to read the machine, side by side.
 *
 * "Plan a run" is green when the read is whole and brass when it is not, and Scan sits beside it rather
 * than in the top bar.
 */
final class InteractBoxTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private System $machine;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8, 'egress_trace_at' => 25, 'egress_si_at' => 40])->save();
        $this->machine = System::factory()->at(8, 8)->grade(1)->withTracer(2, 4)->create(['address' => 'DEADBEEF']);
    }

    private function page(): TestResponse
    {
        return $this->actingAs($this->character->fresh()->user)->get(route('cyberspace.index'))->assertOk();
    }

    private function scan(array $known): void
    {
        SystemScan::query()->create(array_merge([
            'character_id' => $this->character->id, 'address' => 'DEADBEEF', 'x' => 8, 'y' => 8, 'passes' => 1,
            'scanned_at' => now(), 'expires_at' => now()->addDay(),
        ], $known));
    }

    public function test_the_box_is_called_interact_and_holds_both_buttons(): void
    {
        $this->page()->assertSee('Interact')->assertDontSee('Making a run')
            ->assertSeeInOrder(['Interact', 'Plan a run', route('cyberspace.scan'), 'Scan'], false);
    }

    public function test_plan_a_run_is_brass_until_the_read_is_whole_and_then_green(): void
    {
        $this->page()->assertSee('btn btn--warn cut" href="'.route('run.setup'), false)->assertDontSee('btn btn--go cut" href="'.route('run.setup'), false);

        $os = Skill::query()->where('slug', 'lattice')->first() ?? Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $this->scan(['grade' => 1, 'os_skill_id' => $os->id, 'ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0, 'ice_level' => 0]);

        $this->page()->assertSee('btn btn--go cut" href="'.route('run.setup'), false)->assertDontSee('btn btn--warn cut" href="'.route('run.setup'), false);
    }

    public function test_the_scan_button_is_not_in_the_top_bar_any_more(): void
    {
        // One scan form on the page, and it comes after the Interact heading, not before it.
        $html = $this->page()->getContent();

        $this->assertSame(1, substr_count($html, 'action="'.route('cyberspace.scan').'"'));
        $this->assertGreaterThan(strpos($html, 'Interact'), strpos($html, 'action="'.route('cyberspace.scan').'"'));
    }

    public function test_the_thresholds_panel_is_headed_thresholds_with_its_values_as_tags(): void
    {
        $this->page()->assertSee('Thresholds')->assertDontSee('When to pull out')
            ->assertSee('<span class="tag num" title="Egress when the trace passes">25%</span>', false)
            ->assertSee('<span class="tag num" title="Egress when Synaptic Integrity falls below">40%</span>', false);
    }
}
