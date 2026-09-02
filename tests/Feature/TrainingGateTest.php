<?php

namespace Tests\Feature;

use App\Inventory\SkillInjector;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\TrainingQueueEntry;
use App\Skills\TrainingDeposit;
use App\Skills\TrainingQueueValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule that joins the two systems: nothing can be trained until its
 * skillbook has been owned and spent.
 *
 * A character_skills row is the whole gate. Injecting is the only thing that
 * creates one, and every training path refuses without one — so this tests
 * the seam from both sides, including the path around the UI.
 */
class TrainingGateTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private Skill $hacking;

    private Item $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));

        $this->hacking = Skill::factory()->rank(16)
            ->governedBy('intellect', 'memory')
            ->create(['slug' => 'hacking', 'title' => 'Hacking']);

        $this->book = Item::factory()->skillbookFor($this->hacking)->create();
    }

    private function queue(): TrainingQueueEntry
    {
        return TrainingQueueEntry::create([
            'character_id' => $this->character->id,
            'skill_id' => $this->hacking->id,
            'target_level' => 1,
            'position' => 1,
            'last_deposited_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
        ]);
    }

    private function inject(): void
    {
        $entry = InventoryItem::factory()
            ->ownedBy($this->character)->of($this->book)->create();

        (new SkillInjector)->inject($this->character, $entry);
    }

    // ---- before the book is spent -----------------------------------------

    public function test_owning_the_book_is_not_knowing_the_skill(): void
    {
        InventoryItem::factory()->ownedBy($this->character)->of($this->book)->create();

        $this->assertFalse($this->character->hasInjected($this->hacking));
        $this->assertSame(0, CharacterSkill::count());
    }

    public function test_the_queue_validator_refuses_an_uninjected_skill(): void
    {
        $this->queue();

        $problems = (new TrainingQueueValidator)->problemsFor($this->character->fresh());

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('never been injected', implode(' ', $problems));
    }

    /**
     * The path around the UI: a queue row written directly, with no book ever
     * bought. The deposit must refuse rather than create the progress row,
     * which would hand over the skill for free.
     */
    public function test_a_deposit_will_not_create_the_progress_row_itself(): void
    {
        $this->queue();

        $result = TrainingDeposit::make()->advance(
            $this->character->fresh(),
            CarbonImmutable::parse('2026-09-01 20:00:00')
        );

        $this->assertTrue($result->uninjected);
        $this->assertSame(0, $result->pointsAwarded);
        $this->assertSame(0, CharacterSkill::count(), 'training must not be a way to learn a skill');
    }

    // ---- after the book is spent ------------------------------------------

    public function test_injecting_opens_the_gate(): void
    {
        $this->inject();

        $this->assertTrue($this->character->fresh()->hasInjected($this->hacking));

        $this->queue();

        $this->assertSame([], (new TrainingQueueValidator)->problemsFor($this->character->fresh()));
    }

    public function test_training_runs_once_the_skill_is_injected(): void
    {
        $this->inject();
        $this->queue();

        $result = TrainingDeposit::make()->advance(
            $this->character->fresh(),
            CarbonImmutable::parse('2026-09-01 13:00:00')
        );

        $this->assertFalse($result->uninjected);
        $this->assertGreaterThan(0, $result->pointsAwarded);
    }

    public function test_the_whole_path_end_to_end(): void
    {
        // Buy the book.
        $entry = InventoryItem::factory()->ownedBy($this->character)->of($this->book)->create();

        // Spend it through the screen.
        $this->actingAs($this->character->user)
            ->post(route('inventory.inject', $entry))
            ->assertSessionHas('status');

        $this->assertNull($entry->fresh(), 'the book is gone');

        // Queue it and let an hour of the scheduler happen.
        $this->queue();
        TrainingDeposit::make()->advance(
            $this->character->fresh(),
            CarbonImmutable::parse('2026-09-01 13:00:00')
        );

        $progress = CharacterSkill::where('skill_id', $this->hacking->id)->first();

        $this->assertNotNull($progress);
        $this->assertGreaterThan(0, $progress->skill_points);
    }
}
