<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/**
 * Everything about a fight at one moment, as plain data that round-trips through `toArray()` /
 * `fromArray()` — step 4 stores it as JSON on a row and rebuilds it every request.
 *
 * The log mirrors the hunt's: append-only structured entries, each rendered once when it was
 * written (`{round, text, tone}`), never recomposed. `first` is who acts first and never changes;
 * `distance` is how far apart the sides are, a number: a fight opens at 2, only a side's own action moves it
 * (Create distance away, Close in towards), and nought is close combat; `range` is that number read as the
 * weapon it allows. `result` is null until somebody is down or has got away.
 */
final readonly class FightState
{
    public Range $range;

    /** How far YOU stand from the meeting point, on your side of it (see the constructor's `$youAt`). */
    public int $youAt;

    /** @param  list<array{round: int, text: string, tone: string}>  $log */
    public function __construct(
        public Combatant $a,
        public Combatant $b,
        public int $distance = 2,
        public int $round = 1,
        public array $log = [],
        public Side $first = Side::B,
        public ?Result $result = null,
        /** The player's belt item was got out this round (the roll passed). Transient: never stored, read by `Fights` after the round. */
        public bool $used = false,
        /** The enemy stands `distance - youAt` on theirs, so `distance` (the gap) is always the two added. Null splits the gap evenly: a fight stored before positions existed. */
        ?int $youAt = null,
    ) {
        $this->range = Range::at($this->distance);
        $this->youAt = max(0, min($this->distance, $youAt ?? intdiv($this->distance, 2)));
    }

    /** How far this side stands from the meeting point (0 is at it, and a gap of 0 is both there). */
    public function offset(Side $side): int
    {
        return $side === Side::A ? $this->youAt : $this->distance - $this->youAt;
    }

    /** The two sides' positions at once, which fixes the gap. Nobody is placed beyond the fight's reach. */
    public function withOffsets(int $you, int $foe): self
    {
        $reach = self::reach();
        $you = max(0, min($reach, $you));
        $foe = max(0, min($reach, $foe));

        return new self($this->a, $this->b, $you + $foe, $this->round, $this->log, $this->first, $this->result, $this->used, $you);
    }

    /** How far from the meeting point a side can be: half the fight's largest gap. */
    public static function reach(): int
    {
        return intdiv((int) config('combat.distance.max'), 2);
    }

    /** A fight as it opens: the sides at the configured starting distance. */
    public static function opening(Combatant $a, Combatant $b, Side $first = Side::B): self
    {
        return new self($a, $b, (int) config('combat.distance.start'), first: $first);
    }

    public function combatant(Side $side): Combatant
    {
        return $side === Side::A ? $this->a : $this->b;
    }

    public function with(Side $side, Combatant $combatant): self
    {
        return $this->but(a: $side === Side::A ? $combatant : null, b: $side === Side::B ? $combatant : null);
    }

    /** @param  list<array{round: int, text: string, tone: string}>  $lines */
    public function withLog(array $lines): self
    {
        return $this->but(log: [...$this->log, ...$lines]);
    }

    public function withUsed(): self
    {
        return new self($this->a, $this->b, $this->distance, $this->round, $this->log, $this->first, $this->result, true, $this->youAt);
    }

    public function ended(Result $result): self
    {
        return $this->but(result: $result);
    }

    /** The sides at a new distance, kept inside the fight's bounds. */
    public function withDistance(int $distance): self
    {
        return $this->but(distance: max(0, min((int) config('combat.distance.max'), $distance)));
    }

    /** The next round. Nothing closes the distance by itself: only somebody's action moves it. */
    public function nextRound(): self
    {
        return $this->but(round: $this->round + 1);
    }

    private function but(?Combatant $a = null, ?Combatant $b = null, ?int $distance = null, ?int $round = null, ?array $log = null, ?Result $result = null): self
    {
        return new self(
            $a ?? $this->a, $b ?? $this->b, $distance ?? $this->distance, $round ?? $this->round, $log ?? $this->log,
            $this->first, $result ?? $this->result, $this->used, $this->youAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'a' => $this->a->toArray(), 'b' => $this->b->toArray(), 'distance' => $this->distance, 'round' => $this->round,
            'log' => $this->log, 'first' => $this->first->value, 'result' => $this->result?->value, 'you_at' => $this->youAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        // A fight stored before distance was a number says 'ranged' or 'close': at the opening distance, or at nought.
        $distance = isset($data['distance']) ? (int) $data['distance'] : (($data['range'] ?? 'ranged') === 'close' ? 0 : (int) config('combat.distance.start'));

        return new self(
            Combatant::fromArray($data['a']), Combatant::fromArray($data['b']), $distance, (int) $data['round'],
            array_values((array) $data['log']), Side::from((string) $data['first']),
            $data['result'] === null ? null : Result::from((string) $data['result']),
            youAt: isset($data['you_at']) ? (int) $data['you_at'] : null,
        );
    }
}
