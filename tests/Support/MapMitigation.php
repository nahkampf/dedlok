<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Combat\Fight\Mitigation;
use App\Combat\Fight\Side;
use App\Stats\Damage;

/** Resistance as a plain array: damage type => percent off, per side. Records what it was asked. */
final class MapMitigation implements Mitigation
{
    /** @var list<array{side: Side, damage: Damage}> */
    public array $seen = [];

    /** @param  array<string, array<string, int>>  $resist  side value => [type => percent] */
    public function __construct(private readonly array $resist = []) {}

    public function resolve(Side $side, Damage $damage): int
    {
        $this->seen[] = ['side' => $side, 'damage' => $damage];
        $percent = $this->resist[$side->value][$damage->origin->value] ?? 0;
        $percent *= 1 - $damage->penetration / 100;

        return max(0, (int) round($damage->amount * (1 - $percent / 100)));
    }
}
