<?php

declare(strict_types=1);

namespace App\Decks;

use App\Support\Text;

/**
 * One (kind, variant) pair: a Socket K, a QDRAM slot.
 *
 * The fourteen of these are the whole vocabulary a deck is specified in, and
 * this class is the single place that vocabulary is written down. The editor
 * renders one field per type, the request validates against the same list,
 * and App\Decks\SlotLayout counts by it — so a fifteenth socket is one case
 * in an enum and a label, and nothing else changes.
 *
 * It is a pair rather than a fourteen-case enum because the two halves are
 * asked about separately: a cap applies to a kind across its variants, and
 * compatibility is decided by the variant.
 */
final class SlotType
{
    private function __construct(
        public readonly SlotKind $kind,
        public readonly ?Variant $variant,
    ) {}

    /**
     * Every socket a deck can present, in the order the editor lists them.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $types = [];

        foreach (SlotKind::cases() as $kind) {
            $variants = $kind->variants();

            if ($variants === []) {
                $types[] = new self($kind, null);

                continue;
            }

            foreach ($variants as $variant) {
                $types[] = new self($kind, $variant);
            }
        }

        return $types;
    }

    public static function of(SlotKind $kind, ?Variant $variant): self
    {
        return new self($kind, $variant);
    }

    /** The type a stored row describes, or null if the row is not a legal pair. */
    public static function fromRow(string $kind, ?string $variant): ?self
    {
        $kind = SlotKind::tryFrom($kind);
        $variant = $variant === null ? null : Variant::tryFrom($variant);

        if ($kind === null) {
            return null;
        }

        $type = new self($kind, $variant);

        return $type->isLegal() ? $type : null;
    }

    /** The type a form field names, or null if the key is not one of ours. */
    public static function fromKey(string $key): ?self
    {
        foreach (self::all() as $type) {
            if ($type->key() === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The form field name and the array key everything is keyed by.
     *
     * Underscores rather than dots: a dot in a field name is a nested array
     * to the validator, and `slots.*` has to stay one level deep.
     */
    public function key(): string
    {
        return $this->variant === null
            ? $this->kind->value
            : "{$this->kind->value}_{$this->variant->value}";
    }

    public function is(self $other): bool
    {
        return $this->kind === $other->kind && $this->variant === $other->variant;
    }

    /** Is this pair one the schema would accept? */
    public function isLegal(): bool
    {
        $variants = $this->kind->variants();

        return $this->variant === null
            ? $variants === []
            : in_array($this->variant, $variants, true);
    }

    /**
     * How many of THIS type one deck may present.
     *
     * A kind's cap applies across its variants, so this is the same number
     * for every variant of a kind.
     */
    public function max(): int
    {
        return $this->kind->maxPerDeck();
    }

    /** Does a module of this kind and variant go in this socket? */
    public function accepts(Hardware $hardware, ?Variant $variant): bool
    {
        if ($this->kind->seats() !== $hardware) {
            return false;
        }

        // A socket with no variant seats anything of its kind; NPUs, NICs and
        // PSUs have no sub-type to disagree about.
        if ($this->variant === null) {
            return true;
        }

        return $this->variant->accepts($variant);
    }

    /** The short name: the variant where there is one, the kind where there is not. */
    public function label(): string
    {
        return $this->variant?->label() ?? $this->kind->label();
    }

    /**
     * The half-sentence the editor shows under the label — a pin count, what
     * a set of initials stands for. A socket without one shows none.
     */
    public function note(): ?string
    {
        $key = 'decks.notes.'.($this->variant?->value ?? $this->kind->value);

        return Text::has($key) ? Text::get($key) : null;
    }

    /** The name with its kind in front, for a form where both matter. */
    /**
     * The label a board node wears, with its kind in front of it.
     *
     * A schematic shows variants — "Socket G", "Opto-electrical", "XGate" —
     * and a variant name does not say what KIND of thing it is. On a spec line
     * that is fine, because the line is about one socket; on a board it is six
     * unrelated words and no way to tell the memory from the expansion bus at a
     * glance. The prefix is the kind, short enough not to become the label.
     *
     * A socket with NO variant already reads as its kind — "NIC", "PSU", "NPU"
     * — so it takes no prefix. "NPU: NPU" is not a clarification.
     */
    public function boardLabel(): string
    {
        if ($this->variant === null) {
            return $this->kind->label();
        }

        $prefix = Text::get("decks.prefixes.{$this->kind->value}");

        return $prefix === "decks.prefixes.{$this->kind->value}"
            ? $this->variant->label()
            : "{$prefix}: {$this->variant->label()}";
    }

    public function fullLabel(): string
    {
        return $this->variant === null
            ? $this->kind->label()
            : "{$this->kind->label()} — {$this->variant->label()}";
    }
}
