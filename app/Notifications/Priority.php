<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * How much a message matters to the person reading it, which is the one thing `Category` refuses to say.
 *
 * TWO BOXES, not a scale. Standard is the mass mail (a siphon report, a scan file gone stale, a skill level);
 * Important is what a player should not miss. Anything a player wrote to another is Important, and so is anything
 * hand-written for a story. Only the rail's badge colour and the new-message sound read it: ember and a chime for
 * Important, grey and silence for Standard.
 *
 * WHICH KEY IS WHICH lives in `config/notifications.php`, the one place to move a message between the boxes.
 */
enum Priority: string
{
    case Standard = 'standard';
    case Important = 'important';

    /** A system message's box, by its strings key. A key nobody has flagged is Standard. */
    public static function forKey(string $key): self
    {
        return in_array($key, (array) config('notifications.important'), true) ? self::Important : self::Standard;
    }
}
