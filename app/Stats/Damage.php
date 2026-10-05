<?php

declare(strict_types=1);

namespace App\Stats;

use App\Combat\ArmourSlot;
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
        /**
         * The body part a blow landed on, or null for damage that struck nothing in particular
         * (ICE, an overdose). Worn armour counts only when this is a slot it covers.
         */
        public ?ArmourSlot $location = null,
        /**
         * How much of the target's RESISTANCE this hit ignores, as a signed percent: +50 halves what
         * a vest is worth, -50 makes it count half again, +100 ignores it. Zero is an ordinary hit,
         * and what ICE, an overdose and every older source pass. A round carries it.
         */
        public int $penetration = 0,
    ) {
        if ($penetration < -100 || $penetration > 100) {
            throw new \InvalidArgumentException('Penetration is a percent between -100 and 100.');
        }

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
    public static function synapticIntegrity(int $amount, DamageOrigin $origin, ?ArmourSlot $location = null): self
    {
        return new self($amount, 'current_synaptic_integrity', $origin, $location);
    }

    /** Damage to the body. Neural Shock is the first thing that deals any. */
    public static function hitpoints(int $amount, DamageOrigin $origin, ?ArmourSlot $location = null, int $penetration = 0): self
    {
        return new self($amount, 'current_hitpoints', $origin, $location, $penetration);
    }

    /** The stat this hit resolves as. */
    public function statId(): string
    {
        return DamageStats::id($this->resource, $this->origin);
    }
}
