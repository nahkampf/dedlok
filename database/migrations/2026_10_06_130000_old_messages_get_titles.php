<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Messages sent before `notifications.title` existed have none, and the inbox falls back to showing their
 * CATEGORY as the title, so several different messages all read "Cyberspace". This gives them the title they
 * would have had, read back off the sentence that was stored (a stored sentence is never recomposed, so it is
 * the one reliable record of what the message was).
 *
 * Only rows with NO title are touched, and only when a sentence is recognised; anything else keeps the category.
 * The siphon report's title changed from "Siphons ran" to "Siphon report" the day after it was written, so
 * rows already carrying the old one are renamed.
 *
 * The patterns are copied here rather than read from `config/strings.php`: a migration is history, and the
 * strings may change without it.
 */
return new class extends Migration
{
    /** @var array<string, string> sentence pattern => title, with $1 and $2 for what it captured */
    private const RULES = [
        '/^Your scan of .+ completed\./' => 'Scan complete',
        '/^Your backdoor into .+ was detected\./' => 'Backdoor found',
        '/^Siphons ran:/' => 'Siphon report',
        '/^Your backdoor into .+ is gone\./' => 'Backdoor lost',
        '/ is stale\. Nobody will pay for a scan of /' => 'Scan file gone stale',
        '/^Sold: .+? × (.+), for .+ ¤\.$/u' => 'Sold: $1',
        '/^Your .+ job for .+ ran out of time\./' => 'Job ran out of time',
        '/^You walked away from the .+ job for /' => 'Job abandoned',
        '/^The .+ job for .+ is off:/' => 'Job called off',
        '/^(.+) reached level (\d+)\.$/' => '$1, level $2',
        '/^Achievement unlocked: (.+)\.$/' => 'Achievement: $1',
        '/^You need it\./' => 'Withdrawal',
        '/^You arrived in (.+)\.$/' => 'Arrived in $1',
        '/^You stopped in (.+)\.$/' => 'Stopped in $1',
        '/^Something happened in (.+)\. It is waiting for you\.$/' => 'Something happened in $1',
        '/^You found a place: (.+)\.$/' => 'Found: $1',
        '/^The surgery went well:/' => 'Surgery went well',
        '/ is out and back in your stash\.$/' => 'Implant removed',
        '/^Your body rejected /' => 'Surgery failed',
        '/^(.+) sent you .+ ¤\.$/u' => 'Cash from $1',
        "/^You've made enemies out there\\./" => 'A price on your head',
    ];

    public function up(): void
    {
        DB::table('notifications')->where('title', 'Siphons ran')->update(['title' => 'Siphon report']);

        DB::table('notifications')->whereNull('title')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                foreach (self::RULES as $pattern => $title) {
                    if (preg_match($pattern, (string) $row->body, $captured)) {
                        // Only what was captured goes into the title, never the rest of the sentence.
                        $title = str_replace(['$1', '$2'], [$captured[1] ?? '', $captured[2] ?? ''], $title);

                        DB::table('notifications')->where('id', $row->id)->update(['title' => mb_substr($title, 0, 120)]);

                        break;
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo that is worth undoing: a title is information the message always had.
    }
};
