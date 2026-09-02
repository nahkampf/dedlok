<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A queue entry reached its target level and left the queue. */
class TrainingEntryCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Character $character,
        public readonly Skill $skill,
        public readonly int $targetLevel,
        public readonly \DateTimeInterface $completedAt,
        /** Whether anything is left to train. */
        public readonly bool $queueEmpty,
    ) {}
}
