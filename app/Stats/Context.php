<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * The state a conditional modifier is judged against.
 *
 * Deliberately a bag of plain values rather than a character: the Resolver and
 * every predicate stay free of Eloquent, so "what does this modifier do when
 * the runner is under 30% SI" is a question a unit test can ask with one line
 * and no fixture.
 *
 * Building one from a character is somebody else's job — see
 * App\Stats\Sheet::contextFor(). This class only holds the answers.
 */
final readonly class Context
{
    /** @param  array<string, mixed>  $values */
    public function __construct(public array $values = []) {}

    /** @param  array<string, mixed>  $values */
    public static function of(array $values): self
    {
        return new self($values);
    }

    public static function empty(): self
    {
        return new self;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function number(string $key): ?float
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    /** @param  array<string, mixed>  $values */
    public function with(array $values): self
    {
        return new self([...$this->values, ...$values]);
    }
}
