<?php

declare(strict_types=1);

namespace App\Achievements;

use App\Models\Character;
use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
use App\Support\Text;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `achievement_progress` and `user_achievements`.
 *
 * ## Call sites report, they do not decide
 *
 * `record()` and `reach()` say what happened — a hundred creds came out of a
 * system, a chain of ten was built — and nothing more. Which totals are worth
 * an achievement lives in `config/achievements.php`, so the thing that knows
 * about raiding never learns that raiding is worth anything, and a second
 * achievement over the same metric is a config row rather than another call.
 *
 * ## Two ways a total moves
 *
 * `record()` ADDS, for events: creds, burns, ICE. `reach()` takes the HIGHER
 * of what is stored and what is reported, for states: the longest chain ever
 * held is not the sum of every chain ever built. Both run the same tail, so an
 * unlock is decided in one place either way.
 *
 * ## The account, not the character
 *
 * Every caller holds a Character, because everything that happens in this game
 * happens to one. The row is written against `characters.user_id`, so a
 * character coming to a bad end never takes the record with it.
 *
 * ## Unlocking is idempotent by index
 *
 * `insertOrIgnore` against the unique (user, achievement) pair, so crossing a
 * threshold again is a no-op and nothing has to read first to find that out.
 * Nothing is dispatched on an unlock — there is no screen and no notification
 * to dispatch it to yet, and the row is the record.
 */
final class Achievements
{
    private const PROGRESS = 'achievement_progress';

    private const UNLOCKED = 'user_achievements';

    /** An event happened, $amount of it. Adds to the running total. */
    public function record(Character|User $who, Metric $metric, int $amount = 1): void
    {
        $this->move($who, $metric, $amount, highWater: false);
    }

    /**
     * A state was observed at $value. Keeps the highest ever seen.
     *
     * Reporting a lower value than is stored is ordinary and does nothing,
     * which is what lets a screen that recomputes the same state on every
     * render call this without thinking about it.
     */
    public function reach(Character|User $who, Metric $metric, int $value): void
    {
        $this->move($who, $metric, $value, highWater: true);
    }

    /**
     * What this account has unlocked, as ids, oldest first.
     *
     * @return list<int>
     */
    public function unlockedFor(User $user): array
    {
        return DB::table(self::UNLOCKED)
            ->where('user_id', $user->getKey())
            ->orderBy('unlocked_at')
            ->pluck('achievement_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * What an achievements page may show this account.
     *
     * AN OPEN ACHIEVEMENT IS LISTED WHETHER OR NOT IT IS WON — that is the
     * point of it, something to go and do. A SECRET ONE IS NOT THERE AT ALL
     * until it is unlocked, and then it is an ordinary entry. The rule lives
     * here rather than on the page so that a second surface — a profile, an
     * API, whatever comes next — cannot answer it differently.
     *
     * Ordered as `config/achievements.php` lists them, which is the only
     * ordering that exists until a screen wants another.
     *
     * @return array<int, array{metric: string, threshold: int, secret: bool, unlocked: bool}>
     */
    public function listableFor(User $user): array
    {
        $unlocked = $this->unlockedFor($user);
        $listing = [];

        foreach ($this->definitions() as $id => $definition) {
            $won = in_array($id, $unlocked, true);

            if ($definition['secret'] && ! $won) {
                continue;
            }

            $listing[$id] = [...$definition, 'unlocked' => $won];
        }

        return $listing;
    }

    /** The running total, where absent and zero are the same answer. */
    public function progressFor(Character|User $who, Metric $metric): int
    {
        $userId = $this->accountOf($who);

        return $userId === null ? 0 : (int) $this->row($userId, $metric)->value('value');
    }

    private function move(Character|User $who, Metric $metric, int $amount, bool $highWater): void
    {
        $userId = $this->accountOf($who);

        // Nothing to count, and a character with no account is a test fixture
        // rather than a state the game can reach.
        if ($userId === null || $amount <= 0) {
            return;
        }

        DB::table(self::PROGRESS)->insertOrIgnore([
            'user_id' => $userId,
            'metric' => $metric->value,
            'value' => 0,
        ]);

        if ($highWater) {
            $this->row($userId, $metric)->where('value', '<', $amount)->update(['value' => $amount]);
        } else {
            $this->row($userId, $metric)->increment('value', $amount);
        }

        $this->check($userId, $metric, (int) $this->row($userId, $metric)->value('value'));
    }

    /**
     * Hand out everything this metric now qualifies for.
     *
     * Every definition is checked rather than only the one that just came due:
     * a threshold added to a metric that passed it long ago is won on the next
     * movement, which is the cheap way to make a new achievement retroactive.
     */
    private function check(int $userId, Metric $metric, int $value): void
    {
        foreach ($this->definitions() as $id => $unlock) {
            if ($unlock['metric'] !== $metric->value || $value < $unlock['threshold']) {
                continue;
            }

            $unlocked = DB::table(self::UNLOCKED)->insertOrIgnore([
                'user_id' => $userId,
                'achievement_id' => $id,
                'unlocked_at' => now(),
            ]);

            /*
             * SAID OUT LOUD ONCE, AND THE INDEX IS WHAT MAKES THAT TRUE.
             * `insertOrIgnore` returns the number of rows it actually wrote,
             * so crossing the same threshold a second time is a zero here and
             * nothing is sent — the unique (user, achievement) pair is doing
             * the deduplicating that a "have they been told" column otherwise
             * would.
             *
             * An unlock is the side effect of doing something else, which is
             * exactly what the inbox is for: nobody presses a button to win
             * one and no screen is about to carry the news.
             */
            if ($unlocked > 0 && ($user = User::find($userId)) !== null) {
                (new Notifier)->send($user, Category::Achievement, 'achievement_unlocked', [
                    'name' => Text::get('achievements.name', ['id' => $id]),
                ]);
            }
        }
    }

    /**
     * Every definition, with its optional half filled in.
     *
     * `secret` is absent on most rows and false when it is, so normalising it
     * once here is what keeps both readers from having to know that.
     *
     * @return array<int, array{metric: string, threshold: int, secret: bool}>
     */
    private function definitions(): array
    {
        /** @var array<int|string, array{metric?: string, threshold?: int, secret?: bool}> $unlocks */
        $unlocks = Config::get('achievements.unlocks', []);

        $definitions = [];

        foreach ($unlocks as $id => $unlock) {
            $definitions[(int) $id] = [
                'metric' => (string) ($unlock['metric'] ?? ''),
                'threshold' => (int) ($unlock['threshold'] ?? 0),
                'secret' => (bool) ($unlock['secret'] ?? false),
            ];
        }

        return $definitions;
    }

    private function row(int $userId, Metric $metric): Builder
    {
        return DB::table(self::PROGRESS)->where('user_id', $userId)->where('metric', $metric->value);
    }

    private function accountOf(Character|User $who): ?int
    {
        $id = $who instanceof User ? $who->getKey() : $who->user_id;

        return $id === null ? null : (int) $id;
    }
}
