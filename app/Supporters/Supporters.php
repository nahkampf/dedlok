<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\User;
use App\Notifications\Category;
use App\Notifications\Notifier;
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

    /** @return bool true when the flag changed */
    public function grant(User $user): bool
    {
        if ($user->isSupporter()) {
            return false;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_supporter' => true, 'supporter_since' => now()])->save();

            $this->notifier->send($user, Category::Support, 'supporter_welcome', ['url' => (string) config('strings.discord.url')]);
        });

        return true;
    }

    /** @return bool true when the flag changed */
    public function revoke(User $user): bool
    {
        if (! $user->isSupporter()) {
            return false;
        }

        $user->forceFill(['is_supporter' => false, 'supporter_since' => null])->save();

        return true;
    }
}
