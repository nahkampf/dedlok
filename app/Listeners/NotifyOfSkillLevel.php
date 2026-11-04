<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SkillLevelReached;
use App\Notifications\Category;
use App\Notifications\Notifier;

/**
 * A skill crossed a level, and the clock did it rather than the player.
 *
 * THE FIRST LISTENER IN THE GAME. `SkillLevelReached` has been dispatched
 * since the training queue was built and nothing has ever subscribed to it —
 * which is the shape the events were written for: the deposit says what
 * happened and does not know who cares.
 *
 * ONE PER LEVEL, because the event is one per level. A character coming back
 * from three days away gets "reached level 2" and "reached level 3" as two
 * messages, which is what the event's own docblock promises and is right: two
 * levels is twice the news.
 *
 * NOT QUEUED, deliberately. `QUEUE_CONNECTION` is `sync` and this is one
 * insert — putting it on a queue would need a worker to exist before the news
 * arrived at all. When that switch happens (see docs/TODO.md) this is a candidate
 * rather than a requirement.
 */
class NotifyOfSkillLevel
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(SkillLevelReached $event): void
    {
        $this->notifier->send($event->character, Category::Training, 'skill_level', [
            'skill' => $event->skill->title,
            'level' => $event->level,
        ]);
    }
}
