<?php

declare(strict_types=1);

namespace App\Cyberspace;

/**
 * What one look around revealed.
 *
 * Every figure here is about the LOOK rather than about the world: how much
 * ground was new to this character, what was standing in it, and what they had
 * remembered that is no longer there. The last of those is the interesting one
 * — it is the world having moved on since they were last here, and it is the
 * only place a player finds that out.
 */
final readonly class SweepResult
{
    public function __construct(
        public Position $from,
        public int $positions,
        public int $newlySwept,
        public int $systemsSeen,
        public int $contactsMade,
        public int $contactsLost,
    ) {}

    /** Was any of this ground new? */
    public function foundGround(): bool
    {
        return $this->newlySwept > 0;
    }

    /** Did anything remembered here turn out to be gone? */
    public function foundChange(): bool
    {
        return $this->contactsLost > 0;
    }
}
