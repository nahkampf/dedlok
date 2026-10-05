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

    /** @param  list<array{round: int, text: string, tone: string}>  $log */
    public function __construct(
        public Combatant $a,
        public Combatant $b,
        public int $distance = 2,
        public int $round = 1,
        public array $log = [],
        public Side $first = Side::B,
        public ?Result $result = null,
    ) {
        $this->range = Range::at($this->distance);
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
            $this->first, $result ?? $this->result,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'a' => $this->a->toArray(), 'b' => $this->b->toArray(), 'distance' => $this->distance, 'round' => $this->round,
            'log' => $this->log, 'first' => $this->first->value, 'result' => $this->result?->value,
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
        );
    }
}
