<?php

declare(strict_types=1);

namespace Tests\Feature\Missions;

use App\Cyberspace\ExtractedFile;
use App\Cyberspace\Extractor;
use App\Cyberspace\Hunters;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunPurpose;
use App\Cyberspace\RunStarter;
use App\Missions\MissionAction;
use App\Missions\MissionBoard;
use App\Missions\MissionLedger;
use App\Missions\MissionRefusal;
use App\Missions\MissionState;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\Ice;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Run;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContent;
use App\Notifications\Category;
use App\Standing\StandingLedger;
use App\Stats\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\HasRoomForData;
use Tests\TestCase;

/**
 * Work done for an entity.
 *
 * THE TWO THINGS THIS FILE EXISTS FOR: a board is rolled per character and
 * never shared, and the three endings cost three different things — completing
 * pays, failing charges, and voiding does neither because a machine somebody
 * else burned was never this runner's fault.
 */
final class MissionTest extends TestCase
{
    use HasRoomForData;
    use RefreshDatabase;

    private Character $character;

    private Entity $entity;

    private Exchange $desk;

    /** Every fixture system needs its own cell; this is the next free one. */
    private int $placed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('missions.board.size', ['min' => 2, 'max' => 2]);

        $this->character = Character::factory()->create(['creds' => 0]);

        // Entities arrive by migration rather than a factory — ten of them are
        // somebody, and this takes the first that can be dealt with.
        $this->entity = Entity::query()->interactable()->firstOrFail();

        $deskSystem = System::factory()->at(8, 8)->ownedBy($this->entity)->create([
            'type' => 'exchange',
            'grade' => 1,
        ]);

        $this->desk = Exchange::query()->create([
            'system_id' => $deskSystem->id,
            'grade' => 1,
            'agent' => 'Mr Nakata',
            'min_standing' => 0,
        ]);
    }

    /** A run that woke the ICE and left, shutting the machine, ticks an Agitate target; an unnoticed one does not. */
    public function test_a_run_that_wakes_the_ice_and_leaves_agitates_the_target(): void
    {
        $mission = $this->accepted(MissionAction::Agitate);
        $target = $mission->targets->first()->system;
        $target->forceFill(['ice_id' => Ice::query()->firstOrFail()->id])->save();

        $quiet = $this->runAt($target, detected: false);
        app(RunStarter::class)->end($this->character, $quiet, RunEnding::Egress);
        $this->assertNull($mission->fresh()->targets->first()->satisfied_at, 'unnoticed: nothing woke');

        $loud = $this->runAt($target, detected: true);
        app(RunStarter::class)->end($this->character->fresh(), $loud, RunEnding::Egress);

        $this->assertNotNull($mission->fresh()->targets->first()->satisfied_at);
        $this->assertTrue($target->fresh()->isLockedDown());
    }

    private function runAt(System $system, bool $detected): Run
    {
        $run = Run::query()->create([
            'character_id' => $this->character->id,
            'address' => $system->address,
            'x' => $system->x, 'y' => $system->y,
            'purpose' => RunPurpose::Extraction->value,
            'turn' => 1,
            'detected' => $detected,
            'started_at' => now(),
        ]);
        $this->character->forceFill(['current_run_id' => $run->id])->save();

        return $run;
    }

    /** The file is seeded for the runner whatever the node held, only they can pull it, and it is handed in decrypted. */
    public function test_an_extraction_job_seeds_a_file_for_its_runner_and_is_handed_in_decrypted(): void
    {
        $mission = $this->accepted(MissionAction::Extract);
        $system = $mission->targets->first()->system;
        $stranger = Character::factory()->create();

        $this->ledger()->placeJobFile($this->character, $system);
        $this->ledger()->placeJobFile($this->character, $system);   // seeded once

        $this->assertSame(1, SystemContent::query()->where('mission_id', $mission->id)->count());
        $this->assertSame(0.0, (new Extractor)->totalFileQb($system->address, $stranger), 'it is nobody else\'s');
        $this->assertGreaterThan(0.0, (new Extractor)->totalFileQb($system->address, $this->character));

        // Delivered straight, as a party deal or a withdrawal does: this test has no deck to hold it.
        $content = SystemContent::query()->where('mission_id', $mission->id)->firstOrFail();
        (new Extractor)->deliver($this->character, $content);

        $row = InventoryItem::query()->where('character_id', $this->character->id)->whereNotNull('extracted_file')->firstOrFail();
        $this->assertSame($mission->id, ExtractedFile::fromArray($row->extracted_file)?->missionId);

        $this->assertSame(MissionRefusal::NotDone, $this->ledger()->complete($this->character->fresh(), $mission->fresh()), 'still sealed');

        $row->forceFill(['item_id' => Item::query()->where('slug', ExtractedFile::DECRYPTED_SLUG)->firstOrFail()->id])->save();

        $done = $this->ledger()->complete($this->character->fresh(), $mission->fresh());
        $this->assertInstanceOf(Mission::class, $done);
        $this->assertSame(MissionState::Completed, $done->state);
        $this->assertSame(0, InventoryItem::query()->whereNotNull('extracted_file')->count(), 'the file is spent');
    }

    /** A heist's take is held for the desk, ticks the target, and a tenth comes back on top of the reward. */
    public function test_a_heist_holds_the_take_and_pays_back_a_cut(): void
    {
        $mission = $this->accepted(MissionAction::Heist);
        $first = $mission->targets->first();
        $second = $mission->targets->last();
        $other = System::factory()->at(60, 60)->create(['type' => 'silo', 'grade' => 2]);

        $extractor = new Extractor;
        $extractor->payCreds($this->character, 1000, $first->system_id);
        $extractor->payCreds($this->character, 500, $other->id);   // not the job's: an ordinary haul

        $this->assertSame(500, (int) $this->character->fresh()->creds);
        $this->assertSame(1000, (int) $mission->fresh()->proceeds);
        $this->assertNotNull($first->fresh()->satisfied_at);

        $this->assertSame(MissionRefusal::NotDone, $this->ledger()->complete($this->character->fresh(), $mission->fresh()), 'one target left');

        $extractor->payCreds($this->character->fresh(), 2000, $second->system_id);
        $done = $this->ledger()->complete($this->character->fresh(), $mission->fresh());

        $this->assertInstanceOf(Mission::class, $done);
        // 500 raided elsewhere + the 500 reward + 10% of the 3000 handed in
        $this->assertSame(500 + 500 + 300, (int) $this->character->fresh()->creds);
    }

    public function test_a_transport_hands_over_a_container_and_raises_heat_until_it_ends(): void
    {
        $this->giveRoomForData($this->character);
        $this->character->forceFill(['heat' => 10.0])->save();

        $mission = $this->accepted(MissionAction::Transport);

        $this->assertSame(1, InventoryItem::query()->whereHas('item', fn ($q) => $q->where('slug', 'sealed-data-container'))->count());
        $this->assertSame(100.0, (float) $this->character->fresh()->heat);
        $this->assertSame(90.0, (float) $mission->fresh()->heat_added);

        // Not a desk's to hand in.
        $this->assertSame(MissionRefusal::NotDone, $this->ledger()->complete($this->character->fresh(), $mission->fresh()));

        // Standing on the target with the container: upload finishes it, pays, takes the container and the heat.
        $target = $mission->targets->first()->system;
        $this->character->forceFill(['grid_x' => $target->x, 'grid_y' => $target->y])->save();

        $done = $this->ledger()->deliver($this->character->fresh());

        $this->assertInstanceOf(Mission::class, $done);
        $this->assertSame(MissionState::Completed, $done->state);
        $this->assertSame(500, (int) $this->character->fresh()->creds);
        $this->assertSame(10.0, (float) $this->character->fresh()->heat);
        $this->assertSame(0, InventoryItem::query()->whereHas('item', fn ($q) => $q->where('slug', 'sealed-data-container'))->count());
    }

    public function test_a_transports_hound_is_graded_by_the_desk_and_the_container_by_the_target(): void
    {
        $this->giveRoomForData($this->character);
        $this->desk->forceFill(['grade' => 3])->save();
        $this->character->forceFill(['heat' => 10.0])->save();

        $mission = $this->accepted(MissionAction::Transport);

        // The target fixture is grade 2: the file weighs twice that.
        $row = InventoryItem::query()->whereHas('item', fn ($q) => $q->where('slug', 'sealed-data-container'))->firstOrFail();
        $this->assertSame(4.0, (float) $row->extracted_file['size_qb']);

        // Heat is 100 now, which on its own is a grade 5 hound; the job's own is the desk's grade over the runner's own heat.
        $hunt = Hunters::fromConfig(app(Resolver::class))->spawn($this->character->fresh());
        $this->assertSame(3, $hunt->grade);
    }

    public function test_losing_to_a_hunter_loses_the_container_and_fails_the_job(): void
    {
        $this->giveRoomForData($this->character);
        $mission = $this->accepted(MissionAction::Transport);

        $this->ledger()->transportLost($this->character->fresh());

        $this->assertSame(MissionState::Failed, $mission->fresh()->state);
        $this->assertSame(0, InventoryItem::query()->whereHas('item', fn ($q) => $q->where('slug', 'sealed-data-container'))->count());
    }

    public function test_a_transport_is_refused_when_there_is_no_room_for_the_container(): void
    {
        $offer = $this->anOffer(MissionAction::Transport);

        $this->assertSame(MissionRefusal::NoRoom, $this->ledger()->accept($this->character, $offer));
    }

    // ---- the board ----------------------------------------------------------

    public function test_a_board_is_rolled_for_whoever_is_standing_there(): void
    {
        $this->targets(6);

        $mine = $this->board()->forCharacter($this->character, $this->desk);

        $this->assertCount(2, $mine);

        $someoneElse = Character::factory()->create();
        $theirs = $this->board()->forCharacter($someoneElse, $this->desk);

        // Different rows entirely. Nobody can take your job.
        $this->assertCount(2, $theirs);
        $this->assertEmpty($mine->pluck('id')->intersect($theirs->pluck('id')));
    }

    public function test_the_board_is_not_rerolled_within_the_hour(): void
    {
        $this->targets(6);

        $first = $this->board()->forCharacter($this->character, $this->desk)->pluck('id');
        $again = $this->board()->forCharacter($this->character, $this->desk)->pluck('id');

        $this->assertSame($first->all(), $again->all());
    }

    public function test_an_emptied_board_is_rolled_again_on_the_next_visit(): void
    {
        $this->targets(6);

        $this->board()->forCharacter($this->character, $this->desk)
            ->each(fn (Mission $offer) => $offer->delete());

        // THE FLOOR HOLDS. Somebody who declined everything finds work waiting
        // rather than an empty desk until the hour is up.
        $this->assertGreaterThan(0, $this->board()->forCharacter($this->character, $this->desk)->count());
    }

    /** A survey pays x3 at grade 1, falling to x1 at grade 5; the other actions keep their one figure. */
    public function test_a_survey_pays_more_at_the_low_grades(): void
    {
        $rewards = new \ReflectionMethod(MissionBoard::class, 'rewards');
        $min = fn (string $action, int $grade): int => $rewards->invoke($this->board(), MissionAction::from($action), $grade)['creds'];

        // The test roll returns the bottom of every band.
        $this->assertSame(120, $min('scan', 1));
        $this->assertSame(225, $min('scan', 2));
        $this->assertSame(900, $min('scan', 5));
        $this->assertSame(80, $min('burn', 1));
    }

    /** The rewards are frozen at the offer, so retuning cannot change a job already taken. */
    public function test_rewards_are_snapshotted_onto_the_row(): void
    {
        $this->targets(6);

        $offer = $this->board()->forCharacter($this->character, $this->desk)->first();
        $paid = $offer->creds_reward;

        Config::set('missions.grades.1.creds', [999999, 999999]);

        $this->assertSame($paid, $offer->fresh()->creds_reward);
    }

    // ---- taking it ----------------------------------------------------------

    public function test_accepting_starts_the_clock(): void
    {
        $offer = $this->anOffer(MissionAction::Scan);

        $taken = $this->ledger()->accept($this->character, $offer);

        $this->assertInstanceOf(Mission::class, $taken);
        $this->assertSame(MissionState::Accepted, $taken->state);
        $this->assertNotNull($taken->accepted_at);
        $this->assertNotNull($taken->expires_at, 'the window is measured from accept, never from the offer');
    }

    /** The job is written down in the inbox: what, where, what it pays and when it is due. */
    public function test_accepting_sends_the_job_to_the_inbox_with_its_addresses_and_due_date(): void
    {
        $offer = $this->anOffer(MissionAction::Burn);
        $addresses = $offer->targets->pluck('address')->all();

        $taken = $this->ledger()->accept($this->character, $offer);

        $message = Notification::query()->sole();
        $this->assertSame(Category::Mission, $message->category);
        $this->assertStringContainsString(MissionAction::Burn->label(), (string) $message->title);
        $this->assertStringContainsString((string) $this->entity->name, (string) $message->title);

        foreach ($addresses as $address) {
            $this->assertStringContainsString($address, $message->body, 'the systems it names, by address');
        }

        $this->assertStringContainsString($taken->expires_at->format('Y-m-d H:i').' UTC', $message->body, 'the due date');
        $this->assertStringContainsString('500', $message->body, 'what it pays');
        $this->assertSame($addresses, array_column($message->details['targets'], 'address'));
    }

    public function test_the_inbox_lists_the_systems_of_an_accepted_job(): void
    {
        $offer = $this->anOffer(MissionAction::Plant);
        $this->ledger()->accept($this->character, $offer);

        $page = $this->actingAs($this->character->user)->get(route('notifications.index'))->assertOk()->getContent();

        foreach ($offer->targets as $target) {
            $this->assertStringContainsString($target->address, $page);
        }
    }

    public function test_a_supply_job_says_how_many_files_not_which_systems(): void
    {
        $this->ledger()->accept($this->character, $this->anOffer(MissionAction::Supply));

        $message = Notification::query()->sole();

        $this->assertStringContainsString('Bring 3 files: complete scan files or sealed data dumps.', $message->body);
        $this->assertNull($message->details);
    }

    public function test_a_refused_acceptance_writes_nothing(): void
    {
        $first = $this->anOffer(MissionAction::Scan);
        $second = $this->anOffer(MissionAction::Burn);
        $this->ledger()->accept($this->character, $first);
        Notification::query()->delete();

        $this->ledger()->accept($this->character, $second);   // one job per agent: refused

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_one_job_per_agent(): void
    {
        $first = $this->anOffer(MissionAction::Scan);
        $second = $this->anOffer(MissionAction::Burn);

        $this->ledger()->accept($this->character, $first);

        $this->assertSame(
            MissionRefusal::AlreadyWorking,
            $this->ledger()->accept($this->character, $second),
        );
    }

    public function test_a_desk_that_will_not_deal_with_you_will_not_employ_you(): void
    {
        $this->desk->forceFill(['min_standing' => 20])->save();

        $offer = $this->anOffer(MissionAction::Scan);

        $this->assertSame(
            MissionRefusal::NotWelcome,
            $this->ledger()->accept($this->character->fresh(), $offer),
        );
    }

    /** Turning one down deletes it: an offer nobody took is not history. */
    public function test_declining_takes_it_off_the_board(): void
    {
        $offer = $this->anOffer(MissionAction::Scan);

        $this->assertTrue($this->ledger()->decline($this->character, $offer));
        $this->assertNull(Mission::query()->find($offer->id));
    }

    // ---- the three endings --------------------------------------------------

    public function test_completing_pays_creds_standing_and_loyalty(): void
    {
        $mission = $this->accepted(MissionAction::Burn);
        $mission->targets->each->forceFill(['satisfied_at' => now()])->each->save();

        $done = $this->ledger()->complete($this->character->fresh(), $mission->fresh());

        $this->assertInstanceOf(Mission::class, $done);
        $this->assertSame(MissionState::Completed, $done->state);

        $this->assertSame((int) $mission->creds_reward, (int) $this->character->fresh()->creds);
        $this->assertGreaterThan(0.0, (new StandingLedger)->standingFor($this->character, $this->entity));
        $this->assertSame((int) $mission->loyalty_reward, (new StandingLedger)->loyaltyFor($this->character, $this->entity));
    }

    public function test_handing_in_unfinished_work_is_refused(): void
    {
        $mission = $this->accepted(MissionAction::Burn);

        $this->assertSame(
            MissionRefusal::NotDone,
            $this->ledger()->complete($this->character, $mission),
        );

        $this->assertSame(0, (int) $this->character->fresh()->creds);
    }

    /** Abandoning costs exactly what expiring costs, or nobody would ever abandon. */
    public function test_abandoning_charges_standing(): void
    {
        $mission = $this->accepted(MissionAction::Scan);

        $given = $this->ledger()->abandon($this->character, $mission);

        $this->assertInstanceOf(Mission::class, $given);
        $this->assertSame(MissionState::Failed, $given->state);
        $this->assertLessThan(0.0, (new StandingLedger)->standingFor($this->character, $this->entity));
    }

    public function test_the_clock_running_out_fails_it(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $mission->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame(['failed' => 1, 'voided' => 0], $this->ledger()->sweepExpired());

        $this->assertSame(MissionState::Failed, $mission->fresh()->state);
        $this->assertLessThan(0.0, (new StandingLedger)->standingFor($this->character, $this->entity));
    }

    /**
     * A TARGET THAT STOPPED EXISTING VOIDS IT, AND COSTS NOTHING.
     *
     * Burned by somebody else, or relocated out from under the job. Neither is
     * the runner's fault, which is the whole reason failing and voiding are
     * different states.
     */
    public function test_a_lost_target_voids_without_penalty(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $mission->forceFill(['expires_at' => now()->subMinute()])->save();

        $mission->targets->first()->system->delete();

        $this->assertSame(['failed' => 0, 'voided' => 1], $this->ledger()->sweepExpired());

        $this->assertSame(MissionState::Voided, $mission->fresh()->state);
        $this->assertSame(0.0, (new StandingLedger)->standingFor($this->character, $this->entity));
    }

    /**
     * A BURNED TARGET LEAVES ITS ROW BEHIND, which is why `system_id` nulls
     * rather than cascading. A cascade would take the target with the machine,
     * leaving a job with no outstanding rows — one that reads as finished and
     * pays out for work nobody did.
     */
    public function test_a_burned_target_does_not_erase_itself(): void
    {
        $mission = $this->accepted(MissionAction::Scan);

        $mission->targets->each(fn ($target) => $target->system->delete());

        $this->assertSame(2, $mission->fresh()->targets()->count(), 'the rows survive their systems');
        $this->assertFalse($mission->fresh()->targetsSatisfied());

        $this->assertSame(
            MissionRefusal::Gone,
            $this->ledger()->complete($this->character, $mission->fresh()),
            'and it voids at the desk rather than paying out',
        );

        $this->assertSame(MissionState::Voided, $mission->fresh()->state);
        $this->assertSame(0, (int) $this->character->fresh()->creds);
    }

    /** A relocation is the same case: same row, new address. */
    public function test_a_relocated_target_voids_too(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $mission->forceFill(['expires_at' => now()->subMinute()])->save();

        $mission->targets->first()->system->forceFill(['address' => 'FFFFFFFF'])->save();

        $this->assertSame(['failed' => 0, 'voided' => 1], $this->ledger()->sweepExpired());
        $this->assertSame(MissionState::Voided, $mission->fresh()->state);
    }

    /** A relocated target is swapped for the nearest system of its grade and type, and the player is told. */
    public function test_a_relocated_target_is_replaced_by_one_of_the_same_grade_and_type(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $old = $mission->targets->first();
        $was = $old->address;
        $spare = $this->targets(1)->first();

        $old->system->forceFill(['address' => 'FFFFFFFF'])->save();
        Notification::query()->delete();

        $this->assertTrue($this->ledger()->retarget($mission->fresh()));

        $new = $old->fresh();
        $this->assertSame($spare->id, $new->system_id);
        $this->assertSame($spare->address, $new->address);
        $this->assertSame(MissionState::Accepted, $mission->fresh()->state);

        $message = Notification::query()->firstOrFail();
        $this->assertSame('Mission Update', $message->title);
        $this->assertSame("{$was} seems to have gone dark. We've assigned a new target for you: {$spare->address}", $message->body);
    }

    public function test_a_satisfied_target_is_left_alone(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $done = $mission->targets->first();
        $done->forceFill(['satisfied_at' => now()])->save();
        $done->system->forceFill(['address' => 'FFFFFFFF'])->save();
        $this->targets(1);

        $this->assertTrue($this->ledger()->retarget($mission->fresh()));
        $this->assertSame($done->system_id, $done->fresh()->system_id);
    }

    public function test_an_ending_nobody_watched_reaches_the_inbox(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $mission->forceFill(['expires_at' => now()->subMinute()])->save();
        // Taking the job wrote its own briefing; this is about what the sweep adds after it.
        Notification::query()->delete();

        $this->ledger()->sweepExpired();

        $message = Notification::query()->sole();

        $this->assertSame(Category::Mission, $message->category);
        $this->assertStringContainsString((string) $this->entity->name, $message->body);
    }

    // ---- supply: the second buyer for a scan file ---------------------------

    /**
     * SCANNING AS AN OCCUPATION, and the sink files have never had.
     *
     * The agent names no systems: they are buying intel by the file, and what
     * the runner brings is whatever they had or went and got.
     */
    public function test_supply_is_paid_in_complete_files(): void
    {
        $mission = $this->accepted(MissionAction::Supply);

        $this->assertSame(3, $mission->quantity_required);

        $files = $this->scanFiles(3);

        $done = $this->ledger()->complete($this->character->fresh(), $mission);

        $this->assertInstanceOf(Mission::class, $done);
        $this->assertSame(MissionState::Completed, $done->state);

        // Spent, so the same file cannot be sold to two agents.
        foreach ($files as $row) {
            $this->assertNull(InventoryItem::query()->find($row->id));
        }

        // THE TARGET ROWS ARE THE RECEIPT RATHER THAN THE ASSIGNMENT, written
        // at hand-in, so the job still records which systems were sold.
        $this->assertSame(3, $done->targets()->count());
        $this->assertSame(0, $done->outstanding());
    }

    // ---- a survey is handed in as files -----------------------------------------------------------------

    public function test_a_survey_is_handed_in_as_one_file_per_system_and_the_files_are_spent(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $files = $mission->targets->map(fn ($target) => $this->fileFor($target->system));
        $stranger = $this->scanFiles(1)->first();   // a file of some other system is not wanted and is not spent

        $done = $this->ledger()->complete($this->character->fresh(), $mission);

        $this->assertInstanceOf(Mission::class, $done);
        $this->assertSame(MissionState::Completed, $done->state);
        $this->assertSame(0, $done->outstanding());
        $files->each(fn (InventoryItem $row) => $this->assertNull(InventoryItem::query()->find($row->id), 'spent'));
        $this->assertNotNull(InventoryItem::query()->find($stranger->id));
        $this->assertSame(500, (int) $this->character->fresh()->creds, 'and it paid');
    }

    public function test_a_survey_with_a_file_missing_is_refused_and_spends_nothing(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $first = $this->fileFor($mission->targets->first()->system);

        $this->assertSame(MissionRefusal::NotDone, $this->ledger()->complete($this->character->fresh(), $mission));

        $this->assertNotNull(InventoryItem::query()->find($first->id), 'a refusal spends nothing');
        $this->assertSame(0, $mission->fresh()->targets()->whereNotNull('satisfied_at')->count());
    }

    public function test_an_incomplete_or_stale_file_does_not_stand_in_for_a_system(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        [$one, $two] = [$mission->targets[0]->system, $mission->targets[1]->system];
        $this->fileFor($one);
        $this->fileFor($two, complete: false);

        $this->assertSame(MissionRefusal::NotDone, $this->ledger()->complete($this->character->fresh(), $mission));

        $this->fileFor($two, stale: true);
        $this->assertSame(MissionRefusal::NotDone, $this->ledger()->complete($this->character->fresh(), $mission));
    }

    public function test_the_card_says_which_systems_of_a_survey_have_their_file_in_hand(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $this->fileFor($mission->targets->first()->system);

        $held = $this->ledger()->heldAddresses($this->character->fresh());

        $this->assertSame([$mission->targets->first()->address], $held);
    }

    public function test_supply_refuses_when_the_files_are_short(): void
    {
        $mission = $this->accepted(MissionAction::Supply);

        $this->scanFiles(2);

        $this->assertSame(
            MissionRefusal::NotDone,
            $this->ledger()->complete($this->character->fresh(), $mission),
        );

        $this->assertSame(2, InventoryItem::query()->whereNotNull('scan_file')->count(), 'a refusal spends nothing');
    }

    /** Sealed data dumps are files too; a decrypted one (a Memory Dump) is not bulk and does not count. */
    public function test_supply_takes_sealed_dumps_after_scan_files_and_not_memory_dumps(): void
    {
        $mission = $this->accepted(MissionAction::Supply);
        $this->scanFiles(1);

        $dump = fn (string $slug): InventoryItem => InventoryItem::create([
            'character_id' => $this->character->id,
            'item_id' => Item::query()->where('slug', $slug)->value('id'),
            'quantity' => 1,
            'extracted_file' => ['label' => 'Logs', 'size_qb' => 2, 'encryption_skill_id' => null, 'value_sealed' => 40, 'value_broken' => 90, 'grade' => 1],
        ]);

        $dump('encrypted-data-dump');
        $dump('memory-dump');

        $this->assertSame(
            MissionRefusal::NotDone,
            $this->ledger()->complete($this->character->fresh(), $mission),
            'one scan file and one sealed dump are two files, and a Memory Dump is not a third',
        );
        $this->assertSame(1, InventoryItem::query()->whereNotNull('scan_file')->count(), 'a refusal spends nothing');

        $dump('encrypted-data-dump');

        $this->assertNotInstanceOf(MissionRefusal::class, $this->ledger()->complete($this->character->fresh(), $mission));
        $this->assertSame(0, InventoryItem::query()->whereNotNull('scan_file')->count());
        $this->assertSame(1, InventoryItem::query()->whereHas('item', fn ($q) => $q->where('slug', 'memory-dump'))->count(), 'the Memory Dump is untouched');
        $this->assertSame(0, InventoryItem::query()->whereHas('item', fn ($q) => $q->where('slug', 'encrypted-data-dump'))->count());
    }

    /** A one-pass file that learned the grade and nothing else is not intel. */
    public function test_an_incomplete_file_does_not_count(): void
    {
        $mission = $this->accepted(MissionAction::Supply);

        $this->scanFiles(2);
        $this->scanFiles(1, complete: false);

        $this->assertSame(
            MissionRefusal::NotDone,
            $this->ledger()->complete($this->character->fresh(), $mission),
        );
    }

    /** Nor is one that went stale in somebody's stash. */
    public function test_a_stale_file_does_not_count(): void
    {
        $mission = $this->accepted(MissionAction::Supply);

        $this->scanFiles(2);
        $this->scanFiles(1, stale: true);

        $this->assertSame(
            MissionRefusal::NotDone,
            $this->ledger()->complete($this->character->fresh(), $mission),
        );
    }

    // ---- the act satisfies its own target -----------------------------------

    public function test_an_act_marks_the_target_where_it_happens(): void
    {
        $mission = $this->accepted(MissionAction::Burn);
        $system = $mission->targets->first()->system;

        $this->assertSame(1, $this->ledger()->recordAgainst($this->character, $system, MissionAction::Burn));

        // One target of the two, so the job is not done — which is the point
        // of counting rows rather than trusting the last act.
        $this->assertSame(1, $mission->fresh()->outstanding());
        $this->assertTrue($mission->targets->first()->fresh()->isSatisfied());

        // Not satisfied twice, and a machine nobody has a job against is an
        // ordinary no-op rather than an error.
        $this->assertSame(0, $this->ledger()->recordAgainst($this->character, $system, MissionAction::Burn));
    }

    public function test_an_act_of_the_wrong_kind_satisfies_nothing(): void
    {
        $mission = $this->accepted(MissionAction::Burn);
        $system = $mission->targets->first()->system;

        $this->assertSame(0, $this->ledger()->recordAgainst($this->character, $system, MissionAction::Plant));
        $this->assertFalse($mission->fresh()->targetsSatisfied());
    }

    // ---- fixtures -----------------------------------------------------------

    private function board(): MissionBoard
    {
        // Pinned to the low end of every range: two jobs, the fewest targets.
        return new MissionBoard(static fn (int $min, int $max): int => $min);
    }

    private function ledger(): MissionLedger
    {
        return app(MissionLedger::class);
    }

    /** A scan file of a particular system, made the way an export makes one. */
    private function fileFor(System $system, bool $complete = true, bool $stale = false): InventoryItem
    {
        $item = Item::query()->where('slug', 'scan-file')->firstOrFail();
        $os = Skill::query()->where('slug', 'lattice')->first()
            ?? Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'rank' => 1]);

        return InventoryItem::query()->create([
            'character_id' => $this->character->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'scan_file' => array_merge([
                'address' => $system->address,
                'name' => $system->displayName(),
                'grade' => $system->grade,
                'made_at' => now()->subHour()->format(DATE_ATOM),
                'expires_at' => ($stale ? now()->subHour() : now()->addHours(12))->format(DATE_ATOM),
            ], $complete ? ['os_skill_id' => $os->id, 'ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0, 'ice_level' => 0] : []),
        ]);
    }

    /** One offer of a named action, built the way the board builds one. */
    private function anOffer(MissionAction $action): Mission
    {
        $mission = Mission::query()->create([
            'character_id' => $this->character->id,
            'entity_id' => $this->entity->id,
            'exchange_id' => $this->desk->id,
            'action' => $action,
            'state' => MissionState::Offered,
            'quantity_required' => $action === MissionAction::Supply ? 3 : 0,
            'creds_reward' => 500,
            'standing_reward' => 0.02,
            'loyalty_reward' => 2,
            'penalty' => 0.02,
            'offered_at' => now(),
        ]);

        if ($action->designatesTargets()) {
            foreach ($this->targets(2) as $system) {
                $mission->targets()->create([
                    'system_id' => $system->id,
                    'address' => $system->address,
                ]);
            }
        }

        return $mission->fresh(['targets.system']);
    }

    private function accepted(MissionAction $action): Mission
    {
        $taken = $this->ledger()->accept($this->character, $this->anOffer($action));

        $this->assertInstanceOf(Mission::class, $taken, 'the fixture has to get the job taken');

        return $taken->fresh(['targets.system']);
    }

    /**
     * Files in the stash, made the way an export makes them.
     *
     * @return Collection<int, InventoryItem>
     */
    private function scanFiles(int $count, bool $complete = true, bool $stale = false): Collection
    {
        $item = Item::query()->where('slug', 'scan-file')->firstOrFail();
        $os = Skill::query()->where('slug', 'lattice')->first()
            ?? Skill::factory()->create(['slug' => 'lattice', 'title' => 'Lattice', 'rank' => 1]);

        return collect(range(1, $count))->map(function (int $n) use ($item, $os, $complete, $stale): InventoryItem {
            $system = System::factory()->at(20 + $this->placed++, 20)->create(['type' => 'silo', 'grade' => 3]);

            return InventoryItem::query()->create([
                'character_id' => $this->character->id,
                'item_id' => $item->id,
                'quantity' => 1,
                'scan_file' => array_merge([
                    'address' => $system->address,
                    'name' => $system->displayName(),
                    'grade' => 3,
                    'made_at' => now()->subHour()->format(DATE_ATOM),
                    'expires_at' => ($stale ? now()->subHour() : now()->addHours(12))->format(DATE_ATOM),
                ], $complete ? [
                    'os_skill_id' => $os->id,
                    'ids_detection' => 0,
                    'tracer_detection' => 0,
                    'tracer_rate' => 0,
                    'ice_level' => 0,
                ] : []),
            ]);
        });
    }

    /**
     * Machines in the desk's band, each on its own cell.
     *
     * A position is unique and the factory pins it at the origin on purpose —
     * see SystemFactory — so a test that wants several says where each one is.
     *
     * @return Collection<int, System>
     */
    private function targets(int $count): Collection
    {
        return collect(range(1, $count))->map(fn (int $n): System => System::factory()
            ->at(10 + $this->placed++, 12)
            ->create(['type' => 'silo', 'grade' => 2]));
    }
}
