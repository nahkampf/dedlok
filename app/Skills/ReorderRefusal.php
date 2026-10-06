<?php

declare(strict_types=1);

namespace App\Skills;

use App\Support\Text;

/**
 * Why the training queue was not reordered. Ordinary things a player does, so an enum.
 *
 * UNLIKE QUEUEING, A REORDERING IS REFUSED FOR A REQUIREMENT: moving an entry ahead of what it needs
 * (the level before it, or a prerequisite) is something the player did a moment ago and can undo, where
 * a queue that already describes impossible training is a thing they may have been left with.
 */
enum ReorderRefusal: string
{
    /** The list did not name exactly the entries in the queue now: it changed under the player. */
    case QueueChanged = 'queue_changed';

    /** The new order has a problem the current one does not. */
    case BreaksRequirement = 'breaks_requirement';

    /** @param  array<string, string|int>  $with  `problem` for BreaksRequirement */
    public function message(array $with = []): string
    {
        return Text::get("training.reorder_refused.{$this->value}", $with);
    }
}
