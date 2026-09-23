<?php

declare(strict_types=1);

namespace App\Skills;

/** What one call to TrainingDeposit::advance() did. */
final class DepositResult
{
    public int $pointsAwarded = 0;

    /** @var list<array{0: ?string, 1: int}> */
    public array $levelsReached = [];

    /** @var list<array{0: ?string, 1: int}> */
    public array $entriesCompleted = [];

    /** True when training cannot progress — every governing attribute is zero. */
    public bool $stalled = false;

    /**
     * True when the active entry names a skill that was never injected.
     *
     * Kept apart from $stalled because the fix is different: a stall wants an
     * implant, this wants the imprint spent, and it should never happen at
     * all through the normal path.
     */
    public bool $uninjected = false;

    public function didSomething(): bool
    {
        return $this->pointsAwarded > 0 || $this->entriesCompleted !== [];
    }
}
