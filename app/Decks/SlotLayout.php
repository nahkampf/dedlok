<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * What a cyberdeck presents: its sockets, counted by type.
 *
 * This is the collection the whole feature is about. A deck's specification
 * is not a set of columns — it is "two Socket K, four QDRAM, three XGate, a
 * neuralink port" — and that is a shape neither `items` nor a fixed set of
 * counters can hold honestly, because no two decks present the same set.
 *
 * Pure, and deliberately not a model: the editor needs a layout for a deck
 * that has not been saved yet (the one the form is proposing) exactly as much
 * as it needs one for a deck that has. Rows are one way to build it, a form
 * submission is another, and neither is privileged.
 *
 * `deck_slots` stores one ROW per socket while this counts them, and that is
 * not a redundancy: a count cannot be pointed at, and a module fitted into a
 * deck will point at the socket it went into.
 */
final class SlotLayout
{
    /** @param  array<string, int>  $counts  keyed by SlotType::key(), never zero */
    private function __construct(private readonly array $counts) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * From a form submission: whatever it sent, keyed by field name.
     *
     * Unknown keys and non-positive counts are dropped rather than refused —
     * this builds the layout a payload DESCRIBES, and whether that payload is
     * allowed is App\Http\Requests\Admin\ItemRequest's question. Nothing is
     * clamped here for the same reason: a layout that quietly held four when
     * the form said forty would hide the error the validator is about to
     * report.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromCounts(array $raw): self
    {
        $counts = [];

        foreach (self::orderedKeys() as $key) {
            $count = (int) ($raw[$key] ?? 0);

            if ($count > 0) {
                $counts[$key] = $count;
            }
        }

        return new self($counts);
    }

    /**
     * From stored rows, or anything else that can name its type.
     *
     * @param  iterable<SlotType>  $types
     */
    public static function fromTypes(iterable $types): self
    {
        $counts = [];

        foreach ($types as $type) {
            $key = $type->key();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        // Canonical order, so two layouts holding the same sockets render the
        // same way whatever order the rows came back in.
        return self::fromCounts($counts);
    }

    public function count(SlotType $type): int
    {
        return $this->counts[$type->key()] ?? 0;
    }

    /** How many sockets of a kind, across every variant of it. */
    public function ofKind(SlotKind $kind): int
    {
        $total = 0;

        foreach ($this->present() as [$type, $count]) {
            if ($type->kind === $kind) {
                $total += $count;
            }
        }

        return $total;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    /**
     * The sockets that are actually there, in canonical order.
     *
     * @return list<array{0: SlotType, 1: int}>
     */
    public function present(): array
    {
        $present = [];

        foreach ($this->counts as $key => $count) {
            $type = SlotType::fromKey($key);

            if ($type !== null) {
                $present[] = [$type, $count];
            }
        }

        return $present;
    }

    /** Does this deck have anywhere at all to put such a module? */
    public function accepts(Hardware $hardware, ?Variant $variant): bool
    {
        foreach ($this->present() as [$type, $count]) {
            if ($type->accepts($hardware, $variant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows this layout is stored as, in canonical order.
     *
     * Position is the ordinal across the whole deck rather than per kind, so
     * re-saving an unchanged layout writes the same numbers it read.
     *
     * @return list<array{kind: string, variant: string|null, position: int}>
     */
    public function rows(): array
    {
        $rows = [];
        $position = 1;

        foreach ($this->present() as [$type, $count]) {
            for ($n = 0; $n < $count; $n++) {
                $rows[] = [
                    'kind' => $type->kind->value,
                    'variant' => $type->variant?->value,
                    'position' => $position++,
                ];
            }
        }

        return $rows;
    }

    /** One line: "2× Socket K · 4× QDRAM · NIC · NeuraLink". */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->present() as [$type, $count]) {
            $parts[] = $count === 1
                ? $type->label()
                : Text::get('decks.summary.times', ['count' => $count, 'label' => $type->label()]);
        }

        return implode(Text::get('items.summary.separator'), $parts);
    }

    /** @return list<string> */
    private static function orderedKeys(): array
    {
        return array_map(static fn (SlotType $type): string => $type->key(), SlotType::all());
    }
}
