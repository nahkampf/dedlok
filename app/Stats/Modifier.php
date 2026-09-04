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
        /**
         * When this applies. Null is the ordinary case and the one to prefer:
         * an unconditional modifier can be resolved once and cached, and a
         * conditional one cannot. See App\Stats\Condition.
         */
        public ?Condition $condition = null,
    ) {}

    /**
     * Build from config data.
     *
     * Unknown keys are rejected rather than ignored, and so is an unknown
     * predicate inside a condition: a modifier that quietly does nothing is a
     * balance bug with no symptom.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $known = ['op', 'amount', 'target', 'source', 'priority', 'condition'];
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
            condition: isset($data['condition']) ? Condition::fromArray((array) $data['condition']) : null,
        );
    }

    public function appliesTo(Stat $stat): bool
    {
        return $this->target->matches($stat);
    }

    /**
     * Does this modifier depend on state that moves between reads?
     *
     * The split the whole caching story rests on: unconditional modifiers
     * resolve once and can be held, conditional ones have to be judged at the
     * moment of the check.
     */
    public function isConditional(): bool
    {
        return $this->condition !== null;
    }

    /**
     * Is this modifier live, given what is true right now?
     *
     * A conditional modifier with NO context is not live. That is the
     * deliberate direction: resolving without a context is asking for the
     * context-free answer, and quietly folding in a bonus that is only
     * sometimes earned would make that answer wrong in a way nothing reports.
     */
    public function isLiveIn(?Context $context): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return $context !== null && $this->condition->holds($context);
    }
}
