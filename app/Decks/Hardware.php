<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * What a deck-hardware item IS: the deck itself, or a module of one kind.
 *
 * Stored on `items.hardware`, and NULL there for everything that is not deck
 * hardware — which is most of the catalogue. It is the only place the kind is
 * written: `deck_modules` carries the numbers and not the word, so a row can
 * never disagree with its item about being a CPU.
 */
enum Hardware: string
{
    case Deck = 'deck';

    case Cpu = 'cpu';

    case Npu = 'npu';

    case Ram = 'ram';

    case Nic = 'nic';

    case Expansion = 'expansion';

    case Psu = 'psu';

    /** Takes heat away. The only kind whose measure is a subtraction. */
    case Thermal = 'thermal';

    /** Bulk storage: what a file costs by existing, not by running. */
    case Storage = 'storage';

    /** @return list<self> */
    public static function modules(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $hardware): bool => $hardware !== self::Deck,
        ));
    }

    public function isDeck(): bool
    {
        return $this === self::Deck;
    }

    public function isModule(): bool
    {
        return $this !== self::Deck;
    }

    /** The kind of socket this module goes in. Null for the deck itself. */
    public function socket(): ?SlotKind
    {
        return match ($this) {
            self::Deck => null,
            self::Cpu => SlotKind::Cpu,
            self::Npu => SlotKind::Npu,
            self::Ram => SlotKind::Ram,
            self::Nic => SlotKind::Nic,
            self::Expansion => SlotKind::Expansion,
            self::Psu => SlotKind::Psu,
            self::Thermal => SlotKind::Thermal,
            self::Storage => SlotKind::Storage,
        };
    }

    /**
     * The sub-types a module of this kind comes in. Empty means it has none
     * and its `variant` column stays null.
     *
     * Narrower than the socket it fits, by one value: a RAM socket may be
     * `universal`, a stick of RAM may not.
     *
     * @return list<Variant>
     */
    public function variants(): array
    {
        return match ($this) {
            self::Cpu => [Variant::SocketG, Variant::SocketK, Variant::SocketZ],
            self::Ram => [Variant::Opto, Variant::QDram],
            self::Expansion => [Variant::XGate, Variant::Pei, Variant::Horizon],
            default => [],
        };
    }

    /**
     * The one column on `deck_modules` this kind fills, or null for a kind
     * that fills none.
     *
     * An expansion card is the null: what an expansion card does is not
     * decided, and inventing a number for it now would be a number to migrate
     * away from once it is. The CHECK constraint allows at most one measure
     * for exactly this reason.
     */
    public function measure(): ?string
    {
        return match ($this) {
            self::Cpu, self::Npu => 'qflops',
            self::Ram => 'quantabits',
            self::Nic => 'bandwidth',
            self::Psu => 'watts',
            self::Thermal => 'cooling',
            // Quantabits like RAM, and NOT the same measure. A stick of RAM
            // and a storage module are not interchangeable, and DeckTotals
            // sums by measure — sharing `quantabits` would silently merge
            // working memory with bulk storage into one meaningless figure.
            self::Storage => 'storage',
            self::Deck, self::Expansion => null,
        };
    }

    /**
     * Does a module of this kind draw power?
     *
     * Everything but the supply does — a radiator has a fan, and the fan
     * runs on the deck's power like anything else. A PSU's number is what it
     * gives, and the schema refuses a row that claims both.
     */
    public function draws(): bool
    {
        return $this !== self::Deck && $this !== self::Psu;
    }

    /**
     * Does a module of this kind MAKE heat?
     *
     * Everything but the thermal module, which takes it away. The exact mirror
     * of draws() above, and the schema's chk_deck_modules_thermal is what
     * makes it true of every row rather than most of them.
     */
    public function heats(): bool
    {
        return $this !== self::Deck && $this !== self::Thermal;
    }

    public function label(): string
    {
        return Text::get("decks.hardware.{$this->value}");
    }
}
