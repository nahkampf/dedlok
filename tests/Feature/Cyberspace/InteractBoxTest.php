<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Models\Backdoor;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemScan;
use App\Programs\ProgramKind;
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

        // What the top bar remembers standing here: the line about scanners lives inside it.
        SystemContact::query()->create([
            'character_id' => $this->character->id, 'x' => 8, 'y' => 8, 'address' => 'DEADBEEF',
            'name' => $this->machine->displayName(), 'type' => $this->machine->type, 'owner' => 'Nobody',
            'seen_at' => now(), 'first_seen_at' => now(),
        ]);
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

    public function test_the_box_lists_what_the_read_has_found_and_marks_the_rest_unread(): void
    {
        $this->page()->assertSee('data-fact="grade"', false)->assertSee('UNKNOWN');

        $os = Skill::query()->where('slug', 'lattice')->first() ?? Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $this->scan(['grade' => 4, 'os_skill_id' => $os->id]);

        $this->page()->assertSee('data-fact="grade"', false)->assertSee('IV');
    }

    public function test_a_running_read_replaces_the_scan_button_with_show_scan(): void
    {
        $this->page()->assertSee(route('cyberspace.scan'), false)->assertDontSee('Show scan');

        $this->scan(['pass_started_at' => now()->subMinute(), 'pass_ready_at' => now()->addMinutes(10)]);

        $this->page()->assertSee('Show scan')
            ->assertSee('<button type="button" class="btn cut" data-reading-open="DEADBEEF">', false)
            ->assertDontSee('action="'.route('cyberspace.scan').'"', false);
    }

    private function analyser(): InventoryItem
    {
        $item = Item::factory()->program(ProgramKind::Analyser, qflops: 1, memory: 1)->create();

        return InventoryItem::query()->create([
            'character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1,
            'running_on_deck_id' => $this->character->fresh()->equipped_deck_id, 'started_at' => now()->subHour(),
        ]);
    }

    public function test_it_says_no_scanner_only_when_none_is_running_and_not_when_one_is_busy(): void
    {
        $this->page()->assertSee('You have no scanner running');

        // One running and reading THIS machine: it is not absent, and not busy elsewhere either.
        $process = $this->analyser();
        $this->scan(['pass_started_at' => now()->subMinute(), 'pass_ready_at' => now()->addMinutes(10), 'reading_process_id' => $process->id]);
        $this->page()->assertDontSee('You have no scanner running')->assertDontSee('reading something else')->assertSee('Show scan');
    }

    public function test_a_scanner_busy_on_another_machine_says_so(): void
    {
        $process = $this->analyser();
        SystemScan::query()->create([
            'character_id' => $this->character->id, 'address' => 'ELSEWHERE', 'x' => 1, 'y' => 1, 'passes' => 1,
            'scanned_at' => now(), 'expires_at' => now()->addDay(),
            'pass_started_at' => now()->subMinute(), 'pass_ready_at' => now()->addMinutes(10), 'reading_process_id' => $process->id,
        ]);

        $this->page()->assertDontSee('You have no scanner running')->assertSee('reading something else');
    }

    public function test_a_running_pass_carries_its_clock_so_the_bar_can_move(): void
    {
        $this->scan(['pass_started_at' => now()->subSeconds(60), 'pass_ready_at' => now()->addSeconds(540)]);

        $html = $this->page()->assertSee('data-pass-clock', false)->assertSee('data-pass-bar', false)->assertSee('data-pass-left', false)->getContent();

        preg_match('/data-remaining="(\d+)"\s+data-total="(\d+)"/', $html, $m);
        $this->assertEqualsWithDelta(540, (int) $m[1], 3);
        $this->assertEqualsWithDelta(600, (int) $m[2], 3);
    }

    public function test_a_backdoor_you_hold_here_is_listed_with_its_kind(): void
    {
        $this->page()->assertDontSee('Installed here');

        Backdoor::query()->create([
            'character_id' => $this->character->id, 'system_id' => $this->machine->id,
            'kind' => BackdoorKind::Siphon, 'installed_at' => now(),
        ]);

        $this->page()->assertSee('Installed here')->assertSee('Backdoor')
            ->assertSeeInOrder(['Installed here', 'tag tag--ok">Backdoor', 'tag tag--ok', 'Siphon'], false);
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
