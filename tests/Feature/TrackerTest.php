<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cyberspace\SystemType;
use App\Cyberspace\Tracker;
use App\Models\Character;
use App\Models\Entity;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Notification;
use App\Models\System;
use App\Models\TrackerSearch;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Tracker: a filter, a release the deck must be able to hold, and passes that end with a message. */
final class TrackerTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private InventoryItem $row;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();
        $deck = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => Item::factory()->cyberdeck()->create()->id, 'quantity' => 1]);
        $this->character->forceFill(['equipped_deck_id' => $deck->id])->save();

        $item = Item::factory()->program(ProgramKind::Utility, qflops: 0.5, memory: 0.5, storage: 1, background: true, abilities: ['tracks' => true])->create();
        $this->row = InventoryItem::create(['character_id' => $this->character->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->row->forceFill(['running_on_deck_id' => $deck->id, 'started_at' => now()])->save();
    }

    private function released(array $filter): TrackerSearch
    {
        return TrackerSearch::create(['inventory_item_id' => $this->row->id, 'character_id' => $this->character->id, 'pass_started_at' => now()->subMinutes(2)] + $filter);
    }

    public function test_a_filter_needs_at_least_one_term_and_a_running_tracker(): void
    {
        $tracker = new Tracker;

        $this->assertSame(Text::get('cyberspace.tracker.no_filter'), $tracker->setFilter($this->character, null, null, null));
        $this->assertNull($tracker->setFilter($this->character, null, 'exchange', 2));
        $this->assertSame('exchange', TrackerSearch::query()->first()->type);

        $this->row->forceFill(['running_on_deck_id' => null, 'started_at' => null])->save();
        $this->assertSame(Text::get('cyberspace.tracker.not_running'), $tracker->setFilter($this->character->fresh(), null, 'exchange', 2));
    }

    public function test_release_is_refused_when_the_deck_cannot_hold_the_active_cost(): void
    {
        (new Tracker)->setFilter($this->character, null, 'exchange', null);

        $this->assertSame('Not enough RAM or CPU to run active Tracker', (new Tracker)->release($this->character->fresh(), new ProgramRunner));
        $this->assertFalse(Tracker::isActive($this->row->id));
    }

    public function test_an_active_tracker_holds_more_than_an_idle_one(): void
    {
        $this->assertSame(['qflops' => 0.5, 'memory' => 0.5], (new ProgramRunner)->spentOn($this->character, $this->character->equipped_deck_id));

        $this->released(['type' => 'exchange']);

        $this->assertSame(['qflops' => 2.0, 'memory' => 2.0], (new ProgramRunner)->spentOn($this->character, $this->character->equipped_deck_id));
    }

    public function test_a_pass_that_lands_beside_a_match_stops_the_tracker_and_sends_the_address(): void
    {
        $owner = Entity::query()->create(['name' => 'Tracked Co', 'category' => Entity::query()->value('category') ?? 'corporation', 'interactable' => true, 'business' => 'x']);
        $system = System::factory()->at(51, 49)->type(SystemType::Silo)->ownedBy($owner)->create();
        $this->released(['owner_id' => $owner->id, 'type' => 'silo']);

        // The die picks (50, 50): the system is one square away, inside the nine.
        $this->assertTrue((new Tracker(fn () => 50))->settle($this->character->fresh()));

        $this->assertFalse($this->row->fresh()->isRunning());
        $this->assertSame(0, TrackerSearch::query()->count());
        $note = Notification::query()->where('user_id', $this->character->user_id)->firstOrFail();
        $this->assertSame('Tracker: Node found', $note->title);
        $this->assertSame('Tracker has found the requested node: '.$system->address, $note->body);
    }

    public function test_a_miss_keeps_going_and_moves_the_clock_by_the_passes_credited(): void
    {
        System::factory()->at(60, 60)->type(SystemType::Silo)->create();
        $search = $this->released(['type' => 'silo']);
        $before = $search->pass_started_at;

        $this->assertFalse((new Tracker(fn () => 50))->settle($this->character->fresh()));

        $this->assertTrue($this->row->fresh()->isRunning());
        $this->assertSame(120, $search->fresh()->pass_started_at->diffInSeconds($before, true) | 0);
    }
}
