<?php

declare(strict_types=1);

namespace App\Stats;

use App\Models\Character;

/**
 * A hit: how much, to what, from what.
 *
 * The three facts a damage event needs before anything can have an opinion
 * about it. Amount is the RAW number — whatever rolled it has already rolled
 * it, and a burnout taking "25-75% of current SI" resolves that percentage
 * before it gets here. What happens after this is mitigation, and mitigation
 * is the stat system's job rather than the caller's.
 *
 * Immutable and free of the character it is about, so the same hit can be
 * previewed against one and applied to another — which is what a future
 * area-of-effect anything will want, and what makes this testable without a
 * database.
 */
final readonly class Damage
{
    public function __construct(
        public int $amount,
        public string $resource,
        public DamageOrigin $origin,
    ) {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Damage is never negative. Healing is its own act.');
        }

        if (! array_key_exists($resource, Character::RESOURCES)) {
            throw new \InvalidArgumentException(
                "[{$resource}] is not a resource. Known: ".implode(', ', array_keys(Character::RESOURCES)).'.'
            );
        }
    }

    /** Damage to the mind: ICE, a deck failing underneath you, a bad jack-out. */
    public static function synapticIntegrity(int $amount, DamageOrigin $origin): self
    {
        return new self($amount, 'current_synaptic_integrity', $origin);
    }

    /** Damage to the body. Nothing deals any yet. */
    public static function hitpoints(int $amount, DamageOrigin $origin): self
    {
        return new self($amount, 'current_hitpoints', $origin);
    }

    /** The stat this hit resolves as. */
    public function statId(): string
    {
        return DamageStats::id($this->resource, $this->origin);
    }
}
