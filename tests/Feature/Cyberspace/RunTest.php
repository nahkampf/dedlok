<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\Navigator;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunRefusal;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A run exists, holds the player, and can be left.
 *
 * The spine, and the riskiest part to get wrong — everything phase 5 adds hangs
 * off it. What is NOT here is the fight: no detection, no ICE, no extraction.
 */
final class RunTest extends TestCase
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

        System::factory()->at(8, 8)->grade(1)->create(['address' => 'DEADBEEF']);

        // Placed directly, the way ProbeTest does: the deck is equipped first
        // because the lock refuses to equip anything while somebody is inside.
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();
    }

    private function starter(): RunStarter
    {
        return app(RunStarter::class);
    }

    // ---- going in ----------------------------------------------------------

    public function test_a_run_begins_on_the_machine_underfoot(): void
    {
        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertTrue($result->succeeded());
        $this->assertSame('DEADBEEF', $result->run->address);
        $this->assertSame(RunPurpose::Extraction, $result->run->purpose);
        $this->assertTrue($result->run->isLive());

        $this->assertSame(
            $result->run->getKey(),
            $this->character->fresh()->current_run_id,
            'the character points at it, which is what "in a run" means',
        );
    }

    public function test_you_cannot_run_a_machine_from_outside_cyberspace(): void
    {
        Navigator::fromConfig(app(Resolver::class))->jackOut($this->character->fresh());

        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Burn);

        $this->assertSame(RunRefusal::NotJackedIn, $result->refusal);
    }

    public function test_there_has_to_be_something_there(): void
    {
        System::query()->where('address', 'DEADBEEF')->delete();

        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertSame(RunRefusal::NothingThere, $result->refusal);
    }

    /** One column holds one value, so a second run is refused rather than made. */
    public function test_a_second_run_is_refused(): void
    {
        $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Burn);

        $this->assertSame(RunRefusal::AlreadyRunning, $result->refusal);
        $this->assertSame(1, Run::query()->count());
    }

    /**
     * READINESS IS CHECKED TWICE and this is the second time — cheap, and it
     * closes the window between entering cyberspace and attacking something.
     */
    public function test_a_deck_that_stopped_running_cannot_start_a_run(): void
    {
        // Straight at the column rather than through DeckEquipper, which the
        // deck lock would refuse while somebody is inside — and being inside
        // is the state under test. No deck at all is the emptiest possible
        // failure of readiness and takes the same refusal.
        $this->character->forceFill(['equipped_deck_id' => null])->save();

        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertSame(RunRefusal::DeckNotReady, $result->refusal);
    }

    // ---- the gate you cannot see -------------------------------------------

    /**
     * A MACHINE WHOSE OS YOU LACK IS ENTERED, NOT REFUSED.
     *
     * A refusal naming the operating system would hand over a scan fact for one
     * click, with no instrument running and no minutes spent. So the run starts
     * and the verdict goes onto the row, where nothing shows it.
     */
    public function test_lacking_the_operating_system_does_not_refuse_the_run(): void
    {
        $os = Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'category' => 'Operating Systems', 'rank' => 16]);
        System::query()->where('address', 'DEADBEEF')->update(['os_skill_id' => $os->id]);

        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertTrue($result->succeeded(), 'entered, never refused');
        $this->assertTrue($result->run->os_locked, 'and the verdict is on the row');
    }

    public function test_a_machine_running_nothing_is_operable_by_anybody(): void
    {
        $result = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->assertFalse($result->run->os_locked);
    }

    // ---- the lock ----------------------------------------------------------

    /**
     * THIS IS WHAT REPLACES ATOMICITY. An interactive run a player could walk
     * out of is not a risk, it is a menu.
     */
    public function test_a_live_run_sends_every_other_request_back_to_it(): void
    {
        $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $user = $this->character->fresh()->user;

        $this->actingAs($user)->get(route('cyberspace.index'))->assertRedirect(route('run.show'));
        $this->actingAs($user)->get(route('deck.index'))->assertRedirect(route('run.show'));
        $this->actingAs($user)->get(route('inventory.index'))->assertRedirect(route('run.show'));
    }

    /** Signing out has to work, or a session can never end. It postpones a turn. */
    public function test_signing_out_is_still_reachable(): void
    {
        $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->actingAs($this->character->fresh()->user)
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertNotNull(
            Run::query()->sole()->ended_at === null ? Run::query()->sole() : null,
            'and the run is still there afterwards',
        );
    }

    /** A fetch() would follow a redirect into HTML, so JSON gets the URL instead. */
    public function test_a_json_caller_gets_409_and_the_way_back(): void
    {
        $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->actingAs($this->character->fresh()->user)
            ->getJson(route('cyberspace.reading', ['address' => 'DEADBEEF']))
            ->assertStatus(409)
            ->assertJsonPath('redirect', route('run.show'));
    }

    public function test_the_run_screen_itself_is_reachable(): void
    {
        $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        $this->actingAs($this->character->fresh()->user)
            ->get(route('run.show'))
            ->assertOk()
            ->assertSee('DEADBEEF');
    }

    // ---- getting out -------------------------------------------------------

    /**
     * A RUN WITH NO EXIT IS A CHARACTER DELETED BY A DOOR THEY COULD NOT SEE.
     * Leaving is always on the list.
     */
    public function test_egress_ends_the_run_and_releases_the_lock(): void
    {
        $this->starter()->start($this->character->fresh(), RunPurpose::Extraction);

        // LEAVING IS A ROLL NOW, so a test about what a successful one does to
        // the lock has to pin the die. Against a real one this passes about
        // four times in five, which is a test that fails on a Tuesday.
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        $this->actingAs($this->character->fresh()->user)
            ->post(route('run.egress'))
            ->assertRedirect(route('cyberspace.index'));

        $run = Run::query()->sole();

        $this->assertFalse($run->isLive());
        $this->assertSame(RunEnding::Egress->value, $run->ending);
        $this->assertNull($this->character->fresh()->current_run_id, 'the lock is off');

        // And the map is reachable again.
        $this->actingAs($this->character->fresh()->user)
            ->get(route('cyberspace.index'))
            ->assertOk();
    }

    /** Ending a run twice is not an error: the screen and the press are apart. */
    public function test_ending_a_finished_run_is_not_an_error(): void
    {
        $run = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction)->run;

        $this->starter()->end($this->character->fresh(), $run, RunEnding::Egress);
        $result = $this->starter()->end($this->character->fresh(), $run->fresh(), RunEnding::Defeat);

        $this->assertTrue($result->succeeded());
        $this->assertSame(RunEnding::Egress->value, $run->fresh()->ending, 'the first ending stands');
    }

    /** A run has to outlive its target: the row carries an address, never a key. */
    public function test_a_run_survives_its_machine_being_burned(): void
    {
        $run = $this->starter()->start($this->character->fresh(), RunPurpose::Extraction)->run;

        System::query()->where('address', 'DEADBEEF')->delete();

        $this->assertNull($run->fresh()->system(), 'nothing there is an ordinary answer');
        $this->assertTrue($run->fresh()->isLive(), 'and the row is still readable');

        $this->actingAs($this->character->fresh()->user)->get(route('run.show'))->assertOk();
    }
}
