<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Decks\DeckTotals;
use App\Decks\Storage;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The briefing's Suspend box: ticked background processes stop when the run begins and start again when it ends.
 */
final class SuspendProcessesTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();

        System::factory()->at(8, 8)->grade(1)->withIce(Ice::query()->where('level', 1)->sole())->create(['address' => 'DEADBEEF']);
    }

    /** @return list<InventoryItem> running rows */
    private function running(Item $item, int $copies = 1): array
    {
        $row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $copies]);
        $runner = app(ProgramRunner::class);

        for ($i = 0; $i < $copies; $i++) {
            $this->assertTrue($runner->start($this->character->fresh(), $row->fresh() ?? $row)->succeeded());
            $row = InventoryItem::query()->where('item_id', $item->id)->loose()->first() ?? $row;
        }

        return InventoryItem::query()->where('item_id', $item->id)->whereNotNull('running_on_deck_id')->get()->all();
    }

    private function scanner(): Item
    {
        return Item::factory()->program(ProgramKind::Analyser, qflops: 0.1, memory: 0.1, storage: 0.1, background: true)->create();
    }

    private function start(array $suspend): TestResponse
    {
        return $this->actingAs($this->character->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value, 'suspend' => $suspend]);
    }

    private function runningCount(Item $item): int
    {
        return InventoryItem::query()->where('item_id', $item->id)->whereNotNull('running_on_deck_id')->count();
    }

    public function test_a_ticked_process_stops_for_the_run_and_returns_when_it_ends(): void
    {
        $item = $this->scanner();
        [$row] = $this->running($item);

        $this->start([$row->id])->assertRedirect(route('run.show'));

        $this->assertSame(0, $this->runningCount($item), 'stopped for the run');
        $this->assertSame([$item->id => 1], $this->character->fresh()->suspended_programs);

        $character = $this->character->fresh();
        app(RunStarter::class)->end($character, $character->currentRun, RunEnding::Egress);

        $this->assertSame(1, $this->runningCount($item), 'started again');
        $this->assertNull($this->character->fresh()->suspended_programs);
    }

    public function test_every_copy_of_a_stacked_scanner_ticked_comes_back(): void
    {
        $item = $this->scanner();
        $item->program->forceFill(['multithreaded' => true])->save();
        Config::set('programs.threads', 2);
        $rows = $this->running($item, 2);

        $this->start(array_map(static fn (InventoryItem $r): int => $r->id, $rows))->assertRedirect(route('run.show'));
        $this->assertSame([$item->id => 2], $this->character->fresh()->suspended_programs);

        $character = $this->character->fresh();
        app(RunStarter::class)->end($character, $character->currentRun, RunEnding::Egress);

        $this->assertSame(2, $this->runningCount($item));
    }

    public function test_a_run_that_does_not_begin_leaves_everything_running(): void
    {
        $item = $this->scanner();
        [$row] = $this->running($item);
        $second = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => Item::query()->where('slug', 'icebreaker-2')->value('id'), 'quantity' => 1]);
        $first = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => Item::query()->where('slug', 'icebreaker-1')->value('id'), 'quantity' => 1]);

        $this->actingAs($this->character->user)->post(route('run.start'), [
            'purpose' => RunPurpose::Extraction->value, 'icebreaker' => $first->id, 'programs' => [$second->id], 'suspend' => [$row->id],
        ])->assertSessionHas('error');

        $this->assertSame(1, $this->runningCount($item), 'the scanner never stopped');
        $this->assertNull($this->character->fresh()->suspended_programs);
    }

    public function test_only_your_own_running_background_processes_can_be_suspended(): void
    {
        $item = $this->scanner();
        $idle = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $other = Character::factory()->create();
        $theirs = InventoryItem::create(['character_id' => $other->id, 'item_id' => $item->id, 'quantity' => 1, 'running_on_deck_id' => $idle->id]);

        $this->start([$idle->id, $theirs->id])->assertRedirect(route('run.show'));

        $this->assertNull($this->character->fresh()->suspended_programs);
        $this->assertNotNull($theirs->fresh()->running_on_deck_id, 'somebody else\'s is untouched');
    }

    public function test_a_compressor_the_storage_needs_cannot_be_suspended(): void
    {
        $item = Item::factory()->program(ProgramKind::Utility, qflops: 0.1, memory: 0.1, storage: 0.1, background: true, modifiers: [
            ['op' => 'percentage_of_base', 'amount' => 900, 'target' => ['id' => 'deck_storage']],
        ])->create();
        [$row] = $this->running($item);

        // Fill the room the Compressor made.
        $filler = Item::factory()->create(['container' => Item::CONTAINER_DATA, 'stackable' => false, 'size' => Storage::capacity($this->character->fresh()) - 5]);
        InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $filler->id, 'quantity' => 1]);
        $character = $this->character->fresh();
        $plain = (new ProgramRunner)->wouldOverfill($character, [$row->id]);

        $this->assertTrue($plain);

        $this->actingAs($this->character->user)->get(route('run.setup'))->assertOk()
            ->assertSee(ProcessRefusal::WouldOverfillStorage->message());
        $this->start([$row->id])->assertSessionHas('error', ProcessRefusal::WouldOverfillStorage->message());

        $this->assertSame(1, $this->runningCount($item));
        $this->assertNull($this->character->fresh()->current_run_id);
    }

    public function test_what_will_not_start_again_is_dropped_with_a_message(): void
    {
        $item = $this->scanner();
        $this->character->forceFill(['suspended_programs' => [$item->id => 1]])->save();

        app(ProgramRunner::class)->resumeSuspended($this->character->fresh());

        $this->assertNull($this->character->fresh()->suspended_programs);
        $this->assertSame(0, $this->runningCount($item));
    }

    public function test_the_briefing_lists_what_is_running_to_suspend(): void
    {
        $item = $this->scanner();
        [$row] = $this->running($item);

        $this->actingAs($this->character->user)->get(route('run.setup'))->assertOk()
            ->assertSee('name="suspend[]" value="'.$row->id.'"', false);
    }

    /** The page's sum counts what Suspend gives back, so a row that fits once the hog is stopped must not be disabled by the server. */
    public function test_the_briefing_does_not_disable_a_row_for_compute_it_could_have_after_suspending(): void
    {
        $deck = $this->character->equippedDeck;
        $total = DeckTotals::forCharacter($this->character, $deck, app(Resolver::class))->qflops;
        $hog = Item::factory()->program(ProgramKind::Analyser, qflops: $total - 0.5, memory: 0.1, storage: 0.1, background: true)->create();
        $this->running($hog);
        $breaker = Item::factory()->program(ProgramKind::Icebreaker, qflops: 1, memory: 0.1, storage: 0.1, background: false)->create();
        $row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $breaker->id, 'quantity' => 1]);

        $this->actingAs($this->character->user)->get(route('run.setup'))->assertOk()
            ->assertSee('name="icebreaker" value="'.$row->id.'"', false)
            ->assertDontSee('value="'.$row->id.'" disabled', false)
            ->assertDontSee(ProcessRefusal::NotEnoughCompute->message());
    }
}
