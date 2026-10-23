<?php

namespace Tests\Feature\Admin;

use App\Decks\Hardware;
use App\Decks\SlotKind;
use App\Decks\Variant;
use App\Models\DeckModule;
use App\Models\DeckSlot;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authoring cyberdecks and modules in the item editor.
 *
 * A deck is an item plus a socket each in `deck_slots`, and a module is an
 * item plus a row in `deck_modules`. What is covered here is the part the
 * schema cannot check on its own: that the counts add up across rows, that a
 * module's fields follow from what kind of module it is, and that an item
 * changing what it IS loses the half it stopped being.
 */
class CyberdeckAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * A valid non-hardware item, the same shape the editor posts: every
     * hardware field present and empty, because the form renders them all
     * whatever kind of item is being written.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'reaver',
            'name' => 'Hosaka Reaver',
            'flavor' => null,
            'container' => Item::CONTAINER_PHYSICAL,
            'tier' => 2,
            'rarity' => 'rare',
            'stackable' => '',
            'use_op' => '',
            'use_skill_id' => '',
            'weight' => '2.40',
            'size' => 12,
            'capacity' => 0,
            'accepts' => '',
            'base_cost' => 22_000,
            'on_market' => '1',
            'seed_quantity' => 1,

            'hardware' => '',
            'variant' => '',
            'power_draw' => '',
            'thermal_load' => '',
            'qflops' => '',
            'quantabits' => '',
            'bandwidth' => '',
            'watts' => '',
            'cooling' => '',
            // The counters the form always renders. All zero is "no sockets
            // asked for", not fifteen requests for none.
            'slots' => array_fill_keys([
                'cpu_socket_g', 'cpu_socket_k', 'cpu_socket_z', 'npu',
                'ram_opto', 'ram_qdram', 'ram_universal', 'nic',
                'expansion_xgate', 'expansion_pei', 'expansion_horizon', 'psu',
                'thermal',
            ], 0),
        ], $overrides);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function deck(array $counts, array $overrides = []): array
    {
        $payload = $this->payload($overrides);
        $payload['hardware'] = Hardware::Deck->value;
        $payload['slots'] = array_merge($payload['slots'], $counts);

        return $payload;
    }

    // ---- decks ------------------------------------------------------------

    public function test_an_admin_can_author_a_cyberdeck_and_its_sockets(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck([
                'cpu_socket_k' => 2,
                'ram_qdram' => 4,
                'nic' => 1,
                'expansion_xgate' => 2,
                'psu' => 1,
                'thermal' => 1,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $deck = Item::where('slug', 'reaver')->firstOrFail();

        $this->assertTrue($deck->isCyberdeck());
        $this->assertNull($deck->deckModule);

        $layout = $deck->slotLayout();

        $this->assertSame(11, $layout->total());
        $this->assertSame(2, $layout->ofKind(SlotKind::Cpu));
        $this->assertSame(2, $layout->ofKind(SlotKind::Expansion));
        $this->assertSame(1, $layout->ofKind(SlotKind::Thermal));
        $this->assertSame(0, $layout->ofKind(SlotKind::Npu));

        // Positions are written from one, in canonical order.
        $this->assertSame(range(1, 11), $deck->deckSlots->pluck('position')->all());
    }

    public function test_a_deck_needs_somewhere_to_put_a_cpu_and_somewhere_to_draw_power(): void
    {
        // No CYBERDECK was created — a delta against the baseline, not an
        // absolute zero, because a fresh database carries real hardware
        // content now (the expansion cards), not only placeholder implants.
        $before = Item::query()->whereNotNull('hardware')->count();

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck(['ram_opto' => 2]))
            ->assertSessionHasErrors('slots');

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck(['cpu_socket_g' => 1]))
            ->assertSessionHasErrors('slots');

        $this->assertSame($before, Item::query()->whereNotNull('hardware')->count());
    }

    public function test_a_deck_with_no_sockets_at_all_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck([]))
            ->assertSessionHasErrors('slots');
    }

    /** The cap is on the kind, not on each of its buses. */
    public function test_a_kinds_cap_counts_every_variant_of_it_together(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck([
                'cpu_socket_k' => 1,
                'psu' => 1,
                'expansion_xgate' => 3,
                'expansion_pei' => 3,
            ]))
            ->assertSessionHasErrors('slots');

        // Five of them, spread across three buses, is exactly the cap.
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck([
                'cpu_socket_k' => 1,
                'psu' => 1,
                'expansion_xgate' => 2,
                'expansion_pei' => 2,
                'expansion_horizon' => 1,
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_cyberdeck_cannot_stack(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck(['cpu_socket_k' => 1, 'psu' => 1], ['stackable' => '1']))
            ->assertSessionHasErrors('stackable');
    }

    public function test_deck_hardware_is_carried_never_stored_on_a_deck(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->deck(
                ['cpu_socket_k' => 1, 'psu' => 1],
                ['container' => Item::CONTAINER_DATA],
            ))
            ->assertSessionHasErrors('container');
    }

    public function test_only_a_deck_may_ask_for_sockets(): void
    {
        $payload = $this->payload();
        $payload['slots']['cpu_socket_k'] = 2;

        $this->actingAs($this->admin)
            ->post('/admin/items', $payload)
            ->assertSessionHasErrors('slots');
    }

    public function test_editing_replaces_the_sockets_rather_than_adding_to_them(): void
    {
        $deck = Item::factory()->cyberdeck(['cpu_socket_k' => 2, 'psu' => 1])->create(['slug' => 'reaver']);

        $this->actingAs($this->admin)
            ->put("/admin/items/{$deck->id}", $this->deck(
                ['cpu_socket_z' => 1, 'npu' => 1, 'psu' => 1],
                ['slug' => null],
            ))
            ->assertSessionHasNoErrors();

        $layout = $deck->fresh()->slotLayout();

        $this->assertSame(3, $layout->total());
        $this->assertSame(1, $layout->ofKind(SlotKind::Npu));
        $this->assertSame(1, $layout->ofKind(SlotKind::Cpu));
        $this->assertSame(3, DeckSlot::count());
    }

    // ---- modules ----------------------------------------------------------

    public function test_an_admin_can_author_a_cpu(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'slug' => 'kurogane_k4',
                'hardware' => Hardware::Cpu->value,
                'variant' => Variant::SocketK->value,
                'qflops' => 1_200,
                'power_draw' => 45,
                'thermal_load' => 20,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $spec = Item::where('slug', 'kurogane_k4')->firstOrFail()->deckModule;

        $this->assertSame(Variant::SocketK, $spec->variant);
        $this->assertEquals(1_200, $spec->qflops);
        $this->assertSame(45, $spec->power_draw);
        $this->assertNull($spec->watts);
    }

    public function test_a_module_has_to_say_which_socket_it_fits(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Ram->value,
                'quantabits' => 512,
                'power_draw' => 8,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('variant');
    }

    public function test_a_module_cannot_want_a_socket_of_the_wrong_family(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Ram->value,
                'variant' => Variant::SocketZ->value,
                'quantabits' => 512,
                'power_draw' => 8,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('variant');
    }

    /** `universal` describes a socket that takes either stick, never a stick. */
    public function test_no_stick_of_ram_is_universal(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Ram->value,
                'variant' => Variant::Universal->value,
                'quantabits' => 512,
                'power_draw' => 8,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('variant');
    }

    public function test_a_kind_with_one_flavour_has_no_socket_to_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Npu->value,
                'variant' => Variant::SocketG->value,
                'qflops' => 800,
                'power_draw' => 30,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('variant');
    }

    public function test_a_module_is_measured_by_its_own_unit_and_no_other(): void
    {
        // A CPU carrying a wattage: the schema allows at most one measure, and
        // this is that rule said in words before the database has to say it.
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Cpu->value,
                'variant' => Variant::SocketG->value,
                'qflops' => 900,
                'watts' => 600,
                'power_draw' => 40,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('watts');

        // And one with no compute at all is not a CPU.
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Cpu->value,
                'variant' => Variant::SocketG->value,
                'power_draw' => 40,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('qflops');
    }

    public function test_a_supply_is_rated_rather_than_drawing(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Psu->value,
                'watts' => 600,
                'power_draw' => 5,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasErrors('power_draw');

        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'slug' => 'aegis_600',
                'hardware' => Hardware::Psu->value,
                'watts' => 600,
                'thermal_load' => 12,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Item::where('slug', 'aegis_600')->firstOrFail()->deckModule->power_draw);
    }

    /** What an expansion card does is undecided, so it carries no number to be wrong about. */
    public function test_an_expansion_card_is_a_bus_and_nothing_else_yet(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'slug' => 'horizon_blank',
                'hardware' => Hardware::Expansion->value,
                'variant' => Variant::Horizon->value,
                'power_draw' => 6,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasNoErrors();

        $spec = Item::where('slug', 'horizon_blank')->firstOrFail()->deckModule;

        $this->assertSame(Variant::Horizon, $spec->variant);
        $this->assertSame(6, $spec->power_draw);
        $this->assertNull($spec->qflops);
        $this->assertNull($spec->quantabits);
        $this->assertNull($spec->bandwidth);
        $this->assertNull($spec->watts);
    }

    public function test_a_module_has_to_say_what_it_draws(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/items', $this->payload([
                'hardware' => Hardware::Nic->value,
                'bandwidth' => 4_096,
            ]))
            ->assertSessionHasErrors('power_draw');
    }

    // ---- changing what an item is -----------------------------------------

    public function test_a_module_re_saved_as_another_kind_loses_the_first_kinds_number(): void
    {
        $item = Item::factory()
            ->module(Hardware::Cpu, Variant::SocketK, measure: 1_200, draw: 45)
            ->create(['slug' => 'reaver']);

        $this->actingAs($this->admin)
            ->put("/admin/items/{$item->id}", $this->payload([
                'slug' => null,
                'hardware' => Hardware::Nic->value,
                'bandwidth' => 4_096,
                'power_draw' => 20,
                'thermal_load' => 20,
            ]))
            ->assertSessionHasNoErrors();

        $spec = $item->fresh()->deckModule;

        $this->assertSame(4_096, $spec->bandwidth);
        $this->assertNull($spec->qflops);
        $this->assertNull($spec->variant);
        // Scoped to this item, not the whole table — real hardware content
        // (the expansion cards) lives there now too.
        $this->assertSame(1, DeckModule::query()->where('item_id', $item->id)->count());
    }

    public function test_a_deck_re_saved_as_a_module_loses_its_sockets(): void
    {
        $deck = Item::factory()->cyberdeck(['cpu_socket_k' => 2, 'psu' => 1])->create(['slug' => 'reaver']);

        $this->actingAs($this->admin)
            ->put("/admin/items/{$deck->id}", $this->payload([
                'slug' => null,
                'hardware' => Hardware::Psu->value,
                'watts' => 400,
                'thermal_load' => 12,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DeckSlot::count());
        $this->assertSame(400, $deck->fresh()->deckModule->watts);
    }

    public function test_an_item_that_stops_being_hardware_keeps_neither_half(): void
    {
        $deck = Item::factory()->cyberdeck()->create(['slug' => 'reaver']);

        $this->actingAs($this->admin)
            ->put("/admin/items/{$deck->id}", $this->payload(['slug' => null, 'stackable' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull($deck->fresh()->hardware);
        $this->assertSame(0, DeckSlot::query()->where('item_id', $deck->id)->count());
        // Scoped, not global — the expansion cards keep their own module rows.
        $this->assertSame(0, DeckModule::query()->where('item_id', $deck->id)->count());
    }

    // ---- the screens ------------------------------------------------------

    public function test_the_editor_offers_every_socket_and_the_list_filters_by_kind(): void
    {
        $deck = Item::factory()->cyberdeck(['cpu_socket_z' => 1, 'psu' => 1])->create(['slug' => 'reaver']);
        Item::factory()->module(Hardware::Cpu, Variant::SocketZ, measure: 900)->create(['slug' => 'k9']);
        Item::factory()->create(['slug' => 'scrap_alloy']);

        // The blank form has no item, no layout and no module row behind it,
        // which is the case every accessor in it has to survive.
        $this->actingAs($this->admin)
            ->get('/admin/items/create')
            ->assertOk()
            ->assertSee('slots[cpu_socket_k]', false)
            ->assertSee('624-pin');

        $this->actingAs($this->admin)
            ->get("/admin/items/{$deck->id}/edit")
            ->assertOk()
            ->assertSee('slots[cpu_socket_z]', false)
            ->assertSee('slots[thermal]', false)
            ->assertSee('962-pin');

        $this->actingAs($this->admin)
            ->get('/admin/items?hardware=cpu')
            ->assertOk()
            ->assertSee('k9')
            ->assertDontSee('scrap_alloy');
    }
}
