<?php

declare(strict_types=1);

namespace App\Combat\Fight;

/**
 * Everything about a fight at one moment, as plain data that round-trips through `toArray()` /
 * `fromArray()` — step 4 stores it as JSON on a row and rebuilds it every request.
 *
 * The log mirrors the hunt's: append-only structured entries, each rendered once when it was
 * written (`{round, text, tone}`), never recomposed. `first` is who acts first and never changes;
 * `reopen` is a successful Create distance waiting for the round to end; `result` is null until
 * somebody is down or has got away.
 */
final readonly class FightState
{
    /** @param  list<array{round: int, text: string, tone: string}>  $log */
    public function __construct(
        public Combatant $a,
        public Combatant $b,
        public Range $range = Range::Ranged,
        public int $round = 1,
        public array $log = [],
        public Side $first = Side::B,
        public bool $reopen = false,
        public ?Result $result = null,
    ) {}

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

    /** A successful Create distance: the next round opens at Ranged. */
    public function reopening(): self
    {
        return $this->but(reopen: true);
    }

    /**
     * The next round. The range closes after a round at Ranged; a successful Create distance
     * holds it at Ranged for exactly one more, so it goes back to Ranged for the round that follows.
     */
    public function nextRound(): self
    {
        return $this->but(range: $this->reopen ? Range::Ranged : Range::Close, round: $this->round + 1, reopen: false);
    }

    private function but(?Combatant $a = null, ?Combatant $b = null, ?Range $range = null, ?int $round = null, ?array $log = null, ?bool $reopen = null, ?Result $result = null): self
    {
        return new self(
            $a ?? $this->a, $b ?? $this->b, $range ?? $this->range, $round ?? $this->round, $log ?? $this->log,
            $this->first, $reopen ?? $this->reopen, $result ?? $this->result,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'a' => $this->a->toArray(), 'b' => $this->b->toArray(), 'range' => $this->range->value, 'round' => $this->round,
            'log' => $this->log, 'first' => $this->first->value, 'reopen' => $this->reopen, 'result' => $this->result?->value,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Combatant::fromArray($data['a']), Combatant::fromArray($data['b']), Range::from((string) $data['range']), (int) $data['round'],
            array_values((array) $data['log']), Side::from((string) $data['first']), (bool) $data['reopen'],
            $data['result'] === null ? null : Result::from((string) $data['result']),
        );
    }
}
