<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Stats\Damage;

/** One mitigation per side, so the engine can be handed a single one. */
final class FightMitigation implements Mitigation
{
    public function __construct(private readonly Mitigation $a, private readonly Mitigation $b) {}

    public function resolve(Side $side, Damage $damage): int|float
    {
        return ($side === Side::A ? $this->a : $this->b)->resolve($side, $damage);
    }
}
