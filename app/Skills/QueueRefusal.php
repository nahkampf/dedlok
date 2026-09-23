<?php

declare(strict_types=1);

namespace App\Skills;

use App\Support\Text;

/**
 * Why a skill did not go into the training queue.
 *
 * An enum rather than an exception, for the reason InjectionRefusal is one:
 * every case here is an ordinary thing a player does. Queuing a sixth skill
 * because you forgot how many were already in there is not exceptional.
 *
 * NOTHING ABOUT PREREQUISITES IS IN THIS LIST, deliberately. A queue is
 * allowed to describe training that cannot happen yet — that is what lets
 * Codebreaking sit behind the Hacking that unlocks it — and
 * TrainingQueueValidator is what says so on the screen. A refusal here would
 * make the queue refuse the exact thing it exists to express.
 */
enum QueueRefusal: string
{
    /** The imprint has never been spent, so there is no progress row to train. */
    case NotInjected = 'not_injected';

    /** Every level of it is either reached or already queued. */
    case AlreadyMaxed = 'already_maxed';

    /** Five is the limit — see TrainingQueue::LIMIT. */
    case QueueFull = 'queue_full';

    /** The lang key describing this to a player. */
    public function message(): string
    {
        return Text::get("training.refused.{$this->value}");
    }
}
