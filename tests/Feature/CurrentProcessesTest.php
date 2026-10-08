<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Characters\CurrentProcesses;
use App\Decryption\DecryptionQueue;
use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\Dose;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
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

    public function test_an_idle_character_has_nothing_going_on(): void
    {
        $this->assertSame([], app(CurrentProcesses::class)->for($this->character));

        $this->actingAs($this->character->user)->get(route('processes.current'))
            ->assertOk()->assertSeeText('Nothing is going on.');
    }

    public function test_a_drug_in_effect_is_a_bar_with_the_time_left_on_it(): void
    {
        $item = Item::factory()->create(['name' => 'Rewire']);
        Dose::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'potency' => 1, 'started_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(30)]);

        $rows = app(CurrentProcesses::class)->for($this->character);

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

        $this->assertSame([], app(CurrentProcesses::class)->for($this->character));
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

    public function test_the_top_bar_offers_the_modal_to_a_signed_in_character_only(): void
    {
        $this->actingAs($this->character->user)->get(route('inventory.index'))
            ->assertOk()->assertSee('id="current-processes"', false)->assertSee(route('processes.current'), false);

        auth()->logout();
        $this->get(route('processes.current'))->assertRedirect();
    }
}
