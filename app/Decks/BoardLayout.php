<?php

declare(strict_types=1);

namespace App\Decks;

/**
 * A deck's sockets, arranged on a board.
 *
 * Pure, and built the same way App\Decks\SlotLayout is: from stored rows or
 * from a form submission, neither privileged. The editor needs a board for a
 * deck that has not been saved yet exactly as much as for one that has.
 *
 * Two inputs and one rule between them:
 *
 * - The SOCKETS decide which nodes exist. A coordinate for a socket the deck
 *   does not have draws nothing.
 * - The stored COORDINATES are an override, never the whole answer. Every node
 *   gets an automatic position first and a stored one on top of it, so a deck
 *   that gains a NIC after it was arranged puts the NIC somewhere sensible
 *   instead of at the origin. Same reason a partial layout is not an error.
 *
 * Percentages, not pixels. The board is responsive and a pixel coordinate is
 * correct at exactly one width.
 */
final class BoardLayout
{
    /** The centre. The CPU sits here and every wire runs to it. */
    private const CENTRE = 50.0;

    /** How far out the automatic ring sits, as a percentage of the board. */
    private const RING_X = 32.0;

    private const RING_Y = 29.0;

    /** Two CPU sockets sit either side of centre rather than on top of it. */
    private const CPU_SPREAD = 18.0;

    /**
     * Nodes never touch the edge: a box is positioned by its centre, so a node
     * at 100 would hang half of itself outside the frame.
     */
    public const MIN = 4.0;

    public const MAX = 96.0;

    /** @param  list<BoardNode>  $nodes */
    private function __construct(private readonly array $nodes) {}

    /**
     * @param  array<string, mixed>|null  $stored  coordinates keyed by SlotType::key()
     */
    public static function for(SlotLayout $sockets, ?array $stored = null): self
    {
        $present = $sockets->present();

        if ($present === []) {
            return new self([]);
        }

        $types = array_map(static fn (array $pair): SlotType => $pair[0], $present);
        $automatic = self::automatic($types);

        $nodes = [];

        foreach ($present as [$type, $count]) {
            $key = $type->key();

            // A stored coordinate that is not a usable pair falls back to the
            // automatic one. Visible and roughly right beats a 500 — the same
            // call Text::get() makes when a key is missing.
            [$x, $y] = self::coordinate($stored[$key] ?? null) ?? $automatic[$key];

            $nodes[] = new BoardNode($type, $count, $x, $y);
        }

        return new self($nodes);
    }

    /** @return list<BoardNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /**
     * The node every wire runs to.
     *
     * The first CPU node in canonical order. ItemRequest already refuses a
     * deck with nowhere to put a CPU, so the fallback is for a board being
     * drawn from a form that has not been validated yet.
     */
    public function hub(): ?BoardNode
    {
        foreach ($this->nodes as $node) {
            if ($node->isCpu()) {
                return $node;
            }
        }

        return $this->nodes[0] ?? null;
    }

    /**
     * Every line to draw: each node paired with the hub.
     *
     * The hub is not wired to itself, and a second CPU node is wired to the
     * first — so the board is always one connected thing rather than two.
     *
     * @return list<array{0: BoardNode, 1: BoardNode}>
     */
    public function wires(): array
    {
        $hub = $this->hub();

        if ($hub === null) {
            return [];
        }

        $wires = [];

        foreach ($this->nodes as $node) {
            if ($node !== $hub) {
                $wires[] = [$node, $hub];
            }
        }

        return $wires;
    }

    /**
     * The coordinates as they are stored, for the editor's hidden field.
     *
     * Every node, including the ones sitting where the ring put them: once a
     * board has been arranged, "wherever the ring would have gone" is not an
     * answer a later socket change should be free to move.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    public function coordinates(): array
    {
        $coordinates = [];

        foreach ($this->nodes as $node) {
            $coordinates[$node->key()] = [$node->x, $node->y];
        }

        return $coordinates;
    }

    /**
     * Keep only what a board could use: legal socket keys and usable pairs.
     *
     * Keys for sockets this deck does NOT have survive, deliberately. Dropping
     * a RAM socket and adding it back should not cost you the place you put
     * it, and the cost of keeping it is a few bytes nothing reads.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, array{0: float, 1: float}>
     */
    public static function clean(array $raw): array
    {
        $clean = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || SlotType::fromKey($key) === null) {
                continue;
            }

            $pair = self::coordinate($value);

            if ($pair !== null) {
                $clean[$key] = $pair;
            }
        }

        return $clean;
    }

    /**
     * One stored pair, clamped into the frame, or null if it is not one.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function coordinate(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== 2) {
            return null;
        }

        $x = $value[0] ?? null;
        $y = $value[1] ?? null;

        if (! is_numeric($x) || ! is_numeric($y)) {
            return null;
        }

        return [self::clamp((float) $x), self::clamp((float) $y)];
    }

    private static function clamp(float $value): float
    {
        return round(max(self::MIN, min(self::MAX, $value)), 1);
    }

    /**
     * Somewhere sensible for every node, before anyone arranges anything.
     *
     * The CPU takes the centre and everything else takes a ring around it,
     * starting at the top and going clockwise in the canonical socket order.
     * It is not the organic arrangement an author would draw — it is the one
     * that is never wrong, never overlapping for an ordinary deck, and always
     * connected.
     *
     * @param  list<SlotType>  $types
     * @return array<string, array{0: float, 1: float}>
     */
    private static function automatic(array $types): array
    {
        $cpu = array_values(array_filter($types, static fn (SlotType $t): bool => $t->kind === SlotKind::Cpu));
        $rest = array_values(array_filter($types, static fn (SlotType $t): bool => $t->kind !== SlotKind::Cpu));

        $coordinates = [];

        $cpuCount = count($cpu);

        foreach ($cpu as $index => $type) {
            $offset = $cpuCount === 1
                ? 0.0
                : ($index - ($cpuCount - 1) / 2) * self::CPU_SPREAD;

            $coordinates[$type->key()] = [self::clamp(self::CENTRE + $offset), self::CENTRE];
        }

        $restCount = max(1, count($rest));

        foreach ($rest as $index => $type) {
            // From the top, clockwise. cos/sin the usual way round with y
            // growing downwards, which is what a screen does.
            $angle = -M_PI / 2 + ($index * 2 * M_PI / $restCount);

            $coordinates[$type->key()] = [
                self::clamp(self::CENTRE + self::RING_X * cos($angle)),
                self::clamp(self::CENTRE + self::RING_Y * sin($angle)),
            ];
        }

        return $coordinates;
    }
}
