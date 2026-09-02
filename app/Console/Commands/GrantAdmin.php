<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only thing that writes users.is_admin.
 *
 * The column is out of User's #[Fillable] on purpose, so there is no HTTP path
 * to it at all — not a hidden field, not a mass-assignment slip, not an admin
 * screen that could be reached by someone who should not have been able to
 * reach it. Promoting an account is a deliberate act at the console, which is
 * also the only way the FIRST admin could ever be made.
 */
class GrantAdmin extends Command
{
    protected $signature = 'admin:grant {email : The account to promote} {--revoke : Take the flag away instead}';

    protected $description = 'Grant or revoke admin rights on an account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $revoking = (bool) $this->option('revoke');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email [{$email}].");

            return self::FAILURE;
        }

        if ($user->isAdmin() === ! $revoking) {
            $this->line($revoking
                ? "[{$email}] is not an admin."
                : "[{$email}] is already an admin.");

            return self::SUCCESS;
        }

        // forceFill because the column is guarded, which is the whole point.
        $user->forceFill(['is_admin' => ! $revoking])->save();

        $this->info($revoking
            ? "[{$email}] is no longer an admin."
            : "[{$email}] is now an admin.");

        return self::SUCCESS;
    }
}
