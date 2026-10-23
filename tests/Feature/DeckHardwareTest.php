<?php

namespace Tests\Feature;

use App\Decks\Hardware;
use App\Decks\HardwareEditor;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Decks\Variant;
use App\Models\DeckModule;
use App\Models\DeckSlot;
use App\Models\Item;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cyberdecks and their modules as rows.
 *
 * The compatibility rules are unit-tested without the database; what is here
 * is everything that only exists once there are rows: that a deck's sockets
 * come back as the layout that wrote them, that a module's spec reads as a
 * line a player can understand, and that switching what an item IS takes the
 * other half of it away.
 */
class DeckHardwareTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expansion_card_states_whichever_measure_its_row_sets(): void
    {
        $item = Item::factory()->create(['hardware' => 'expansion']);
        $module = DeckModule::query()->create([
            'item_id' => $item->id, 'variant' => 'xgate', 'power_draw' => 8, 'thermal_load' => 0, 'cooling' => 20,
        ]);

        $this->assertSame('20 TU cooling', $module->measureLabel(Hardware::Expansion));
        $this->assertSame('XGate · 20 TU cooling · 8 W draw', $module->summary(Hardware::Expansion));

        $bare = DeckModule::query()->create(['item_id' => Item::factory()->create(['hardware' => 'expansion'])->id, 'variant' => 'xgate', 'power_draw' => 5, 'thermal_load' => 2]);
        $this->assertNull($bare->measureLabel(Hardware::Expansion));
    }

    public function test_a_deck_is_an_item_and_its_sockets_are_rows(): void
    {
        $deck = Item::factory()->cyberdeck([
            'cpu_socket_k' => 2,
            'ram_qdram' => 4,
            'nic' => 1,
            'psu' => 1,
            'thermal' => 1,
        ])->create(['slug' => 'hosaka_reaver', 'name' => 'Hosaka Reaver']);

        $this->assertTrue($deck->isCyberdeck());
        $this->assertFalse($deck->isModule());
        $this->assertSame(9, DeckSlot::where('item_id', $deck->id)->count());

        $layout = $deck->fresh()->slotLayout();

        $this->assertSame(2, $layout->ofKind(SlotKind::Cpu));
        $this->assertSame(4, $layout->ofKind(SlotKind::Ram));
        $this->assertSame(1, $layout->ofKind(SlotKind::Thermal));
        $this->assertSame(0, $layout->ofKind(SlotKind::Npu));
    }

    public function test_a_module_is_an_item_and_its_numbers_are_a_row(): void
    {
        $cpu = Item::factory()
            ->module(Hardware::Cpu, Variant::SocketK, measure: 1_200, draw: 45)
            ->create(['slug' => 'kurogane_k4', 'name' => 'Kurogane K4']);

        $spec = $cpu->fresh()->deckModule;

        $this->assertInstanceOf(DeckModule::class, $spec);
        $this->assertSame(Variant::SocketK, $spec->variant);
        $this->assertEquals(1_200, $spec->qflops);
        $this->assertSame(45, $spec->power_draw);

        // One measure, and the other three stay empty.
        $this->assertNull($spec->quantabits);
        $this->assertNull($spec->bandwidth);
        $this->assertNull($spec->watts);
    }

    public function test_a_supply_gives_power_rather_than_taking_it(): void
    {
        $psu = Item::factory()->module(Hardware::Psu, measure: 600)->create();

        $spec = $psu->fresh()->deckModule;

        $this->assertSame(600, $spec->watts);
        $this->assertSame(0, $spec->power_draw);
        $this->assertNull($spec->variant);
    }

    public function test_a_socket_decides_what_may_be_fitted_into_it(): void
    {
        $deck = Item::factory()->cyberdeck(['cpu_socket_k' => 1, 'ram_universal' => 1, 'psu' => 1])->create();

        $fits = Item::factory()->module(Hardware::Cpu, Variant::SocketK)->create();
        $wrongSocket = Item::factory()->module(Hardware::Cpu, Variant::SocketZ)->create();
        $optoStick = Item::factory()->module(Hardware::Ram, Variant::Opto)->create();
        $notHardware = Item::factory()->create();

        $cpuSocket = $deck->deckSlots->firstWhere('kind', SlotKind::Cpu);
        $ramSocket = $deck->deckSlots->firstWhere('kind', SlotKind::Ram);

        $this->assertTrue($cpuSocket->accepts($fits));
        $this->assertFalse($cpuSocket->accepts($wrongSocket));
        $this->assertFalse($cpuSocket->accepts($optoStick));
        $this->assertFalse($cpuSocket->accepts($notHardware));

        // The universal socket is the one that takes either stick.
        $this->assertTrue($ramSocket->accepts($optoStick));
    }

    public function test_a_module_with_no_spec_row_fits_nowhere(): void
    {
        $deck = Item::factory()->cyberdeck()->create();
        // hardware set, deck_modules row missing — a half-written definition.
        $orphan = Item::factory()->create(['hardware' => Hardware::Cpu]);

        $this->assertFalse($deck->deckSlots->first()->accepts($orphan));
    }

    public function test_the_spec_line_reads_as_a_person_would_say_it(): void
    {
        $deck = Item::factory()->cyberdeck([
            'cpu_socket_k' => 2,
            'ram_qdram' => 4,
            'nic' => 1,
            'psu' => 1,
        ])->create();

        $this->assertSame(
            '2× Socket K · 4× QDRAM · NIC · PSU',
            $deck->fresh()->hardwareSummary(),
        );

        $cpu = Item::factory()->module(Hardware::Cpu, Variant::SocketK, measure: 1_200, draw: 45)->create();

        $this->assertSame('Socket K · 1,200 qFLOPS · 45 W draw', $cpu->fresh()->hardwareSummary());

        $psu = Item::factory()->module(Hardware::Psu, measure: 600)->create();

        $this->assertSame('600 W', $psu->fresh()->hardwareSummary());

        // Everything that is not deck hardware says nothing at all, so a
        // caller can render the line without asking what it is holding.
        $this->assertNull(Item::factory()->create()->hardwareSummary());
    }

    public function test_a_module_says_which_piece_it_is_not_which_inventory(): void
    {
        // The spec line gives a module's numbers and never its kind, so
        // without this a stick of RAM introduced itself as "Physical".
        $ram = Item::factory()->module(Hardware::Ram, Variant::Opto, measure: 16, draw: 34)->create();

        $this->assertSame('RAM', $ram->fresh()->kindName());
        $this->assertSame('Cyberdeck', Item::factory()->cyberdeck()->create()->kindName());

        // Everything else has only the inventory it lives in to go on.
        $this->assertSame(
            Text::get('items.kinds.physical'),
            Item::factory()->physical()->create()->kindName(),
        );
    }

    public function test_becoming_a_module_takes_the_sockets_away(): void
    {
        $item = Item::factory()->cyberdeck()->create();

        $this->assertSame(2, $item->deckSlots()->count());

        HardwareEditor::apply($item, Hardware::Ram, SlotLayout::empty(), [
            'variant' => Variant::QDram->value,
            'power_draw' => 8,
            'quantabits' => 512,
        ]);

        $this->assertSame(0, $item->deckSlots()->count());
        $this->assertEquals(512, $item->fresh()->deckModule->quantabits);
    }

    public function test_becoming_a_deck_takes_the_module_spec_away(): void
    {
        $item = Item::factory()->module(Hardware::Cpu, Variant::SocketG)->create();

        HardwareEditor::apply($item, Hardware::Deck, SlotLayout::fromCounts(['cpu_socket_g' => 1, 'psu' => 1]));

        $this->assertNull($item->fresh()->deckModule);
        $this->assertSame(2, $item->deckSlots()->count());
    }

    public function test_becoming_nothing_takes_both(): void
    {
        $deck = Item::factory()->cyberdeck()->create();
        $module = Item::factory()->module(Hardware::Nic, measure: 90)->create();

        HardwareEditor::apply($deck, null, SlotLayout::empty());
        HardwareEditor::apply($module, null, SlotLayout::empty());

        // Scoped to these two items — real hardware (the expansion cards)
        // lives in these tables now too, not only what a test creates.
        $this->assertSame(0, DeckSlot::query()->where('item_id', $deck->id)->count());
        $this->assertSame(0, DeckModule::query()->where('item_id', $module->id)->count());
    }

    public function test_deleting_the_item_takes_its_hardware_with_it(): void
    {
        $deck = Item::factory()->cyberdeck()->create();
        $module = Item::factory()->module(Hardware::Psu, measure: 400)->create();

        $deckId = $deck->id;
        $moduleId = $module->id;

        $deck->delete();
        $module->delete();

        $this->assertSame(0, DeckSlot::query()->where('item_id', $deckId)->count());
        $this->assertSame(0, DeckModule::query()->where('item_id', $moduleId)->count());
    }

    public function test_re_saving_an_unchanged_layout_writes_the_same_positions(): void
    {
        $deck = Item::factory()->cyberdeck(['cpu_socket_z' => 1, 'nic' => 1, 'psu' => 1])->create();

        $before = $deck->deckSlots->map(fn (DeckSlot $slot) => [$slot->kind->value, $slot->position])->all();

        HardwareEditor::apply($deck, Hardware::Deck, $deck->slotLayout());

        $after = $deck->fresh()->deckSlots->map(fn (DeckSlot $slot) => [$slot->kind->value, $slot->position])->all();

        $this->assertSame($before, $after);
    }
}
