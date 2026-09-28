<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Checks\CheckRoller;
use App\Cyberspace\RunAction;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Cyberspace\Simulator;
use App\Models\Character;
use App\Models\Entity;
use App\Models\EntityBook;
use App\Models\EntityLedgerEntry;
use App\Models\System;
use App\Models\SystemContact;
use App\Standing\Band;
use App\Standing\LedgerKind;
use App\Standing\StandingLedger;
use App\Standing\StandingReason;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * What an entity thinks of somebody, and the one rule that keeps two copies of
 * that number honest.
 *
 * THE BOOKS ARE ALWAYS THE SUM OF THE LEDGER. Every test here is ultimately
 * about that: clamping writes what landed, the drift writes an entry of its
 * own, and nothing moves a total without saying why.
 */
final class StandingTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $character;

    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 16);

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 20),
            ['archetype' => 3],
        ));
        $this->entity = Entity::query()->interactable()->firstOrFail();
    }

    private function ledger(): StandingLedger
    {
        return new StandingLedger;
    }

    // ---- the books ---------------------------------------------------------

    public function test_a_missing_row_reads_as_neutral_and_broke(): void
    {
        $this->assertSame(0.0, $this->ledger()->standingFor($this->character, $this->entity));
        $this->assertSame(0, $this->ledger()->loyaltyFor($this->character, $this->entity));
        $this->assertSame(0, EntityBook::query()->count());
    }

    public function test_an_act_moves_the_books_and_writes_its_reason(): void
    {
        $landed = $this->ledger()->record($this->character, $this->entity, StandingReason::Raided);

        $this->assertSame(StandingReason::Raided->amount(), $landed);
        $this->assertSame($landed, $this->ledger()->standingFor($this->character, $this->entity));

        $entry = EntityLedgerEntry::query()->sole();
        $this->assertSame(StandingReason::Raided, $entry->reason);
        $this->assertSame(LedgerKind::Standing, $entry->kind);
    }

    /**
     * SCENERY KEEPS NO BOOKS. Most of the grid belongs to companies nobody can
     * deal with, and a run against one of those is an ordinary thing to do
     * rather than an error to report — so this is a no-op and not a refusal.
     */
    public function test_a_flavour_entity_records_nothing(): void
    {
        $flavour = Entity::query()->where('interactable', false)->firstOrFail();

        $this->assertNull($this->ledger()->record($this->character, $flavour, StandingReason::Burned));
        $this->assertSame(0, EntityBook::query()->count());
        $this->assertSame(0, EntityLedgerEntry::query()->count());
    }

    public function test_no_entity_at_all_is_also_a_no_op(): void
    {
        $this->assertNull($this->ledger()->record($this->character, null, StandingReason::Noticed));
    }

    // ---- the invariant -----------------------------------------------------

    /**
     * CLAMPING TRIMS THE DELTA, NOT THE TOTAL. An act against somebody who
     * already hates you as much as they can writes a delta of zero, which says
     * honestly that it changed nothing — and keeps the sum equal to the books.
     */
    public function test_a_clamped_act_writes_what_landed(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::Burned, -99.5);
        $landed = $this->ledger()->record($this->character, $this->entity, StandingReason::Burned, -10.0);

        $this->assertSame(-0.5, $landed);
        $this->assertSame(-100.0, $this->ledger()->standingFor($this->character, $this->entity));

        $again = $this->ledger()->record($this->character, $this->entity, StandingReason::Burned, -1.0);
        $this->assertSame(0.0, $again, 'at the floor, an act lands as nothing');

        $this->assertSame([], $this->ledger()->verify());
    }

    public function test_the_ceiling_holds_the_same_way(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::MissionCompleted, 120.0);

        $this->assertSame(100.0, $this->ledger()->standingFor($this->character, $this->entity));
        $this->assertSame([], $this->ledger()->verify());
    }

    public function test_verify_catches_books_that_went_around_the_writer(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::Raided);

        // Exactly the thing the invariant exists to catch.
        EntityBook::query()->sole()->forceFill(['standing' => 42.0])->save();

        $wrong = $this->ledger()->verify();

        $this->assertCount(1, $wrong);
        $this->assertSame('standing', $wrong[0]['kind']);
        $this->assertSame(42.0, $wrong[0]['books']);
    }

    // ---- loyalty -----------------------------------------------------------

    public function test_loyalty_is_whole_floors_at_zero_and_has_no_ceiling(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::MissionCompleted, 250.0, LedgerKind::Loyalty);
        $this->assertSame(250, $this->ledger()->loyaltyFor($this->character, $this->entity));

        // Spending more than is owed lands as everything there was.
        $landed = $this->ledger()->record($this->character, $this->entity, StandingReason::StoreSpend, -400.0, LedgerKind::Loyalty);

        $this->assertSame(-250.0, $landed);
        $this->assertSame(0, $this->ledger()->loyaltyFor($this->character, $this->entity));
        $this->assertSame([], $this->ledger()->verify());
    }

    public function test_the_two_numbers_do_not_touch_each_other(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::MissionCompleted, 5.0, LedgerKind::Loyalty);
        $this->ledger()->record($this->character, $this->entity, StandingReason::Burned, -2.0);

        $this->assertSame(5, $this->ledger()->loyaltyFor($this->character, $this->entity));
        $this->assertSame(-2.0, $this->ledger()->standingFor($this->character, $this->entity));
    }

    // ---- the drift ---------------------------------------------------------

    /**
     * PROPORTIONAL, from both directions, and never past zero. A flat rate at
     * the size of a mission's reward would erase a day's work at the bottom of
     * the ladder and cost nothing at the top.
     */
    public function test_the_nightly_drift_takes_a_percentage_toward_neutral(): void
    {
        Config::set('standing.drift.nightly', 0.005);

        $this->ledger()->record($this->character, $this->entity, StandingReason::MissionCompleted, 60.0);
        $this->ledger()->drift();

        $this->assertSame(59.7, $this->ledger()->standingFor($this->character, $this->entity));

        // And it writes its own entry, or the sum would stop matching.
        $this->assertSame(1, EntityLedgerEntry::query()->where('reason', StandingReason::Decay)->count());
        $this->assertSame([], $this->ledger()->verify());
    }

    public function test_the_drift_lifts_a_negative_standing(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::Burned, -40.0);
        $this->ledger()->drift();

        $this->assertSame(-39.9, $this->ledger()->standingFor($this->character, $this->entity));
    }

    public function test_the_drift_leaves_loyalty_alone(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::MissionCompleted, 100.0, LedgerKind::Loyalty);
        $this->ledger()->drift();

        $this->assertSame(100, $this->ledger()->loyaltyFor($this->character, $this->entity));
    }

    public function test_the_command_runs_and_verifies(): void
    {
        $this->ledger()->record($this->character, $this->entity, StandingReason::MissionCompleted, 10.0);

        $this->artisan('standing:drift')->assertSuccessful();
        $this->artisan('standing:verify')->assertSuccessful();
    }

    // ---- the hooks in the run ---------------------------------------------

    /**
     * THE ENDINGS ALREADY KNOW THE OWNER, which is the whole reason standing
     * needed no new plumbing in the simulator: being seen, taking something
     * and burning a system are three calls at sites that already had the row
     * in hand.
     */
    public function test_being_noticed_on_their_system_costs_standing(): void
    {
        System::factory()->at(8, 8)->grade(1)->withIds(500)->ownedBy($this->entity)->create();

        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();

        $result = app(RunStarter::class)->start($this->character->fresh(), RunPurpose::Extraction);
        $this->assertNotNull($result->run, 'run refused: '.($result->refusal?->name ?? '?'));
        $run = $result->run;

        // Pinned so the IDS certainly notices: what is under test is the
        // charge, not the roll.
        $this->instance(CheckRoller::class, CheckRoller::fromConfig(static fn (): float => 0.0));

        app(Simulator::class)->take($this->character->fresh(), $run->fresh(), RunAction::Hold);

        $this->assertLessThan(0.0, $this->ledger()->standingFor($this->character, $this->entity));
        $this->assertSame([], $this->ledger()->verify());
    }

    // ---- what the screens say ---------------------------------------------

    /**
     * Stand on a system, with the sighting arriving does.
     *
     * The map draws from MEMORY — a contact — and the standing tag sits in
     * that block because it describes the system somebody is looking at. In
     * play the arrival sweep writes the contact; here it is written directly,
     * which keeps the test about standing rather than about navigation.
     */
    private function standOn(Entity $owner): System
    {
        $system = System::factory()->at(8, 8)->grade(1)->ownedBy($owner)->create();

        $this->equipReadyDeck($this->character);
        $this->character->forceFill(['grid_x' => 8, 'grid_y' => 8])->save();
        $this->character->refresh();

        SystemContact::query()->create([
            'character_id' => $this->character->id,
            'x' => 8,
            'y' => 8,
            'address' => $system->address,
            'name' => $system->displayName(),
            'type' => $system->type,
            'owner' => $owner->name,
            'seen_at' => now(),
            'first_seen_at' => now(),
        ]);

        return $system;
    }

    /**
     * THE FIRST READER, and the reason this shipped with one: heat was a
     * number nothing read for four releases. A card says who answers for the
     * system under you and what they make of you; the figure is the tag's
     * title, because a band is what somebody can act on.
     */
    public function test_the_map_says_what_the_owner_makes_of_you(): void
    {
        $this->standOn($this->entity);

        $this->ledger()->record($this->character, $this->entity, StandingReason::Burned, -30.0);

        $this->actingAs($this->character->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertSeeText(Text::get('entities.standing_with', [
                'band' => Band::Hated->label(),
                'entity' => $this->entity->name,
            ]));
    }

    public function test_the_briefing_says_it_too(): void
    {
        $this->standOn($this->entity);

        $this->actingAs($this->character->user)
            ->get(route('run.setup'))
            ->assertOk()
            ->assertSeeText(Text::get('entities.standing_with', [
                'band' => Band::Neutral->label(),
                'entity' => $this->entity->name,
            ]));
    }

    /** Scenery has no opinion, so the card says nothing about one. */
    public function test_a_flavour_owner_is_not_mentioned(): void
    {
        $flavour = Entity::query()->where('interactable', false)->firstOrFail();
        $this->standOn($flavour);

        $this->actingAs($this->character->user)
            ->get(route('cyberspace.index'))
            ->assertOk()
            ->assertDontSeeText(Text::get('entities.standing_with', [
                'band' => Band::Neutral->label(),
                'entity' => $flavour->name,
            ]));
    }

    // ---- the words -------------------------------------------------------

    public function test_a_figure_reads_as_a_band(): void
    {
        $this->assertSame(Band::Neutral, Band::for(0.0));
        $this->assertSame(Band::Cold, Band::for(-0.5));
        $this->assertSame(Band::Known, Band::for(5.0));
        $this->assertSame(Band::Hostile, Band::for(-100.0));
        $this->assertSame(Band::Allied, Band::for(100.0));

        $this->assertNotSame('', Band::Hated->label());
    }
}
