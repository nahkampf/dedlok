<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Characters\DownedCause;
use App\Characters\Flags;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Cyberspace\Address;
use App\Cyberspace\BookmarkKeeper;
use App\Cyberspace\Navigator;
use App\Cyberspace\Party\Parties;
use App\Cyberspace\Party\PartyRefusal;
use App\Cyberspace\Position;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Cyberspace\SystemType;
use App\Cyberspace\Terrain;
use App\Cyberspace\TravelRefusal;
use App\Models\Bookmark;
use App\Models\Character;
use App\Models\CharacterModifier;
use App\Models\Run;
use App\Models\System;
use App\Models\SystemContact;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A pair of gateways joins the main grid to a subnet (docs/CYBERSPACE.md, *Subnets*, step 2): passing is an arrival at the other end
 * on the other grid, an address on another grid answers to nothing, and a subnet remembers where you jacked out until a flatline.
 */
final class GatewayTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private System $door;

    private System $mirror;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);
        Config::set('cyberspace.view_range', 2);

        DB::table('grids')->insert(['id' => 2, 'slug' => 'mil', 'name' => 'Military', 'prefix' => 'mil', 'size' => 8, 'entry_x' => 4, 'entry_y' => 4, 'created_at' => now(), 'updated_at' => now()]);

        $this->door = System::factory()->at(2, 2)->create(['type' => SystemType::Gateway, 'address' => 'DEADBEEF']);
        $this->mirror = System::factory()->at(4, 4)->create(['type' => SystemType::Gateway, 'address' => 'mil:0001', 'grid_id' => 2]);
        $this->door->update(['links_to_system_id' => $this->mirror->id]);
        $this->mirror->update(['links_to_system_id' => $this->door->id]);

        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);
    }

    private function navigator(): Navigator
    {
        return Navigator::fromConfig(app(Resolver::class));
    }

    /** Jacked in and standing on the main grid's gateway. */
    private function atTheDoor(): Character
    {
        $this->navigator()->jackIn($this->character);
        $this->character->forceFill(['grid_x' => 2, 'grid_y' => 2])->save();

        return $this->character->fresh();
    }

    public function test_an_address_is_eight_hex_or_a_prefix_and_four(): void
    {
        $this->assertSame('mil:3FCD', Address::normalise(' MIL:3fcd '));
        $this->assertSame('DEADBEEF', Address::normalise('deadbeef'));
        $this->assertNull(Address::normalise('m:3FCD'));
        $this->assertNull(Address::normalise('toolong:3FCD'));
        $this->assertNull(Address::normalise('mil:3FC'));
        $this->assertSame('mil:0A0B', Address::generate(fn () => "\x0A\x0B", 'mil'));
    }

    public function test_passing_arrives_at_the_other_end_and_what_is_seen_there_is_remembered_on_that_grid(): void
    {
        $result = $this->navigator()->pass($this->atTheDoor());

        $this->assertTrue($result->succeeded());
        $character = $this->character->fresh();
        $this->assertSame(2, (int) $character->grid_id);
        $this->assertTrue($character->gridPosition()->equals(new Position(4, 4, 2)));
        $this->assertSame(2, (int) SystemContact::query()->where('address', 'mil:0001')->value('grid_id'));
        $this->assertTrue((new Terrain($character))->hasSwept(new Position(4, 4, 2)));
        $this->assertFalse((new Terrain($character))->hasSwept(new Position(4, 4)));

        // And back out through the mirror.
        $this->assertTrue($this->navigator()->pass($character)->succeeded());
        $this->assertTrue($this->character->fresh()->gridPosition()->equals(new Position(2, 2, 1)));
    }

    public function test_passing_needs_a_gateway_under_you(): void
    {
        $this->navigator()->jackIn($this->character);

        $this->assertSame(TravelRefusal::NoGateway, $this->navigator()->pass($this->character->fresh())->refusal);
    }

    public function test_steps_stay_on_the_subnet_and_stop_at_its_edge(): void
    {
        $this->navigator()->pass($this->atTheDoor());
        $character = $this->character->fresh();

        $this->assertTrue($this->navigator()->step($character, new Position(5, 4))->succeeded());
        $this->assertSame(2, (int) $character->fresh()->grid_id);

        $character->forceFill(['grid_x' => 7, 'grid_y' => 7])->save();
        $this->assertSame(TravelRefusal::OffTheGrid, $this->navigator()->step($character->fresh(), new Position(8, 7))->refusal);
    }

    public function test_an_address_on_another_grid_answers_to_nothing(): void
    {
        $this->navigator()->jackIn($this->character);

        $this->assertSame(TravelRefusal::NoSuchAddress, $this->navigator()->jump($this->character->fresh(), 'mil:0001')->refusal);

        $this->navigator()->pass($this->atTheDoor());

        $this->assertSame(TravelRefusal::NoSuchAddress, $this->navigator()->jump($this->character->fresh(), 'DEADBEEF')->refusal);
    }

    public function test_a_waypoint_on_the_subnet_belongs_to_it(): void
    {
        $this->navigator()->pass($this->atTheDoor());
        $character = $this->character->fresh();

        $saved = BookmarkKeeper::fromConfig()->markPosition($character, new Position(4, 4, 2));

        $this->assertTrue($saved->succeeded());
        $this->assertSame(2, (int) Bookmark::query()->first()->grid_id);

        // From the main grid it is not a place you can recall.
        $this->navigator()->pass($character);
        $this->assertSame(TravelRefusal::OffTheGrid, $this->navigator()->recall($this->character->fresh(), Bookmark::query()->first())->refusal);
    }

    public function test_jacking_out_of_a_subnet_resumes_there_and_a_flatline_does_not(): void
    {
        $this->navigator()->pass($this->atTheDoor());
        $this->navigator()->step($this->character->fresh(), new Position(5, 4));
        $this->navigator()->jackOut($this->character->fresh());

        $out = $this->character->fresh();
        $this->assertFalse($out->isJackedIn());
        $this->assertSame([5, 4], [(int) $out->resume_x, (int) $out->resume_y]);

        $this->assertTrue($this->navigator()->jackIn($out)->succeeded());
        $this->assertTrue($this->character->fresh()->gridPosition()->equals(new Position(5, 4, 2)));

        // Emptied of SI inside: out, and the resume point goes.
        $this->navigator()->jackOut($this->character->fresh());
        $down = $this->character->fresh();
        $down->forceFill(['current_synaptic_integrity' => 0])->save();
        app(WaitingRoom::class)->checkFloor($down, new DownedCause(reason: 'test'));

        $this->assertSame(1, (int) $this->character->fresh()->grid_id);
        $this->assertNull($this->character->fresh()->resume_x);
    }

    public function test_a_main_grid_jack_out_keeps_nothing(): void
    {
        $this->navigator()->jackIn($this->character);
        $this->navigator()->jackOut($this->character->fresh());

        $this->assertNull($this->character->fresh()->resume_x);
        $this->assertTrue($this->navigator()->jackIn($this->character->fresh())->position->equals(new Position(8, 8)));
    }

    public function test_the_map_offers_the_pass_on_a_gateway_and_the_button_goes_through(): void
    {
        $user = $this->character->user;
        $this->atTheDoor();

        $this->actingAs($user)->get(route('cyberspace.index'))->assertSee(route('cyberspace.pass'), false);

        $this->actingAs($user)->post(route('cyberspace.pass'))->assertRedirect();
        $this->assertSame(2, (int) $this->character->fresh()->grid_id);
        $this->actingAs($user)->get(route('cyberspace.index'))->assertOk();
    }

    public function test_a_gateway_with_a_flag_rule_opens_only_for_those_who_hold_the_flag(): void
    {
        $this->door->update(['entry_rules' => [['kind' => 'flag', 'flag' => 'subnet_mil_access']]]);

        $this->assertSame(TravelRefusal::GatewayClosed, $this->navigator()->pass($this->atTheDoor())->refusal);
        $this->assertSame(1, (int) $this->character->fresh()->grid_id);

        Flags::set($this->character, 'subnet_mil_access');

        $this->assertTrue($this->navigator()->pass($this->character->fresh())->succeeded());

        // The subnet's own end has no rules: out is always allowed.
        $this->assertTrue($this->navigator()->pass($this->character->fresh())->succeeded());
    }

    public function test_a_rule_nothing_understands_passes_nobody(): void
    {
        $this->door->update(['entry_rules' => [['kind' => 'nonsense']]]);

        $this->assertSame(TravelRefusal::GatewayClosed, $this->navigator()->pass($this->atTheDoor())->refusal);
    }

    private function dice(float $roll): void
    {
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => $roll));
    }

    public function test_a_gateway_takes_infiltrate_and_nothing_else_and_nothing_else_takes_it(): void
    {
        $this->atTheDoor();
        $starter = app(RunStarter::class);

        $this->assertSame(RunRefusal::WrongPurpose, $starter->start($this->character->fresh(), RunPurpose::Extraction)->refusal);
        $this->assertTrue($starter->start($this->character->fresh(), RunPurpose::Infiltrate)->succeeded());

        // And the other way round: an ordinary system will not be infiltrated.
        $this->character->fresh()->forceFill(['current_run_id' => null])->save();
        Run::query()->delete();
        System::factory()->at(3, 3)->type(SystemType::Silo)->create();
        $this->character->forceFill(['grid_x' => 3, 'grid_y' => 3, 'current_run_id' => null])->save();

        $this->assertSame(RunRefusal::WrongPurpose, $starter->start($this->character->fresh(), RunPurpose::Infiltrate)->refusal);
    }

    public function test_a_gateway_cannot_be_scanned_or_run_as_a_party(): void
    {
        $this->assertFalse(SystemType::Gateway->isScannable());
        $this->assertTrue(SystemType::Gateway->isDefended());

        $this->atTheDoor();
        Config::set('effects.coordinating', [
            'label' => 'Coordinating',
            'stacking' => 'unique',
            'retired' => false,
            'modifiers' => [['op' => 'add', 'amount' => 3, 'target' => ['id' => 'coop_slots']]],
        ]);
        CharacterModifier::query()->create(['character_id' => $this->character->id, 'definition_key' => 'coordinating']);

        $this->assertSame(PartyRefusal::TooNoisy, app(Parties::class)->open($this->character->fresh()));
    }

    public function test_surviving_every_check_in_a_row_opens_the_gateway_for_a_while(): void
    {
        $this->door->update(['entry_rules' => [['kind' => 'infiltrate']]]);
        $this->dice(0.0);
        $this->atTheDoor();
        app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Infiltrate);

        $this->assertSame(TravelRefusal::GatewayClosed, $this->navigator()->pass($this->character->fresh())->refusal);

        $turns = (int) config('cyberspace.infiltrate.turns');
        $last = null;

        for ($i = 0; $i < $turns; $i++) {
            $last = Simulator::fromConfig()->take($this->character->fresh(), $this->character->fresh()->currentRun ?? Run::query()->latest('id')->first(), RunAction::Infiltrate);
        }

        $this->assertSame(RunEnding::Completed, $last->ending);
        $this->assertNull($this->character->fresh()->current_run_id);
        $this->assertTrue($this->navigator()->pass($this->character->fresh())->succeeded());
        $this->assertSame(2, (int) $this->character->fresh()->grid_id);
    }

    public function test_a_failed_check_wakes_the_watch_and_costs_the_progress(): void
    {
        $this->atTheDoor();
        $this->dice(0.0);
        app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Infiltrate);
        $run = Run::query()->latest('id')->first();

        Simulator::fromConfig()->take($this->character->fresh(), $run, RunAction::Infiltrate);
        $this->assertSame(1, (int) $run->fresh()->infiltrated);

        $this->dice(0.9999);
        Simulator::fromConfig()->take($this->character->fresh(), $run->fresh(), RunAction::Infiltrate);

        $this->assertSame(0, (int) $run->fresh()->infiltrated);
        $this->assertTrue($run->fresh()->detected);
    }

    public function test_the_way_out_cannot_be_run_against(): void
    {
        $this->navigator()->pass($this->atTheDoor());

        $result = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Infiltrate);

        $this->assertSame(RunRefusal::NotIntrudable, $result->refusal);
    }
}
