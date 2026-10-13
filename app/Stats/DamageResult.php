<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * What a hit turned into. Three numbers, because they answer three questions.
 *
 *   requested  what arrived — what the cause rolled or decided
 *   resolved   what it became once the character's modifiers had their say
 *   taken      what was actually removed, which is less when there was less
 *              left than the hit was for
 *
 * A caller that wants to SAY something uses `taken`; a caller deciding whether
 * anything happened at all must not, because a hit fully mitigated to nothing
 * is still a hit that landed. The consequence is never paid for with the
 * damage — a readiness loss ejects a player whose resistances took it to zero.
 *
 * `breakdown` is the resolver's own step list, so a combat log can say WHY
 * eight became five without a second explanation path existing to disagree
 * with the character sheet.
 */
final readonly class DamageResult
{
    public function __construct(
        public Damage $damage,
        public int|float $requested,
        public int|float $resolved,
        public int|float $taken,
        public ResolvedStat $breakdown,
    ) {}

    /** Did anything the character carries change the number? */
    public function wasModified(): bool
    {
        return $this->resolved !== $this->requested;
    }
}
