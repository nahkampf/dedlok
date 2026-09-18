<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\Lockdown;
use App\Cyberspace\RunPurpose;
use App\Models\Character;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * The briefing: everything decided before a run, and everything not known.
 *
 * The warnings are the product. A run cannot be taken back — the purpose is
 * fixed at the door, a backdoor's kind is permanent, the route is snapshotted
 * — so the screen's job is to say what is unread, what the deck is about to do
 * to itself, and what is not running.
 */
final class RunBriefingTest extends TestCase
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
    }

    // ---- getting to it ------------------------------------------------------

    public function test_it_draws_the_machine_you_are_standing_on(): void
    {
        $system = $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee($system->displayName())
            ->assertSee($system->address);
    }

    /** Nothing here is nothing to plan against. */
    public function test_it_turns_you_away_from_empty_ground(): void
    {
        $this->visit()->assertRedirect(route('cyberspace.index'));
    }

    public function test_it_turns_away_somebody_who_is_not_in_cyberspace(): void
    {
        $this->machine();
        $this->character->forceFill(['grid_x' => null, 'grid_y' => null])->save();

        $this->visit()->assertRedirect(route('cyberspace.index'));
    }

    /**
     * SOMEBODY IN A RUN IS SENT TO THE RUN. Planning the next one from inside
     * this one is not a thing to do, and the lock already says so — this is
     * the assertion that the briefing was not accidentally added to its
     * allow-list.
     */
    public function test_the_run_lock_holds_it_too(): void
    {
        $this->machine();

        $run = Run::query()->create([
            'character_id' => $this->character->id,
            'address' => 'DEADBEEF',
            'x' => 8, 'y' => 8,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'started_at' => now(),
        ]);
        $this->character->forceFill(['current_run_id' => $run->id])->save();

        $this->visit()->assertRedirect(route('run.show'));
    }

    // ---- what is not known --------------------------------------------------

    /** An unread machine says so, because a blank column reads as "nothing there". */
    public function test_it_says_when_nothing_has_been_read(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee('You have read nothing off this machine')
            ->assertSee('Not read');
    }

    public function test_a_partial_file_is_called_incomplete(): void
    {
        $system = $this->machine();
        $this->scan($system, ['grade' => 1]);

        $this->visit()
            ->assertOk()
            ->assertSee('Your file on this machine is incomplete')
            // The fact that IS known is shown beside the ones that are not.
            ->assertSee('Not read');
    }

    public function test_a_complete_file_says_so(): void
    {
        $system = $this->machine();
        // Every discoverable fact answered. Zero is a real answer here — "it
        // has none" — which is the whole reason these columns are nullable.
        // The operating system is the exception: null means NOT READ there,
        // so a complete file has to name the skill the machine runs.
        $this->scan($system, [
            'grade' => 1,
            'os_skill_id' => $system->os_skill_id ?? Skill::query()->value('id'),
            'ids_detection' => 0,
            'tracer_detection' => 0,
            'tracer_rate' => 0,
            'ice_level' => 0,
            'ice_bounty' => 0,
        ]);

        $this->visit()->assertOk()->assertSee('Your file is complete');
    }

    // ---- what the deck is about to do ---------------------------------------

    public function test_it_names_what_is_running_and_what_each_thing_is(): void
    {
        $this->machine();
        $item = $this->runProgram('icebreaker-1');

        $this->visit()
            ->assertOk()
            ->assertSee($item->displayName())
            ->assertSee('Icebreaker');
    }

    /**
     * NO ICEBREAKER AMONG WHAT IS RUNNING is the warning that decides whether
     * a fight is survivable — and it is worth saying even when something else
     * is, because a deck full of analysers still cannot cut.
     */
    public function test_it_warns_when_nothing_running_is_an_icebreaker(): void
    {
        $this->machine();
        $this->runProgram('scanner-mk1');

        $this->visit()->assertOk()->assertSee('If something wakes up, you cannot fight it');
    }

    /** And says nothing of the sort when one IS running. */
    public function test_it_does_not_warn_when_an_icebreaker_is_running(): void
    {
        $this->machine();
        $this->runProgram('icebreaker-1');

        $this->visit()->assertOk()->assertDontSee('you cannot fight it');
    }

    public function test_it_says_when_nothing_is_running_at_all(): void
    {
        $this->machine();

        $this->visit()->assertOk()->assertSee('Nothing is running');
    }

    public function test_a_cool_deck_says_thermals_are_fine(): void
    {
        $this->machine();

        $this->visit()->assertOk()->assertSee('Thermals are fine');
    }

    // ---- the intentions ------------------------------------------------------

    public function test_it_offers_every_intention_there_is(): void
    {
        $this->machine();

        $this->visit()
            ->assertOk()
            ->assertSee('Take what it holds')
            ->assertSee('Leave a siphon')
            ->assertSee('Leave a routing hop')
            // Unbuilt, and shown saying so rather than left out.
            ->assertSee('Burn it')
            ->assertSee('Not built yet');
    }

    /** Holding one already is worth saying: the approach is free from now on. */
    public function test_it_says_when_you_already_hold_the_machine(): void
    {
        $system = $this->machine();
        (new Backdoors)->install($this->character, $system, BackdoorKind::Siphon);

        $this->visit()
            ->assertOk()
            ->assertSee('You already hold a way into this one')
            ->assertSee('that cannot be changed');
    }

    /** A shut machine cannot be entered, and the briefing refuses before the run does. */
    public function test_a_shut_machine_offers_nothing(): void
    {
        $system = $this->machine();
        Lockdown::fromConfig(static fn (int $min, int $max): int => $max)->shut($system);

        $this->visit()
            ->assertOk()
            ->assertSee('It is locked down')
            ->assertDontSee('Take what it holds');
    }

    // ---- fixtures ------------------------------------------------------------

    private function machine(?int $iceLevel = null): System
    {
        $factory = System::factory()->at(8, 8)->grade(1)->withTracer(2, 4);

        if ($iceLevel !== null) {
            $factory = $factory->withIce(Ice::query()->where('level', $iceLevel)->sole());
        }

        return $factory->create(['address' => 'DEADBEEF']);
    }

    /** @param  array<string, mixed>  $known */
    private function scan(System $system, array $known): void
    {
        SystemScan::query()->create(array_merge([
            'character_id' => $this->character->id,
            'address' => $system->address,
            'x' => $system->x,
            'y' => $system->y,
            'passes' => 1,
            'scanned_at' => now(),
            'expires_at' => now()->addDay(),
        ], $known));
    }

    private function runProgram(string $slug): Item
    {
        $item = Item::query()->where('slug', $slug)->sole();

        InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'running_on_deck_id' => $this->character->equipped_deck_id,
            'started_at' => now(),
        ]);

        return $item;
    }

    private function visit()
    {
        return $this->actingAs($this->character->fresh()->user)->get(route('run.setup'));
    }
}
