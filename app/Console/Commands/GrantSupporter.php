<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Supporters\Supporters;
use Illuminate\Console\Command;

/** Grant or revoke the Supporter flag on an account. The admin screen does the same through the same class. */
class GrantSupporter extends Command
{
    protected $signature = 'supporter:grant {email : The account} {--revoke : Take the flag away instead}';

    protected $description = 'Grant or revoke the Supporter flag on an account';

    public function handle(Supporters $supporters): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email [{$email}].");

            return self::FAILURE;
        }

        $revoking = (bool) $this->option('revoke');
        $changed = $revoking ? $supporters->revoke($user) : $supporters->grant($user);

        $this->info($changed
            ? ($revoking ? "[{$email}] is no longer a supporter." : "[{$email}] is now a supporter.")
            : ($revoking ? "[{$email}] is not a supporter." : "[{$email}] is already a supporter."));

        return self::SUCCESS;
    }
}
