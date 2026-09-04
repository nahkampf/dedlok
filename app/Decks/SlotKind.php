<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * A kind of socket on a cyberdeck.
 *
 * Six of the seven seat a module. `Io` does not: an electrode harness and a
 * neuralink port are where the deck reaches the person using it, and nothing
 * is fitted into them. They are here rather than as two booleans on `items`
 * because "what interfaces does this deck present" is one question, and
 * answering half of it with rows and half with flags would mean every screen
 * that lists a deck's hardware reads two shapes and joins them by hand.
 */
enum SlotKind: string
{
    case Cpu = 'cpu';

    case Npu = 'npu';

    case Ram = 'ram';

    case Nic = 'nic';

    case Expansion = 'expansion';

    case Psu = 'psu';

    /** Thermal exhaust. Seats a radiator; a deck may present none at all. */
    case Thermal = 'thermal';

    /**
     * Where data and unloaded programs live.
     *
     * Not memory. Memory is what a program occupies while it RUNS; storage is
     * what a file costs by existing, and the two are separate measures for the
     * reason a deck can be full without being busy. See CYBERSPACE.md.
     */
    case Storage = 'storage';

    /** Electrode and neuralink ports. Seats nothing. */
    case Io = 'io';

    /**
     * The sub-types this kind of socket comes in, in the order a form should
     * offer them. Empty means the kind has exactly one flavour and its slots
     * carry no variant at all — which the schema enforces both ways.
     *
     * @return list<Variant>
     */
    public function variants(): array
    {
        return match ($this) {
            self::Cpu => [Variant::SocketG, Variant::SocketK, Variant::SocketZ],
            self::Ram => [Variant::Opto, Variant::QDram, Variant::Universal],
            self::Expansion => [Variant::XGate, Variant::Pei, Variant::Horizon],
            self::Io => [Variant::Electrode, Variant::Neuralink],
            default => [],
        };
    }

    /** The kind of module this socket seats, or null for an IO port. */
    public function seats(): ?Hardware
    {
        return match ($this) {
            self::Cpu => Hardware::Cpu,
            self::Npu => Hardware::Npu,
            self::Ram => Hardware::Ram,
            self::Nic => Hardware::Nic,
            self::Expansion => Hardware::Expansion,
            self::Psu => Hardware::Psu,
            self::Thermal => Hardware::Thermal,
            self::Storage => Hardware::Storage,
            self::Io => null,
        };
    }

    /**
     * How many of this kind one deck may present, counted across every
     * variant of it — five expansion slots means five, not five of each.
     *
     * Shape rather than balance: these stop a typo in a number field from
     * writing four hundred rows, and they are not the numbers a designer
     * tunes. The only one that comes from the design is Expansion's five.
     */
    public function maxPerDeck(): int
    {
        return match ($this) {
            self::Cpu => 4,
            self::Npu => 4,
            self::Ram => 8,
            self::Nic => 4,
            self::Expansion => 5,
            self::Psu => 2,
            // A deck may present none, one, or several. Nothing requires an
            // exhaust — a cold deck does not need one.
            self::Thermal => 4,
            // Same shape cap as memory: a deck may present several, and a
            // number field typo must not write four hundred rows.
            self::Storage => 8,
            // One electrode port and one neuralink port. The per-variant cap
            // in App\Decks\SlotType is what makes that one of each.
            self::Io => 2,
        };
    }

    public function label(): string
    {
        return Text::get("decks.slots.{$this->value}");
    }
}
