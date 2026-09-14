<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * When a modifier applies.
 *
 *     'condition' => ['when' => 'si_below_pct', 'args' => ['pct' => 30]]
 *
 * The predicate is NAMED, never written inline — see App\Stats\Predicates for
 * why, and for the list. An unknown name throws here, at the moment the
 * modifier is built, rather than the first time somebody happens to be under
 * 30% Synaptic Integrity.
 *
 * MECHANICS.md names the cost this carries, and it is real: a conditional
 * modifier cannot be cached the way an unconditional one can, because it
 * depends on state that moves between reads. That is why Resolver splits the
 * bag rather than evaluating everything every time, and why the rule is
 * "do not make something conditional that could be unconditional".
 */
final readonly class Condition
{
    /** @param  array<string, mixed>  $args */
    private function __construct(
        public string $when,
        public array $args = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), ['when', 'args']);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown condition keys: '.implode(', ', $unknown).'. Known keys are: when, args.'
            );
        }

        if (! isset($data['when']) || ! is_string($data['when'])) {
            throw new \InvalidArgumentException('A condition needs a [when] naming a predicate.');
        }

        if (! Predicates::knows($data['when'])) {
            throw new \InvalidArgumentException(
                "Unknown condition predicate [{$data['when']}]. Known predicates: ".
                implode(', ', Predicates::names()).'.'
            );
        }

        $args = $data['args'] ?? [];

        if (! is_array($args)) {
            throw new \InvalidArgumentException('A condition\'s [args] must be an array.');
        }

        return new self($data['when'], $args);
    }

    public function holds(Context $context): bool
    {
        return Predicates::test($this->when, $context, $this->args);
    }

    /** For the breakdown, so a tooltip can say why a bonus is not showing. */
    public function describe(): string
    {
        if ($this->args === []) {
            return $this->when;
        }

        $parts = [];

        foreach ($this->args as $key => $value) {
            $parts[] = $key.'='.(is_scalar($value) ? (string) $value : gettype($value));
        }

        return $this->when.'('.implode(', ', $parts).')';
    }
}
