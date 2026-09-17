<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * The declaration of a single stat. Immutable, and free of any game state:
 * it says what a stat IS, never what a given character's value is.
 */
final readonly class Stat
{
    /** @param  list<string>  $tags */
    public function __construct(
        public string $id,
        public array $tags = [],
        public ?float $min = null,
        public ?float $max = null,
        public string $rounding = 'none',
    ) {}

    /** @param  array<string, mixed>  $definition */
    public static function fromArray(string $id, array $definition): self
    {
        $unknown = array_diff(array_keys($definition), ['tags', 'min', 'max', 'rounding']);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Stat [{$id}] has unknown keys: ".implode(', ', $unknown)
            );
        }

        $rounding = $definition['rounding'] ?? 'none';

        if (! in_array($rounding, ['round', 'floor', 'ceil', 'tenth', 'none'], true)) {
            throw new \InvalidArgumentException("Stat [{$id}] has unknown rounding [{$rounding}].");
        }

        return new self(
            id: $id,
            tags: array_values($definition['tags'] ?? []),
            min: isset($definition['min']) ? (float) $definition['min'] : null,
            max: isset($definition['max']) ? (float) $definition['max'] : null,
            rounding: $rounding,
        );
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Clamp into range. Happens once, at the end of resolution.
     *
     * Kept separate from round() so the breakdown can report them as two
     * distinct steps — "clamped to 0" and "rounded to 14" are different facts,
     * and conflating them makes a balance report lie.
     */
    public function clamp(float $value): float
    {
        if ($this->min !== null) {
            $value = max($this->min, $value);
        }

        if ($this->max !== null) {
            $value = min($this->max, $value);
        }

        return $value;
    }

    /** Round per this stat's rule. Happens once, after the clamp. */
    public function round(float $value): float
    {
        return match ($this->rounding) {
            'round' => round($value),
            'floor' => floor($value),
            'ceil' => ceil($value),
            // ONE DECIMAL PLACE. For a stat that is genuinely a fraction and
            // whose whole range is small — stealth is single digits, so
            // rounding it to a whole number would throw away most of what
            // training it buys.
            'tenth' => round($value, 1),
            default => $value,
        };
    }
}
