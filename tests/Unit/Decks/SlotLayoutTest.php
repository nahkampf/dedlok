<?php

namespace Tests\Unit\Decks;

use App\Decks\Hardware;
use App\Decks\SlotKind;
use App\Decks\SlotLayout;
use App\Decks\SlotType;
use App\Decks\Variant;
use PHPUnit\Framework\TestCase;

/**
 * The collection a cyberdeck's specification actually is.
 *
 * Pure, and that is the point: the editor builds one out of a form submission
 * to ask what a deck WOULD be before saving it, and out of rows to show what
 * it is. Neither way of building it is privileged, so neither may need the
 * database.
 */
class SlotLayoutTest extends TestCase
{
    private function type(SlotKind $kind, ?Variant $variant = null): SlotType
    {
        return SlotType::of($kind, $variant);
    }

    public function test_it_counts_what_a_form_asked_for(): void
    {
        $layout = SlotLayout::fromCounts([
            'cpu_socket_k' => 2,
            'ram_qdram' => 4,
            'psu' => 1,
        ]);

        $this->assertSame(2, $layout->count($this->type(SlotKind::Cpu, Variant::SocketK)));
        $this->assertSame(0, $layout->count($this->type(SlotKind::Cpu, Variant::SocketG)));
        $this->assertSame(7, $layout->total());
        $this->assertFalse($layout->isEmpty());
    }

    public function test_zeroes_and_nonsense_are_dropped_rather_than_stored(): void
    {
        $layout = SlotLayout::fromCounts([
            'cpu_socket_k' => 1,
            'ram_opto' => 0,
            'nic' => '',
            'gpu_socket_q' => 3,
        ]);

        $this->assertSame(1, $layout->total());
        $this->assertSame(0, $layout->count($this->type(SlotKind::Nic)));
    }

    /** Nothing is clamped here: a forty is the validator's to refuse, not this class's to hide. */
    public function test_it_does_not_clamp_what_it_is_given(): void
    {
        $layout = SlotLayout::fromCounts(['expansion_pei' => 40]);

        $this->assertSame(40, $layout->ofKind(SlotKind::Expansion));
    }

    public function test_a_kinds_count_spans_its_variants(): void
    {
        $layout = SlotLayout::fromCounts([
            'expansion_xgate' => 2,
            'expansion_pei' => 2,
            'expansion_horizon' => 1,
            'ram_opto' => 1,
        ]);

        // Five expansion slots, which is the cap — not five of each.
        $this->assertSame(5, $layout->ofKind(SlotKind::Expansion));
        $this->assertSame(1, $layout->ofKind(SlotKind::Ram));
        $this->assertSame(0, $layout->ofKind(SlotKind::Npu));
    }

    public function test_rows_are_one_per_socket_and_numbered_from_one(): void
    {
        $rows = SlotLayout::fromCounts(['cpu_socket_z' => 2, 'psu' => 1])->rows();

        $this->assertSame([
            ['kind' => 'cpu', 'variant' => 'socket_z', 'position' => 1],
            ['kind' => 'cpu', 'variant' => 'socket_z', 'position' => 2],
            ['kind' => 'psu', 'variant' => null, 'position' => 3],
        ], $rows);
    }

    public function test_rows_and_types_are_the_same_layout_read_twice(): void
    {
        $original = SlotLayout::fromCounts(['io_neuralink' => 1, 'cpu_socket_g' => 1, 'ram_universal' => 2]);

        // What the editor stores, read back the way the model reads it.
        $stored = SlotLayout::fromTypes(array_map(
            static fn (array $row): SlotType => SlotType::fromRow($row['kind'], $row['variant']),
            $original->rows(),
        ));

        $this->assertSame($original->rows(), $stored->rows());
    }

    /** Canonical order, whatever order the rows or the form arrived in. */
    public function test_two_layouts_holding_the_same_sockets_read_the_same(): void
    {
        $one = SlotLayout::fromCounts(['psu' => 1, 'cpu_socket_k' => 1]);
        $two = SlotLayout::fromCounts(['cpu_socket_k' => 1, 'psu' => 1]);

        $this->assertSame($one->rows(), $two->rows());
    }

    public function test_a_layout_knows_whether_a_module_has_anywhere_to_go(): void
    {
        $layout = SlotLayout::fromCounts(['cpu_socket_k' => 1, 'ram_universal' => 2, 'psu' => 1]);

        $this->assertTrue($layout->accepts(Hardware::Cpu, Variant::SocketK));
        $this->assertFalse($layout->accepts(Hardware::Cpu, Variant::SocketZ));

        // The universal socket takes either stick.
        $this->assertTrue($layout->accepts(Hardware::Ram, Variant::Opto));
        $this->assertTrue($layout->accepts(Hardware::Ram, Variant::QDram));

        // No NPU socket on this deck, so no AI program that needs one.
        $this->assertFalse($layout->accepts(Hardware::Npu, null));
        $this->assertTrue($layout->accepts(Hardware::Psu, null));
    }

    public function test_an_empty_layout_answers_rather_than_breaks(): void
    {
        $empty = SlotLayout::empty();

        $this->assertTrue($empty->isEmpty());
        $this->assertSame(0, $empty->total());
        $this->assertSame([], $empty->rows());
        $this->assertSame([], $empty->present());
        $this->assertFalse($empty->accepts(Hardware::Cpu, Variant::SocketG));
    }
}
