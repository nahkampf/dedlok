<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\System;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * Run-only software is chosen on the briefing and started by the run.
 *
 * For eight releases nothing passed `$duringRun`, so no icebreaker could ever
 * run and every fight test wrote a running row by hand — which is how a gate
 * nobody could open went unnoticed. These tests go through the door a player
 * uses.
 */
final class IcebreakerLoadoutTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));

        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();

        System::factory()->at(8, 8)->grade(1)
            ->withIce(Ice::query()->where('level', 1)->sole())
            ->create(['address' => 'DEADBEEF']);

        // Every evasion check fails, so the ICE wakes and there is a fight.
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 1.0));
    }

    public function test_the_briefing_offers_the_icebreakers_you_hold(): void
    {
        $this->hold(1);

        $this->actingAs($this->character->user)
            ->get(route('run.setup'))
            ->assertOk()
            ->assertSee('name="icebreaker"', false)
            ->assertSee(Item::query()->where('slug', 'icebreaker-1')->value('name'));
    }

    public function test_the_icebreaker_chosen_on_the_briefing_runs_and_cuts(): void
    {
        $row = $this->hold(1);

        $this->actingAs($this->character->user)
            ->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value, 'icebreaker' => $row->id])
            ->assertRedirect(route('run.show'));

        $character = $this->character->fresh();
        $this->assertTrue($row->fresh()->isRunning(), 'the run started it');

        $result = $this->simulator()->take($character, $character->currentRun, RunAction::Attack);

        $this->assertLessThan(30, $result->run->ice_integrity, 'the cut landed');
    }

    public function test_leaving_the_run_puts_the_icebreaker_back(): void
    {
        $row = $this->hold(1);
        $run = app(RunStarter::class)->start($this->character, RunPurpose::Extraction, loadout: [$row])->run;

        app(RunStarter::class)->end($this->character->fresh(), $run, RunEnding::Egress);

        $this->assertFalse($row->fresh()->isRunning());
    }

    public function test_only_one_icebreaker_runs_at_a_time(): void
    {
        $runner = app(ProgramRunner::class);

        $this->assertTrue($runner->start($this->character, $this->hold(1), duringRun: true)->succeeded());

        $second = $runner->start($this->character, $this->hold(2), duringRun: true);

        $this->assertSame(ProcessRefusal::OneIcebreaker, $second->refusal);
    }

    public function test_a_loadout_that_will_not_start_refuses_the_whole_run(): void
    {
        $first = $this->hold(1);
        $second = $this->hold(2);

        $this->actingAs($this->character->user)
            ->post(route('run.start'), [
                'purpose' => RunPurpose::Extraction->value,
                'icebreaker' => $first->id,
                'programs' => [$second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('error', ProcessRefusal::OneIcebreaker->message());

        $this->assertNull($this->character->fresh()->current_run_id, 'no run began');
        $this->assertFalse($first->fresh()->isRunning(), 'what did start was put back');
    }

    public function test_a_loadout_that_went_in_is_remembered_as_items(): void
    {
        $row = $this->hold(1);

        $this->actingAs($this->character->user)
            ->post(route('run.start'), ['purpose' => RunPurpose::Extraction->value, 'icebreaker' => $row->id])
            ->assertCookie('run_loadout', (string) $row->item_id);
    }

    public function test_the_briefing_ticks_the_remembered_loadout(): void
    {
        $row = $this->hold(1);

        $this->actingAs($this->character->user)
            ->withCookie('run_loadout', (string) $row->item_id)
            ->get(route('run.setup'))
            ->assertOk()
            ->assertSee('name="icebreaker" value="'.$row->id.'" checked', false);
    }

    public function test_a_refused_loadout_is_not_remembered(): void
    {
        $this->actingAs($this->character->user)
            ->post(route('run.start'), [
                'purpose' => RunPurpose::Extraction->value,
                'icebreaker' => $this->hold(1)->id,
                'programs' => [$this->hold(2)->id],
            ])
            ->assertCookieMissing('run_loadout');
    }

    public function test_an_icebreaker_still_cannot_be_started_by_hand(): void
    {
        $result = app(ProgramRunner::class)->start($this->character, $this->hold(1));

        $this->assertSame(ProcessRefusal::RunOnly, $result->refusal);
    }

    private function hold(int $level): InventoryItem
    {
        return $this->ownedBy($this->character, Item::query()->where('slug', 'icebreaker-'.$level)->sole());
    }

    private function simulator(): Simulator
    {
        // 101 on a d100: the ICE always misses, so the test is only about the cut.
        return Simulator::fromConfig(
            static fn (int $min, int $max): int => min($max, 101),
            app(Resolver::class),
        );
    }
}
