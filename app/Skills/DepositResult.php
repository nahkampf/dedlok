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

    public function didSomething(): bool
    {
        return $this->pointsAwarded > 0 || $this->entriesCompleted !== [];
    }
}
