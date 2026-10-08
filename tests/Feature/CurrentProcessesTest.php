<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\CurrentProcesses;
use App\Cyberspace\SystemType;
use App\Decryption\DecryptionQueue;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\Dose;
use App\Models\DrugUse;
use App\Models\Entity;
use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Mission;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemScan;
use App\Models\TrainingQueueEntry;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Skills\TrainingQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/** The Current processes modal: what is in flight, how far along, and how long is left. */
final class CurrentProcessesTest extends TestCase
{
    use CarriesAWorkingDeck, RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute());
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function processesOnly(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $row['group'] === 'process'));
    }

    public function test_an_idle_character_has_nothing_going_on(): void
    {
        $rows = app(CurrentProcesses::class)->for($this->character);

        $this->assertSame([], array_values(array_filter($rows, fn (array $row): bool => $row['group'] === 'process')));
        $this->assertSame(['restock'], array_column($rows, 'kind'), 'only the market clock, which ticks for everybody');

        $this->actingAs($this->character->user)->get(route('processes.current'))
            ->assertOk()->assertSeeText('Nothing is going on.')->assertSeeText('The market restocks');
    }

    public function test_a_drug_in_effect_is_a_bar_with_the_time_left_on_it(): void
    {
        $item = Item::factory()->create(['name' => 'Rewire']);
        Dose::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'potency' => 1, 'started_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(30)]);

        $rows = $this->processesOnly(app(CurrentProcesses::class)->for($this->character));

        $this->assertCount(1, $rows);
        $this->assertSame('dose', $rows[0]['kind']);
        $this->assertEqualsWithDelta(0.25, $rows[0]['ratio'], 0.001);
        $this->assertSame(1800, $rows[0]['seconds']);

        $this->actingAs($this->character->user)->get(route('processes.current'))
            ->assertOk()->assertSee('Rewire in your system')->assertSee('data-tip="30m left"', false);
    }

    public function test_a_fight_only_dose_is_not_a_process(): void
    {
        $item = Item::factory()->create();
        Dose::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'potency' => 1, 'started_at' => now(), 'expires_at' => now()->addDay(), 'turns_left' => 3]);

        $this->assertNotContains('dose', array_column(app(CurrentProcesses::class)->for($this->character), 'kind'));
    }

    public function test_a_scan_pass_shows_how_far_through_it_is(): void
    {
        SystemScan::query()->create([
            'character_id' => $this->character->id, 'address' => 'AB12CD34', 'x' => 1, 'y' => 1,
            'passes' => 0, 'scanned_at' => now(), 'pass_started_at' => now()->subMinutes(2), 'pass_ready_at' => now()->addMinutes(6),
            'expires_at' => now()->addDay(),
        ]);

        $rows = app(CurrentProcesses::class)->for($this->character);

        $this->assertSame('Scanning AB12CD34', $rows[0]['label']);
        $this->assertEqualsWithDelta(0.25, $rows[0]['ratio'], 0.001);
        $this->assertSame(360, $rows[0]['seconds']);
    }

    public function test_a_decryption_in_progress_shows_its_cycle_and_waiting_files_count(): void
    {
        $cinder = Skill::factory()->create(['slug' => 'cinder', 'category' => 'Encryption', 'rank' => 2]);
        app(SkillInjector::class)->grant($this->character, $cinder, 1);
        $dump = Item::query()->where('slug', 'encrypted-data-dump')->firstOrFail();
        $queue = DecryptionQueue::make();

        foreach (['One', 'Two'] as $label) {
            $file = InventoryItem::create([
                'character_id' => $this->character->id, 'item_id' => $dump->id, 'quantity' => 1,
                'extracted_file' => ['label' => $label, 'size_qb' => 2, 'encryption_skill_id' => $cinder->id, 'value_sealed' => 40, 'value_broken' => 90, 'grade' => 1],
            ]);
            $queue->enqueue($this->character->fresh(), $file);
        }

        $this->equipReadyDeck($this->character);
        $ghostkey = $this->ownedBy($this->character, Item::factory()->program(ProgramKind::Decryptor)->create());
        app(ProgramRunner::class)->start($this->character->fresh(), $ghostkey);
        $queue->settle($this->character->fresh());
        $this->travel(2)->minutes();

        $rows = app(CurrentProcesses::class)->for($this->character->fresh());

        $this->assertSame('Decrypting One', $rows[0]['label']);
        $this->assertEqualsWithDelta(0.4, $rows[0]['ratio'], 0.001, 'two minutes into a five minute cycle');
        $this->assertSame(180, $rows[0]['seconds']);
        $this->assertSame('1 file waiting to be decrypted', $rows[1]['label']);
        $this->assertNull($rows[1]['ratio']);
    }

    public function test_the_skill_in_training_shows_progress_toward_its_next_level(): void
    {
        $skill = Skill::factory()->create(['slug' => 'pistols', 'category' => 'Combat', 'rank' => 2]);
        app(SkillInjector::class)->grant($this->character, $skill, 0);
        $this->assertInstanceOf(TrainingQueueEntry::class, TrainingQueue::make()->enqueue($this->character->fresh(), $skill));

        $rows = app(CurrentProcesses::class)->for($this->character->fresh());

        $this->assertSame('Training '.$skill->title.', level 1', $rows[0]['label']);
        $this->assertSame(0.0, $rows[0]['ratio']);
        $this->assertGreaterThan(0, $rows[0]['seconds']);
    }

    public function test_a_mission_deadline_is_a_bar(): void
    {
        $entity = Entity::query()->interactable()->firstOrFail();
        $desk = System::factory()->at(8, 8)->ownedBy($entity)->create(['type' => SystemType::Exchange]);
        $exchange = Exchange::query()->create(['system_id' => $desk->id, 'grade' => 1, 'agent' => 'Wren', 'min_standing' => 0]);
        Mission::query()->create([
            'character_id' => $this->character->id, 'entity_id' => $entity->id, 'exchange_id' => $exchange->id, 'action' => 'burn', 'state' => 'accepted',
            'quantity_required' => 0, 'creds_reward' => 100, 'standing_reward' => 0.1, 'loyalty_reward' => 1, 'penalty' => 0.1,
            'offered_at' => now()->subHours(2), 'accepted_at' => now()->subHours(1), 'expires_at' => now()->addHours(3),
        ]);

        $rows = $this->processesOnly(app(CurrentProcesses::class)->for($this->character));

        $this->assertSame('mission', $rows[0]['kind']);
        $this->assertEqualsWithDelta(0.25, $rows[0]['ratio'], 0.001);
        $this->assertSame(3 * 3600, $rows[0]['seconds']);
    }

    public function test_a_withdrawal_counts_the_nights_until_it_fades(): void
    {
        $item = Item::factory()->create();
        DrugUse::query()->create(['character_id' => $this->character->id, 'family' => 'opiate', 'addiction' => 43.5, 'tolerance' => 0]);

        $row = $this->processesOnly(app(CurrentProcesses::class)->for($this->character))[0];

        $this->assertSame('withdrawal', $row['kind']);
        $this->assertSame('Withdrawal: Opiate', $row['label']);
        $this->assertSame('Fades in 4 nights, or when you take another', $row['detail'], '43.5 falls to 42.5, 41.5, 40.5, 39.5: four nights');
        $this->assertGreaterThan(3 * 86400, $row['seconds']);
    }

    public function test_the_mend_clock_counts_down_while_hurt_and_is_silent_at_full(): void
    {
        $max = $this->character->maximumFor('current_synaptic_integrity');
        $this->character->forceFill(['current_synaptic_integrity' => $max])->save();
        $this->assertNotContains('mend', array_column(app(CurrentProcesses::class)->for($this->character->fresh()), 'kind'));

        $this->character->forceFill(['current_synaptic_integrity' => $max - 1])->save();
        $mend = collect(app(CurrentProcesses::class)->for($this->character->fresh()))->firstWhere('kind', 'mend');

        $this->assertNotNull($mend);
        $this->assertSame('timer', $mend['group']);
        $this->assertLessThanOrEqual(7200, $mend['seconds']);
    }

    public function test_the_top_bar_offers_the_modal_to_a_signed_in_character_only(): void
    {
        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSee('id="current-processes"', false)->assertSee(route('processes.current'), false);

        auth()->logout();
        $this->get(route('processes.current'))->assertRedirect();
    }
}
