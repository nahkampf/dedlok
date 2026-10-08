<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\CurrentProcesses;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Programs\Lifetime;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** A Script is a program that is spent by running: it lasts its lifetime, then is gone. */
final class ScriptTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $character;

    private ProgramRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute());
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
        $this->character = $this->character->fresh();
        $this->runner = new ProgramRunner;
    }

    /** @param  list<array<string, mixed>>  $modifiers */
    private function script(int $lifetime = 600, float $memory = 0.4, array $modifiers = []): Item
    {
        return Item::factory()->program(ProgramKind::Script, qflops: 0.4, memory: $memory, storage: 0.4, modifiers: $modifiers, lifetime: $lifetime)->create();
    }

    private function own(Item $item, int $quantity = 1): InventoryItem
    {
        return InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => $quantity]);
    }

    private function copies(int $itemId): int
    {
        return (int) InventoryItem::query()->where('character_id', $this->character->id)->where('item_id', $itemId)->sum('quantity');
    }

    private function running(): int
    {
        return InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('running_on_deck_id')->count();
    }

    public function test_a_script_runs_in_the_background_holds_its_cost_and_is_gone_when_its_time_is_up(): void
    {
        $row = $this->own($this->script(), 3);

        $this->assertTrue($this->runner->start($this->character, $row)->succeeded());
        $this->assertSame(1, $this->running());
        $this->assertEquals(['qflops' => 0.4, 'memory' => 0.4], $this->runner->spentOn($this->character, (int) $this->character->equipped_deck_id));

        $this->travel(599)->seconds();
        $this->assertCount(1, $this->character->fresh()->runningProcesses());

        $this->travel(2)->seconds();
        $this->assertCount(0, $this->character->fresh()->runningProcesses(), 'a spent Script is not running, whatever the sweep has done');
        $this->assertSame(0, $this->running(), 'and reading it removed the row');
        $this->assertSame(2, (int) $this->copies($row->item_id), 'two copies are left unspent');
    }

    public function test_only_one_of_a_script_runs_at_a_time_and_it_can_be_run_again_once_spent(): void
    {
        $script = $this->script();
        $row = $this->own($script, 2);

        $this->assertTrue($this->runner->start($this->character, $row)->succeeded());

        $second = $this->runner->start($this->character, $row->fresh());
        $this->assertSame(ProcessRefusal::NoFreeThread, $second->refusal);

        $this->travel(601)->seconds();

        $this->assertTrue($this->runner->start($this->character, $row->fresh())->succeeded(), 'the spent copy no longer holds the place');
    }

    public function test_stopping_a_script_early_destroys_it(): void
    {
        $row = $this->own($this->script(), 1);
        $this->runner->start($this->character, $row);
        $running = InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('running_on_deck_id')->sole();

        $this->runner->stop($this->character, $running);

        $this->assertSame(0, (int) $this->copies($row->item_id), 'nothing to put it back in');
    }

    public function test_its_modifier_works_only_while_it_lives(): void
    {
        $before = $this->character->stat('stealth')->value;
        $row = $this->own($this->script(modifiers: [['op' => 'percentage_of_base', 'amount' => 10, 'target' => ['id' => 'stealth']]]), 1);

        $this->runner->start($this->character, $row);
        $during = $this->character->fresh()->stat('stealth')->value;

        $this->travel(601)->seconds();

        $this->assertGreaterThan($before, $during);
        $this->assertSame($before, $this->character->fresh()->stat('stealth')->value);
    }

    public function test_the_sweep_spends_scripts_nobody_is_looking_at(): void
    {
        $this->runner->start($this->character, $this->own($this->script(), 1));
        $this->travel(601)->seconds();

        $this->assertSame(1, $this->running());
        $this->artisan('programs:expire')->assertSuccessful();
        $this->assertSame(0, $this->running());
        $this->assertSame(0, Lifetime::reapAll());
    }

    public function test_a_running_script_is_a_bar_in_current_processes(): void
    {
        $this->runner->start($this->character, $this->own($this->script(), 1));
        $this->travel(150)->seconds();

        $rows = collect(app(CurrentProcesses::class)->for($this->character->fresh()))->where('kind', 'script')->values();

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(0.25, $rows[0]['ratio'], 0.001);
        $this->assertSame(450, $rows[0]['seconds']);
    }
}
