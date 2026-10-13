<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Stats\Damage;
use App\Support\Points;

/**
 * An NPC's resistance: a map of damage type to percent off, optionally overridden by body part
 * ("a helmet, no vest"). The same arithmetic as the resolver's, written out because an NPC has no
 * modifier sources to ask: a percentage per type and per part is SUMMED, then applied once.
 *
 * Positive is a resistance and negative a vulnerability. **Penetration scales a resistance and
 * nothing else**: a vulnerability is not softened, and 100 or more is an IMMUNITY, which no round
 * pierces — the resolver's own rules for `multiplier` 0.
 */
final class NpcMitigation implements Mitigation
{
    /**
     * @param  array<string, int|float>  $resist  damage type => percent off
     * @param  array<string, array<string, int|float>>  $bySlot  body part => [damage type => extra percent off]
     */
    public function __construct(private readonly array $resist = [], private readonly array $bySlot = []) {}

    public function resolve(Side $side, Damage $damage): int|float
    {
        $type = $damage->origin->value;
        $percent = (float) ($this->resist[$type] ?? 0) + (float) ($damage->location !== null ? ($this->bySlot[$damage->location->value][$type] ?? 0) : 0);

        if ($percent >= 100) {
            return 0;
        }

        if ($percent > 0) {
            $percent *= 1 - $damage->penetration / 100;
        }

        return Points::of(max(0, $damage->amount * (1 - $percent / 100)));
    }
}
