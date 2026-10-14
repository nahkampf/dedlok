<?php

declare(strict_types=1);

namespace App\Quests;

use App\Characters\Flags;
use App\Journal\Journal;
use App\Models\Character;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * The timed half of quests: what happens to somebody a while AFTER they did something, which no button press raises.
 *
 * Run every thirty minutes (`quests:run`, `cadence.quest_runner`). Each task is one method that looks for characters who have
 * reached a point and not yet been dealt with, and records that it has dealt with them as a flag, so a task never
 * repeats and a missed run is simply caught up by the next. Writers stay the owners they were: messages go through
 * `Notifier`, flags through `Flags`.
 */
final class QuestRunner
{
    /** Set when somebody has read the Njord files... */
    public const NJORD_READ = 'njord_jsc_files_read';

    /** ...and this when the legal department has written to them, so it happens once. */
    public const NJORD_NOTICE_SENT = 'njord_jsc_notice_sent';

    public function __construct(private readonly Notifier $notifier, private readonly Journal $journal) {}

    /** Run every task. @return array<string, int> task => how many characters it acted on */
    public function run(): array
    {
        return ['njord_jsc_notice' => $this->njordNotice()];
    }

    /** Everybody who has the read flag and has not been written to gets the legal department's notice, once. */
    private function njordNotice(): int
    {
        $sent = 0;

        Character::query()
            ->whereIn('id', DB::table('character_flags')->where('flag', self::NJORD_READ)->select('character_id'))
            ->each(function (Character $character) use (&$sent): void {
                DB::transaction(function () use ($character, &$sent): void {
                    // The flag is the once: only the call that set it sends.
                    if (Flags::set($character, self::NJORD_NOTICE_SENT)) {
                        $this->notifier->send($character, Category::City, 'njord_jsc_notice');
                        // Under the ghost note when they have it; made, with its own heading, when they do not.
                        $this->journal->write($character, 'ghost_process', Text::get('journal.njord.note'), 'njord_jsc_notice', Text::get('journal.njord.notice'));
                        $sent++;
                    }
                });
            });

        return $sent;
    }
}
