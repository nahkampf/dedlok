<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Models\Character;
use App\Stats\Context;
use App\Stats\Damage;
use App\Stats\DamageResolver;
use App\Stats\Modifier;
use App\Stats\Sheet;

/**
 * A character's resistance, for the engine: `DamageResolver`'s own arithmetic, asked rather than
 * applied. The modifiers and the context are fetched ONCE, because a fight is fought with a snapshot
 * — what changes mid-fight is ignored by design — and a simulation asks thousands of times. It never
 * writes: hit points move in the fight's state and step 4 applies the outcome.
 */
final class CharacterMitigation implements Mitigation
{
    /** @var list<Modifier> */
    private readonly array $modifiers;

    private readonly Context $context;

    private readonly DamageResolver $resolver;

    public function __construct(Character $character)
    {
        $this->modifiers = $character->modifiers();
        $this->context = Sheet::contextFor($character);
        $this->resolver = DamageResolver::make();
    }

    public function resolve(Side $side, Damage $damage): int
    {
        return $this->resolver->against($this->modifiers, $this->context, $damage)->toInt();
    }
}
