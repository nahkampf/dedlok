<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * One change to one or more stats, from one source.
 *
 * Everything that alters a number becomes one of these before it reaches the
 * Resolver: skills, implants, drugs, injuries, archetype perks, gear, weather.
 * That is the whole extensibility story — a new KIND of source never touches
 * the arithmetic.
 */
final readonly class Modifier
{
    public function __construct(
        public Op $op,
        public float $amount,
        public Target $target,
        /** Human-readable origin, shown in the breakdown. */
        public ?string $source = null,
        /** Only meaningful for Op::Override. */
        public int $priority = 0,
    ) {}

    /**
     * Build from config data.
     *
     * Unknown keys are rejected rather than ignored. Conditional modifiers are
     * specified in MECHANICS.md but not implemented yet, so a 'condition' key
     * written today fails loudly here instead of being silently dropped and
     * quietly unbalancing the game.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $known = ['op', 'amount', 'target', 'source', 'priority'];
        $unknown = array_diff(array_keys($data), $known);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown modifier keys: '.implode(', ', $unknown).
                '. Known keys are: '.implode(', ', $known).'.'
            );
        }

        foreach (['op', 'amount', 'target'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \InvalidArgumentException("Modifier is missing [{$required}].");
            }
        }

        $op = Op::tryFrom((string) $data['op']);

        if ($op === null) {
            throw new \InvalidArgumentException(
                "Unknown modifier op [{$data['op']}]. Known ops: ".
                implode(', ', array_column(Op::cases(), 'value')).'.'
            );
        }

        return new self(
            op: $op,
            amount: (float) $data['amount'],
            target: Target::fromArray($data['target']),
            source: $data['source'] ?? null,
            priority: (int) ($data['priority'] ?? 0),
        );
    }

    public function appliesTo(Stat $stat): bool
    {
        return $this->target->matches($stat);
    }
}
