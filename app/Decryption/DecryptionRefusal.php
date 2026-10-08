<?php

declare(strict_types=1);

namespace App\Decryption;

use App\Support\Text;

/**
 * Why nothing was queued, or removed. Every case is an ordinary thing a player can try, so these are values and not
 * exceptions — the shape `QueueRefusal` takes for the training queue.
 */
enum DecryptionRefusal: string
{
    /** Not theirs, not to hand (inside a container, fitted, running), or not a file at all. */
    case NotAFile = 'not_a_file';

    /** Already decrypted: a Memory Dump has nothing left to break. */
    case AlreadyOpen = 'already_open';

    /** Already waiting. */
    case AlreadyQueued = 'already_queued';

    /** The skill that breaks its encryption has not been injected: owning the imprint is a hard gate. */
    case NoSkill = 'no_skill';

    /** The file carries no encryption, so there is nothing a skill could be rolled against. */
    case Unbreakable = 'unbreakable';

    /** `cyberspace.decryption.queue_max` files are already waiting. */
    case QueueFull = 'queue_full';

    /** The file being worked on cannot be called off while jacked in. */
    case LockedIn = 'locked_in';

    /** The order named files that are not the queue, or not all of it. */
    case BadOrder = 'bad_order';

    /** Not in the queue. */
    case NotQueued = 'not_queued';

    public function message(): string
    {
        return Text::get("decryption.refused.{$this->value}");
    }
}
