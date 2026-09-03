<?php

namespace Tests\Feature;

use App\Decks\BoardLayout;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\User;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A deck's sockets, arranged on a board.
 *
 * The rule the whole thing rests on: a node is a TYPE, not a socket. Four
 * QDRAM sockets are one box with one wire, which is why the coordinate is not
 * on `deck_slots` — four rows carrying the same coordinate are four chances
 * for it to disagree with itself.
 */
class DeckBoardTest extends TestCase
{
    use RefreshDatabase;

    private function sockets(array $counts): SlotLayout
    {
        return SlotLayout::fromCounts($counts);
    }

    // ---- the arrangement ---------------------------------------------------

    public function test_one_node_per_socket_type_however_many_sockets(): void
    {
        $board = BoardLayout::for($this->sockets([
            'cpu_socket_k' => 2,
            'ram_qdram' => 4,
            'psu' => 1,
        ]));

        $this->assertCount(3, $board->nodes());

        $ram = $board->nodes()[1];
        $this->assertSame('ram_qdram', $ram->key());
        $this->assertSame(4, $ram->count);
        $this->assertTrue($ram->isGrouped());

        // One socket is drawn as the socket; the box is for a bank of them.
        $this->assertFalse($board->nodes()[2]->isGrouped());
    }

    public function test_the_cpu_is_the_hub_and_every_wire_runs_to_it(): void
    {
        $board = BoardLayout::for($this->sockets([
            'ram_opto' => 1,
            'cpu_socket_z' => 1,
            'psu' => 1,
            'io_neuralink' => 1,
        ]));

        $hub = $board->hub();
        $this->assertNotNull($hub);
        $this->assertSame(SlotKind::Cpu, $hub->type->kind);

        // Everything but the hub, wired to it, and the hub never to itself.
        $wires = $board->wires();
        $this->assertCount(count($board->nodes()) - 1, $wires);

        foreach ($wires as [$from, $to]) {
            $this->assertSame($hub->key(), $to->key());
            $this->assertNotSame($hub->key(), $from->key());
        }
    }

    public function test_a_second_cpu_is_wired_to_the_first_so_the_board_is_one_thing(): void
    {
        $board = BoardLayout::for($this->sockets([
            'cpu_socket_g' => 1,
            'cpu_socket_k' => 1,
            'psu' => 1,
        ]));

        $this->assertCount(2, $board->wires());

        // Two CPU nodes sit either side of centre rather than on top of it.
        $this->assertNotEquals($board->nodes()[0]->x, $board->nodes()[1]->x);
    }

    public function test_an_unarranged_deck_still_draws(): void
    {
        // NULL is not missing data. It means nobody has arranged this deck,
        // and the ring is what that looks like.
        $board = BoardLayout::for($this->sockets(['cpu_socket_k' => 1, 'ram_opto' => 1, 'psu' => 1]), null);

        $this->assertFalse($board->isEmpty());

        foreach ($board->nodes() as $node) {
            $this->assertGreaterThanOrEqual(BoardLayout::MIN, $node->x);
            $this->assertLessThanOrEqual(BoardLayout::MAX, $node->x);
        }
    }

    public function test_stored_coordinates_override_the_ring(): void
    {
        $board = BoardLayout::for(
            $this->sockets(['cpu_socket_k' => 1, 'psu' => 1]),
            ['psu' => [12.5, 88.0]],
        );

        $psu = array_values(array_filter($board->nodes(), fn ($n) => $n->key() === 'psu'))[0];

        $this->assertSame(12.5, $psu->x);
        $this->assertSame(88.0, $psu->y);
    }

    public function test_a_socket_with_no_stored_place_still_gets_one(): void
    {
        // A deck arranged before it grew a NIC puts the NIC somewhere
        // sensible rather than at the origin. The ring is the base, and
        // stored coordinates are an override on top of it.
        $board = BoardLayout::for(
            $this->sockets(['cpu_socket_k' => 1, 'psu' => 1, 'nic' => 1]),
            ['psu' => [12.5, 88.0]],
        );

        $nic = array_values(array_filter($board->nodes(), fn ($n) => $n->key() === 'nic'))[0];

        $this->assertGreaterThanOrEqual(BoardLayout::MIN, $nic->x);
        $this->assertNotSame(0.0, $nic->x);
    }

    public function test_a_coordinate_for_a_socket_the_deck_lacks_draws_nothing(): void
    {
        $board = BoardLayout::for(
            $this->sockets(['cpu_socket_k' => 1, 'psu' => 1]),
            ['ram_qdram' => [10.0, 10.0]],
        );

        $this->assertSame(['cpu_socket_k', 'psu'], array_map(fn ($n) => $n->key(), $board->nodes()));
    }

    public function test_a_corrupt_coordinate_falls_back_rather_than_throwing(): void
    {
        // Visible and roughly right beats a 500 — the call Text::get() makes
        // when a key is missing.
        $board = BoardLayout::for(
            $this->sockets(['cpu_socket_k' => 1, 'psu' => 1]),
            ['psu' => 'over there', 'cpu_socket_k' => [1]],
        );

        $this->assertCount(2, $board->nodes());

        foreach ($board->nodes() as $node) {
            $this->assertGreaterThanOrEqual(BoardLayout::MIN, $node->x);
        }
    }

    public function test_cleaning_clamps_and_drops_what_is_not_a_socket(): void
    {
        $clean = BoardLayout::clean([
            'psu' => [-40, 500],
            'not_a_socket' => [10, 10],
            'nic' => 'nope',
            'ram_qdram' => [33.33, 66.66],
        ]);

        $this->assertSame([
            'psu' => [BoardLayout::MIN, BoardLayout::MAX],
            'ram_qdram' => [33.3, 66.7],
        ], $clean);
    }

    public function test_a_bank_is_a_rectangle_not_a_ribbon(): void
    {
        $columns = fn (int $count) => BoardLayout::for($this->sockets(['ram_universal' => $count]))
            ->nodes()[0]->columns();

        $this->assertSame(1, $columns(1));
        $this->assertSame(4, $columns(4));
        // Six is three and three, not five and a straggler.
        $this->assertSame(3, $columns(6));
        $this->assertSame(4, $columns(8));
    }

    public function test_a_non_deck_has_an_empty_board_rather_than_none(): void
    {
        $this->assertTrue(Item::factory()->physical()->create()->board()->isEmpty());
    }

    // ---- the screen --------------------------------------------------------

    private function character(): Character
    {
        return Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
    }

    private function deck(array $counts = ['cpu_socket_k' => 1, 'ram_opto' => 2, 'psu' => 1]): Item
    {
        return Item::factory()->cyberdeck($counts)->create([
            'slug' => 'kurogane_wayfarer', 'name' => 'Kurogane Wayfarer',
        ]);
    }

    public function test_the_owner_sees_a_node_for_every_socket_type(): void
    {
        $character = $this->character();
        $deckItem = $this->deck();
        $entry = InventoryItem::factory()->ownedBy($character)->of($deckItem)->create();

        $response = $this->actingAs($character->user)->get(route('deck.show', $entry));

        $response->assertOk();
        $response->assertSee('class="board__wires"', false);

        foreach (['cpu_socket_k', 'ram_opto', 'psu'] as $key) {
            $response->assertSee('data-node="'.$key.'"', false);
        }

        // Two sockets of a type are one box with one wire, not two of each.
        $this->assertSame(1, substr_count($response->getContent(), 'data-from="ram_opto"'));

        // Every socket is a way in: an empty one opens the list of what fits,
        // a filled one offers to take it back out.
        foreach ($deckItem->deckSlots as $socket) {
            $response->assertSee('data-open="#socket-'.$socket->id.'"', false);
        }
    }

    public function test_the_stash_offers_the_board_from_the_deck_it_owns(): void
    {
        $character = $this->character();
        $entry = InventoryItem::factory()->ownedBy($character)->of($this->deck())->create();

        $this->actingAs($character->user)
            ->get(route('inventory.index'))
            ->assertSee(route('deck.show', $entry), false);
    }

    public function test_someone_elses_deck_is_not_found_rather_than_refused(): void
    {
        $mine = $this->character();
        $theirs = InventoryItem::factory()->ownedBy($this->character())->of($this->deck())->create();

        $this->actingAs($mine->user)->get(route('deck.show', $theirs))->assertNotFound();
    }

    public function test_a_row_that_is_not_a_deck_has_no_board(): void
    {
        $character = $this->character();
        $rifle = InventoryItem::factory()->ownedBy($character)->of(Item::factory()->physical()->create())->create();

        $this->actingAs($character->user)->get(route('deck.show', $rifle))->assertNotFound();
    }

    // ---- authoring ---------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function deckPayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'kurogane_wayfarer',
            'name' => 'Kurogane Wayfarer',
            'flavor' => '',
            'container' => Item::CONTAINER_PHYSICAL,
            'tier' => 2,
            'rarity' => 'rare',
            'use_op' => '',
            'use_skill_id' => '',
            'weight' => '2.00',
            'size' => 0,
            'capacity' => 0,
            'accepts' => '',
            'base_cost' => 9_000,
            'on_market' => '1',
            'seed_quantity' => 1,
            'hardware' => 'deck',
            'slots' => ['cpu_socket_k' => 1, 'ram_opto' => 2, 'psu' => 1],
        ], $overrides);
    }

    public function test_an_arrangement_is_saved_and_read_back(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->deckPayload([
                'board_layout' => json_encode(['cpu_socket_k' => [50, 50], 'ram_opto' => [20.5, 80.25]]),
            ]))
            ->assertRedirect();

        $item = Item::where('slug', 'kurogane_wayfarer')->firstOrFail();

        // Clamped and rounded on the way in, so what is stored is what draws.
        // assertEquals, not assertSame: JSON has one number type, so a whole
        // 50.0 comes back an int and a fractional 80.3 comes back a float.
        // Which is why nothing reads the column directly — BoardLayout casts
        // both to float, and that is the type the board is drawn from.
        $this->assertEquals([
            'cpu_socket_k' => [50, 50],
            'ram_opto' => [20.5, 80.3],
        ], $item->board_layout);

        $ram = array_values(array_filter($item->board()->nodes(), fn ($n) => $n->key() === 'ram_opto'))[0];
        $this->assertSame(20.5, $ram->x);
        $this->assertSame(80.3, $ram->y);

        $cpu = array_values(array_filter($item->board()->nodes(), fn ($n) => $n->key() === 'cpu_socket_k'))[0];
        $this->assertSame(50.0, $cpu->x);
    }

    public function test_blank_is_not_arranged_and_that_is_a_real_state(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->deckPayload(['board_layout' => '']))
            ->assertRedirect();

        $this->assertNull(Item::where('slug', 'kurogane_wayfarer')->firstOrFail()->board_layout);
    }

    public function test_only_a_cyberdeck_has_a_board(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->deckPayload([
                'hardware' => '',
                'slots' => [],
                'board_layout' => json_encode(['psu' => [10, 10]]),
            ]))
            ->assertSessionHasErrors('board_layout');
    }

    public function test_an_unreadable_arrangement_is_refused_rather_than_dropped(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->deckPayload(['board_layout' => 'not json at all']))
            ->assertSessionHasErrors('board_layout');
    }

    public function test_a_key_that_is_not_a_socket_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->deckPayload([
                'board_layout' => json_encode(['trapdoor' => [10, 10]]),
            ]))
            ->assertSessionHasErrors('board_layout');
    }

    public function test_a_coordinate_off_the_board_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.items.store'), $this->deckPayload([
                'board_layout' => json_encode(['psu' => [10, 400]]),
            ]))
            ->assertSessionHasErrors('board_layout.psu.1');
    }

    public function test_an_item_that_stops_being_a_deck_loses_its_board(): void
    {
        $deck = $this->deck();
        $deck->update(['board_layout' => ['cpu_socket_k' => [50.0, 50.0]]]);

        $this->actingAs($this->admin())
            ->put(route('admin.items.update', $deck), $this->deckPayload([
                'slug' => null,
                'hardware' => '',
                'slots' => [],
                'board_layout' => '',
            ]))
            ->assertRedirect();

        // Becoming nothing is a change too, and it is the one that has to take
        // the old arrangement with it — the same rule HardwareEditor follows
        // for the sockets themselves.
        $this->assertNull($deck->fresh()->board_layout);
    }
}
