<?php

declare(strict_types=1);

namespace App\Combat;

/**
 * Where a fight started from an encounter goes back to when it ends.
 *
 * The encounter row it came from, and the three destination KEYS of the choice that opened it — keys
 * and never a choice id, because the editor recreates every choice on each save. Snapshotted onto the
 * fight, which acts on them only if that row is still the character's live encounter.
 */
final readonly class FightReturn
{
    public function __construct(
        public int $encounterRowId,
        public ?string $win,
        public ?string $lose,
        public ?string $flee,
    ) {}
}
