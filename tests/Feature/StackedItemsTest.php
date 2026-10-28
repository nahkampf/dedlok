<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Equipment\BeltEquipper;
use App\Inventory\ItemGranter;
use App\Inventory\ItemPlacer;
use App\Items\ConsumableEditor;
use App\Models\Character;
use App\Models\District;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Safehouse;
use App\Models\Skill;
use App\Programs\ProgramKind;
use App\Programs\ProgramRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CarriesAWorkingDeck;
use Tests\TestCase;

/**
 * A pile is a stack key. A copy stored at a safehouse is a place of its own and no stopped program or grant joins it.
 */
final class StackedItemsTest extends TestCase
{
    use CarriesAWorkingDeck;
    use RefreshDatabase;

    private Character $character;

    private Item $item;

    private InventoryItem $stored;

    protected function setUp(): void
    {
        parent::setUp();

        Skill::factory()->create(['slug' => 'lattice', 'rank' => 1]);
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['archetype' => 3]));
        $this->equipReadyDeck($this->character);

        $this->item = Item::factory()->program(ProgramKind::Utility, qflops: 0.1, memory: 0.1, storage: 0.1, background: true)->create(['stackable' => true]);
        $home = Safehouse::create([
            'slug' => 'capsule', 'title' => 'Capsule hotel', 'district_id' => District::query()->where('slug', 'dockside')->firstOrFail()->id,
            'rent' => 40, 'security' => 0, 'stealth' => 0, 'rest_rate' => 2, 'storage_slots' => 2, 'actions' => ['rest'],
        ]);

        // One copy stored at the safehouse, one in the deck.
        $this->stored = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $this->item->id, 'quantity' => 1, 'safehouse_id' => $home->id]);
    }

    public function test_a_stopped_program_does_not_join_the_copy_stored_at_a_safehouse(): void
    {
        $running = $this->ownedBy($this->character, $this->item);
        $this->assertTrue(app(ProgramRunner::class)->start($this->character, $running)->succeeded());

        $stopped = $this->character->fresh()->runningProcesses()->first();
        app(ProgramRunner::class)->stop($this->character->fresh(), $stopped);

        $this->assertSame(1, $this->stored->fresh()->quantity);
        $this->assertSame(1, InventoryItem::query()->loose()->where('character_id', $this->character->id)->where('item_id', $this->item->id)->sum('quantity'));
    }

    public function test_a_grant_does_not_join_the_copy_stored_at_a_safehouse(): void
    {
        ItemGranter::grant($this->character, $this->item, 2);

        $this->assertSame(1, $this->stored->fresh()->quantity);
        $this->assertSame(2, InventoryItem::query()->loose()->where('character_id', $this->character->id)->where('item_id', $this->item->id)->sum('quantity'));
    }

    public function test_a_belted_copy_taken_off_does_not_join_the_copy_stored_at_a_safehouse(): void
    {
        $kit = Item::factory()->create(['container' => 'physical', 'stackable' => true]);
        ConsumableEditor::apply($kit, [['kind' => 'heal_hitpoints', 'amount' => 20]]);
        $stored = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $kit->id, 'quantity' => 1, 'safehouse_id' => $this->stored->safehouse_id]);
        $belted = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $kit->id, 'quantity' => 1, 'belt_slot' => 1]);

        $this->assertTrue((new BeltEquipper)->take($this->character, $belted));

        $this->assertSame(1, $stored->fresh()->quantity);
        $this->assertSame(1, InventoryItem::query()->loose()->where('character_id', $this->character->id)->where('item_id', $kit->id)->sum('quantity'));
    }

    public function test_a_copy_taken_out_of_a_container_does_not_join_a_stored_or_running_one(): void
    {
        $card = $this->ownedBy($this->character, Item::factory()->holds('data', 64)->create());
        $boxed = InventoryItem::query()->create(['character_id' => $this->character->id, 'item_id' => $this->item->id, 'quantity' => 1, 'parent_id' => $card->id]);

        app(ItemPlacer::class)->place($this->character, $boxed, null);

        $this->assertSame(1, $this->stored->fresh()->quantity);
        $this->assertSame(1, InventoryItem::query()->loose()->where('character_id', $this->character->id)->where('item_id', $this->item->id)->sum('quantity'));
    }
}
