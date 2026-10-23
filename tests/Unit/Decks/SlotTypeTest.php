<?php

namespace Tests\Unit\Decks;

use App\Decks\Hardware;
use App\Decks\SlotKind;
use App\Decks\SlotType;
use App\Decks\Variant;
use PHPUnit\Framework\TestCase;

/**
 * What fits where, on its own.
 *
 * No framework and no rows: compatibility is decided by a (kind, variant)
 * pair against a (kind, variant) pair, and the editor has to ask the question
 * about a deck that has not been saved yet. Labels are the one thing missing
 * here — they read config, and are covered where the app is booted.
 */
class SlotTypeTest extends TestCase
{
    private function socket(SlotKind $kind, ?Variant $variant = null): SlotType
    {
        return SlotType::of($kind, $variant);
    }

    public function test_a_socket_takes_its_own_kind_and_nothing_else(): void
    {
        $cpu = $this->socket(SlotKind::Cpu, Variant::SocketK);

        $this->assertTrue($cpu->accepts(Hardware::Cpu, Variant::SocketK));
        $this->assertFalse($cpu->accepts(Hardware::Npu, Variant::SocketK));
        $this->assertFalse($cpu->accepts(Hardware::Ram, Variant::SocketK));
    }

    public function test_a_socket_of_the_right_kind_still_has_to_be_the_right_socket(): void
    {
        $socketK = $this->socket(SlotKind::Cpu, Variant::SocketK);

        $this->assertFalse($socketK->accepts(Hardware::Cpu, Variant::SocketG));
        $this->assertFalse($socketK->accepts(Hardware::Cpu, Variant::SocketZ));
        // A CPU with no socket at all fits nothing: the request refuses to
        // save one, and this is the other end of that.
        $this->assertFalse($socketK->accepts(Hardware::Cpu, null));
    }

    /**
     * The asymmetry the whole design is built around: a socket may be
     * universal, a stick of RAM may not.
     */
    public function test_a_universal_ram_socket_takes_either_kind_of_stick(): void
    {
        $universal = $this->socket(SlotKind::Ram, Variant::Universal);

        $this->assertTrue($universal->accepts(Hardware::Ram, Variant::Opto));
        $this->assertTrue($universal->accepts(Hardware::Ram, Variant::QDram));

        $opto = $this->socket(SlotKind::Ram, Variant::Opto);

        $this->assertTrue($opto->accepts(Hardware::Ram, Variant::Opto));
        $this->assertFalse($opto->accepts(Hardware::Ram, Variant::QDram));

        // And it is a socket, never a module: nothing is universal on the
        // other side of the fit.
        $this->assertNotContains(Variant::Universal, Hardware::Ram->variants());
        $this->assertNotContains(Variant::Universal, Variant::forModules());
    }

    public function test_a_socket_with_no_sub_type_takes_anything_of_its_kind(): void
    {
        foreach ([SlotKind::Npu, SlotKind::Nic, SlotKind::Psu] as $kind) {
            $socket = $this->socket($kind);

            $this->assertSame([], $kind->variants());
            $this->assertTrue($socket->accepts($kind->seats(), null));
        }
    }

    public function test_every_expansion_bus_takes_only_its_own_cards(): void
    {
        $xgate = $this->socket(SlotKind::Expansion, Variant::XGate);

        $this->assertTrue($xgate->accepts(Hardware::Expansion, Variant::XGate));
        $this->assertFalse($xgate->accepts(Hardware::Expansion, Variant::Pei));
        $this->assertFalse($xgate->accepts(Hardware::Expansion, Variant::Horizon));
    }

    public function test_keys_are_unique_and_round_trip(): void
    {
        $keys = [];

        foreach (SlotType::all() as $type) {
            $keys[] = $type->key();

            $this->assertTrue($type->isLegal());
            $this->assertTrue($type->is(SlotType::fromKey($type->key())));
        }

        $this->assertSame($keys, array_unique($keys));
        $this->assertNull(SlotType::fromKey('cpu_qdram'));
    }

    public function test_the_sixteen_sockets_are_the_whole_vocabulary(): void
    {
        // Named rather than counted, so adding one is a deliberate edit here
        // as well as in the enum. The thermal exhaust arrived that way, and so
        // did storage.
        $this->assertSame([
            'cpu_socket_g', 'cpu_socket_k', 'cpu_socket_z',
            'npu',
            'ram_opto', 'ram_qdram', 'ram_universal',
            'nic',
            'expansion_xgate', 'expansion_pei', 'expansion_horizon',
            'psu',
            'thermal',
            'storage',
        ], array_map(static fn (SlotType $type): string => $type->key(), SlotType::all()));
    }

    public function test_an_illegal_pair_is_refused_rather_than_invented(): void
    {
        $this->assertFalse($this->socket(SlotKind::Cpu, Variant::QDram)->isLegal());
        $this->assertFalse($this->socket(SlotKind::Cpu)->isLegal());
        $this->assertFalse($this->socket(SlotKind::Nic, Variant::SocketG)->isLegal());

        $this->assertNull(SlotType::fromRow('cpu', 'qdram'));
        $this->assertNull(SlotType::fromRow('gpu', null));
        $this->assertTrue(SlotType::fromRow('ram', 'universal')?->isLegal());
    }

    public function test_a_kind_caps_its_variants_together(): void
    {
        // Five expansion slots means five, not five of each.
        $this->assertSame(5, SlotKind::Expansion->maxPerDeck());
        $this->assertSame(5, $this->socket(SlotKind::Expansion, Variant::Pei)->max());
    }

    public function test_every_module_kind_knows_its_socket_and_its_measure(): void
    {
        $this->assertSame(SlotKind::Cpu, Hardware::Cpu->socket());
        $this->assertSame('qflops', Hardware::Cpu->measure());
        $this->assertSame('qflops', Hardware::Npu->measure());
        $this->assertSame('quantabits', Hardware::Ram->measure());
        $this->assertSame('bandwidth', Hardware::Nic->measure());
        $this->assertSame('watts', Hardware::Psu->measure());
        $this->assertSame('cooling', Hardware::Thermal->measure());

        // Undecided, and deliberately without a number to be wrong about.
        $this->assertNull(Hardware::Expansion->measure());

        // A supply gives power rather than taking it.
        $this->assertFalse(Hardware::Psu->draws());
        $this->assertTrue(Hardware::Cpu->draws());

        // And the mirror: a radiator takes heat rather than making it. It
        // still draws power, because the fan has to run on something.
        $this->assertFalse(Hardware::Thermal->heats());
        $this->assertTrue(Hardware::Thermal->draws());
        $this->assertTrue(Hardware::Cpu->heats());

        $this->assertNull(Hardware::Deck->socket());
        // Storage is quantabits like RAM and a DIFFERENT measure, because
        // DeckTotals sums by measure: one id for both would merge working
        // memory with bulk storage into a figure that means neither.
        $this->assertSame('storage', Hardware::Storage->measure());
        $this->assertSame('quantabits', Hardware::Ram->measure());

        $this->assertSame([Hardware::Cpu, Hardware::Npu, Hardware::Ram, Hardware::Nic,
            Hardware::Expansion, Hardware::Psu, Hardware::Thermal,
            Hardware::Storage], Hardware::modules());
    }
}
