<?php

declare(strict_types=1);

namespace App\Combat\Fight;

use App\Stats\Damage;

/**
 * What a hit is worth once the target has had its say. The engine's only window onto resistances.
 *
 * It RESOLVES and never applies: a player's is `DamageResolver::preview()` on a character, an
 * NPC's is its resistance map, a test's is a plain array. Nothing about it lives in the fight's
 * state, so the state stays plain data that can be stored and rebuilt, and the engine can be run
 * a thousand times by `fight:simulate` without a database.
 */
interface Mitigation
{
    /** The number of points (to two decimals) `$damage` costs `$side` after resistance, never negative. */
    public function resolve(Side $side, Damage $damage): int|float;
}
