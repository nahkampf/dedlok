<?php

declare(strict_types=1);

namespace Tests\Feature\Missions;

use App\Missions\MissionAction;
use App\Missions\MissionBoard;
use App\Missions\MissionLedger;
use App\Missions\MissionRefusal;
use App\Missions\MissionState;
use App\Models\Character;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Skill;
use App\Models\System;
use App\Notifications\Category;
use App\Standing\StandingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
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

    public function test_an_ending_nobody_watched_reaches_the_inbox(): void
    {
        $mission = $this->accepted(MissionAction::Scan);
        $mission->forceFill(['expires_at' => now()->subMinute()])->save();

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
