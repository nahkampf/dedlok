<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * THE ONLY WRITER of `users.is_supporter`, as `admin:grant` is of `is_admin`: the columns are outside User's #[Fillable], so no request
 * can set them however it is shaped.
 *
 * A supporter gets a badge by the character's name, an unlimited training queue (`TrainingQueue::limitFor()`), a place on the
 * Supporters page, and — once, when the flag goes from off to on — a message in the inbox saying thank you and how to join the Discord.
 * Revoking takes the flag and the privileges and sends nothing; granting again sends the message again.
 */
final class Supporters
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function make(): self
    {
        return new self(new Notifier);
    }

    /**
     * @param  ?CarbonInterface  $until  when it lapses; NULL is for good. A grant to somebody who already is one moves the end date
     *                                   (a for-good supporter stays one: a membership payment never shortens it).
     * @return bool true when the flag changed
     */
    public function grant(User $user, ?CarbonInterface $until = null): bool
    {
        if ($user->isSupporter()) {
            if ($user->supporter_until !== null) {
                $user->forceFill(['supporter_until' => $until])->save();
            }

            return false;
        }

        DB::transaction(function () use ($user, $until): void {
            $user->forceFill(['is_supporter' => true, 'supporter_since' => now(), 'supporter_until' => $until])->save();

            $this->notifier->send($user, Category::Support, 'supporter_welcome', ['url' => (string) config('strings.discord.url')]);
        });

        return true;
    }

    /** @return bool true when the flag changed */
    public function revoke(User $user): bool
    {
        if (! $user->is_supporter) {
            return false;
        }

        $user->forceFill(['is_supporter' => false, 'supporter_since' => null, 'supporter_until' => null])->save();

        return true;
    }

    /** Takes the flag from everybody whose time has run out. Reads already say they are not supporters; this keeps the row true. */
    public function expire(): int
    {
        return User::where('is_supporter', true)->where('supporter_until', '<=', now())
            ->update(['is_supporter' => false, 'supporter_since' => null, 'supporter_until' => null]);
    }
}
