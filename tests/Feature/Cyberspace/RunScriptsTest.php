<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\RunPurpose;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** Scripts are offered on the run briefing and started with the loadout, before the run begins. */
final class RunScriptsTest extends TestCase
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

    private function script(float $memory = 0.4): InventoryItem
    {
        $item = Item::factory()->program(ProgramKind::Script, qflops: 0.4, memory: $memory, storage: 0.4, modifiers: [
            ['op' => 'add', 'amount' => 5, 'target' => ['id' => 'stealth']],
        ], lifetime: 600)->create();

        return $this->ownedBy($this->character, $item);
    }

    private function start(array $data): TestResponse
    {
        return $this->actingAs($this->character->user)->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value] + $data);
    }

    public function test_the_briefing_lists_a_script_with_its_cost_and_a_box_to_tick(): void
    {
        $row = $this->script();

        $this->actingAs($this->character->user)
            ->get(route('run.setup'))
            ->assertOk()
            ->assertSee('name="scripts[]" value="'.$row->id.'"', false)
            ->assertSee($row->item->displayName())
            ->assertSee('0.4 qFLOPS');
    }

    public function test_a_ticked_script_is_running_when_the_run_begins(): void
    {
        $row = $this->script();

        $this->start(['scripts' => [$row->id]])->assertRedirect(route('run.show'));

        $this->assertNotNull($this->character->fresh()->current_run_id);
        $this->assertTrue($row->fresh()->isRunning());
    }

    public function test_a_script_that_does_not_fit_refuses_the_run_and_is_not_spent(): void
    {
        $fits = $this->script();
        $toobig = $this->script(memory: 500.0);

        $this->start(['scripts' => [$fits->id, $toobig->id]])
            ->assertRedirect()
            ->assertSessionHas('error', ProcessRefusal::NotEnoughMemory->message());

        $this->assertNull($this->character->fresh()->current_run_id, 'no run began');
        $this->assertNotNull($fits->fresh(), 'the script that did fit was not destroyed by the rollback');
        $this->assertFalse($fits->fresh()->isRunning());
    }

    public function test_a_row_that_is_not_a_script_is_ignored(): void
    {
        $icebreaker = $this->ownedBy($this->character, Item::query()->where('slug', 'icebreaker-1')->sole());

        $this->start(['scripts' => [$icebreaker->id]])->assertRedirect(route('run.show'));

        $this->assertFalse($icebreaker->fresh()->isRunning(), 'only the icebreaker field starts one');
    }
}
